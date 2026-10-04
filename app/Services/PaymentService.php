<?php
declare(strict_types=1);

namespace TripleR\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use TripleR\Config;
use TripleR\Repositories\PaymentProofRepository;
use TripleR\Repositories\PaymentRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\RulesAcceptanceRepository;
use TripleR\Repositories\SecurityLogRepository;
use TripleR\Services\Payments\PaymentGateway;
use TripleR\Support\PaymentMethods;
use TripleR\Support\Money;

/**
 * Paying online, and the balance.
 *
 * Online: the customer starts a payment for the downpayment from their booking page, is sent
 * to the gateway's checkout, and the gateway reports the result in a signed message. A paid
 * result marks the downpayment received in the same transaction; front desk then confirms the
 * reservation, exactly as for a payment recorded at the counter. The amount always comes from
 * the booking, never from the browser.
 *
 * Balance: what is left after the downpayment is received at the counter and recorded here.
 * (Recording a downpayment at the counter is RentalService::recordDownpayment.)
 */
final class PaymentService
{
    private const STARTS_PER_HOUR = 10;

    public function __construct(
        private readonly PDO $db,
        private readonly PaymentRepository $payments,
        private readonly RentalRepository $agreements,
        private readonly RentalService $rentals,
        private readonly PaymentProofRepository $proofs,
        private readonly RulesAcceptanceRepository $rules,
        private readonly RateLimiter $rateLimiter,
        private readonly SecurityLogRepository $securityLogs,
        private readonly ?PaymentGateway $gateway,
    ) {
    }

    /** False when no gateway is configured: the customer then pays by proof upload or at the counter. */
    public function onlineAvailable(): bool
    {
        return $this->gateway !== null;
    }

    /** True when the online checkout is the simulated one, so pages can say that no money is taken. */
    public function onlineIsDemonstration(): bool
    {
        return $this->gateway?->channel() === 'online_demo';
    }

    /** Where the customer continues a payment in progress, or null when paying online is off. */
    public function checkoutUrl(array $payment): ?string
    {
        return $this->gateway?->checkoutUrl($payment);
    }

    /**
     * The policy the customer still has to accept before paying online, or null when their
     * acceptance is already on record (they booked online) or there is no policy to accept.
     */
    public function policyToAccept(int $agreementId): ?array
    {
        return $this->rules->acceptanceForAgreement($agreementId) === null ? $this->rules->currentVersion(OnlineBookingService::POLICY_KEY) : null;
    }

    /**
     * Starts an online payment of the downpayment and returns the checkout address. A payment
     * already in progress is continued, not duplicated.
     */
    public function startOnline(int $agreementId, string $method, bool $acceptsPolicy, string $ip, string $userAgent): string
    {
        if ($this->gateway === null) {
            throw new RuntimeException('Paying online is not available right now. Send a proof of payment, or pay at the rental office.');
        }
        if (!isset(PaymentMethods::online()[$method])) {
            throw new RuntimeException('Choose how you want to pay.');
        }
        $this->payments->expireStale($agreementId);
        $inProgress = $this->payments->pendingFor($agreementId);
        if ($inProgress !== null) {
            return $this->gateway->checkoutUrl($inProgress);
        }
        $agreement = $this->agreements->find($agreementId);
        $this->assertWaitingForDownpayment($agreement);
        if (!$this->rateLimiter->allow('payment-start', (string) $agreementId, self::STARTS_PER_HOUR, 3600)) {
            throw new RuntimeException('Too many payments were started for this booking. Please wait an hour, or call the rental office.');
        }
        $policy = $this->policyToAccept($agreementId);
        $phone = null;
        if ($policy !== null) {
            if (!$acceptsPolicy) {
                throw new RuntimeException('To pay, you need to accept the downpayment policy.');
            }
            $phone = $this->rentals->customerPhone((int) $agreement['customer_id']);
            if ($phone === null) {
                throw new RuntimeException('This booking has no mobile number on record. Please call the rental office to pay.');
            }
        }

        $this->db->beginTransaction();
        try {
            // Checked again under the lock: the hold may have ended, or finance may have recorded a payment.
            $agreement = $this->agreements->find($agreementId, true);
            $this->assertWaitingForDownpayment($agreement);
            if ($policy !== null) {
                $this->rules->recordAcceptance((int) $policy['rules_version_id'], $agreementId, (string) $phone, $ip, $userAgent);
            }
            $receipt = $this->payments->insertPending($agreementId, 'downpayment', $this->gateway->channel(), $method, (string) $agreement['downpayment_amount'], $this->pendingUntil((string) $agreement['hold_expires_at']));
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($error instanceof \PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                // Two clicks at once: the other one made the payment, so continue that one.
                $inProgress = $this->payments->pendingFor($agreementId);
                if ($inProgress !== null) {
                    return $this->gateway->checkoutUrl($inProgress);
                }
                throw new RuntimeException('Your payment could not be started. Please try again.', 0, $error);
            }
            throw $error;
        }
        return $this->gateway->checkoutUrl(['receipt_number' => $receipt]);
    }

    /** The customer's payment in progress, for the checkout page; null when there is none for this booking. */
    public function pendingCheckout(string $receipt, int $agreementId): ?array
    {
        $this->payments->expireStale($agreementId);
        $payment = $this->payments->findByReceipt($receipt);
        if ($payment === null || (int) $payment['agreement_id'] !== $agreementId || $payment['payment_status'] !== 'pending') {
            return null;
        }
        return $payment;
    }

    /** One payment of this booking by its receipt number, whatever its state; null when it belongs to another booking. */
    public function paymentForBooking(string $receipt, int $agreementId): ?array
    {
        $payment = $this->payments->findByReceipt($receipt);
        return $payment !== null && (int) $payment['agreement_id'] === $agreementId ? $payment : null;
    }

    /**
     * Acts on a result message from the gateway. A message with a bad signature, for an
     * unknown payment or for a different amount is refused and written to the security log.
     * A message for a payment that is already settled changes nothing.
     *
     * @return array|null the payment as it now stands, or null when the message was refused
     */
    public function handleGatewayResult(string $payload, string $signature, string $ip = '', string $userAgent = ''): ?array
    {
        $event = $this->gateway?->verifiedResult($payload, $signature);
        if ($event === null) {
            $this->securityLogs->append('payment.result_rejected', null, null, $ip, $userAgent);
            return null;
        }
        $payment = $this->payments->findByReceipt($event['receipt']);
        if ($payment === null || $payment['channel'] !== $this->gateway->channel() || Money::cents($event['amount']) !== Money::cents((string) $payment['amount'])) {
            $this->securityLogs->append('payment.result_mismatch', null, null, $ip, $userAgent);
            return null;
        }
        $agreementId = (int) $payment['agreement_id'];
        $paid = false;
        $this->db->beginTransaction();
        try {
            // Agreement first, then the payment: the same order every payment path locks in.
            $agreement = $this->agreements->find($agreementId, true);
            $payment = $this->payments->findByReceipt($event['receipt'], true);
            if ($agreement === null || $payment === null) {
                throw new RuntimeException('Booking not found.');
            }
            if ($payment['payment_status'] === 'pending') {
                $paymentId = (int) $payment['payment_id'];
                $late = new DateTimeImmutable((string) $payment['expires_at'], new DateTimeZone('UTC')) <= new DateTimeImmutable('now', new DateTimeZone('UTC'));
                if ($event['result'] !== 'paid') {
                    $this->payments->settle($paymentId, $event['result'], null, $event['detail'], $event['result'] === 'failed' ? ($event['reason'] ?? 'The payment was not completed') : null);
                } elseif ($late) {
                    $this->payments->settle($paymentId, 'expired', null, $event['detail'], null);
                } elseif ($agreement['status'] !== 'reserved' || $agreement['downpayment_status'] !== 'due') {
                    $this->payments->settle($paymentId, 'failed', null, $event['detail'], 'The booking was no longer waiting for this payment');
                } else {
                    $this->payments->settle($paymentId, 'paid', $event['reference'], $event['detail'], null);
                    if (!$this->agreements->markDownpaymentReceived($agreementId)) {
                        throw new RuntimeException('The downpayment changed in another request.');
                    }
                    $paid = true;
                }
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        $payment = $this->payments->findByReceipt($event['receipt']);
        if ($paid && $payment !== null) {
            $this->rentals->notifyBooking(
                $agreement,
                'rental.payment_received',
                'Triple R Gensan received your downpayment of ' . Money::pesos(Money::cents((string) $payment['amount'])) . ' for booking ' . $agreement['booking_reference'] . ' (' . PaymentMethods::label($payment['method']) . ', receipt ' . $payment['receipt_number'] . '). The rental office will now confirm your reservation.'
                    // Said plainly to the customer too: the simulated checkout takes no money.
                    . ($payment['channel'] === 'online_demo' ? ' This was a demonstration payment: no money was taken.' : ''),
                'payment-received-' . $payment['payment_id'],
            );
        }
        return $payment;
    }

    /**
     * Finance records money received toward the balance: any method, in full or in part.
     * A blank amount means everything still owed.
     *
     * @return string the receipt number of the new payment
     */
    public function recordBalance(int $agreementId, string $method, string $reference, string $amount, int $actor): string
    {
        $reference = RentalService::staffPaymentReference($method, $reference);
        $amount = trim($amount);
        if ($amount !== '' && preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount) !== 1) {
            throw new RuntimeException('Enter the amount received, for example 7000 or 7000.50.');
        }
        $this->db->beginTransaction();
        try {
            $agreement = $this->agreements->find($agreementId, true);
            if ($agreement === null) {
                throw new RuntimeException('Rental agreement not found.');
            }
            if ($agreement['downpayment_status'] === 'due') {
                throw new RuntimeException('Record the downpayment first. The balance comes after it.');
            }
            if (!in_array($agreement['status'], ['confirmed', 'active', 'returned'], true)) {
                throw new RuntimeException('The balance is recorded after the reservation is confirmed and before the agreement is completed.');
            }
            $owed = $this->rentals->outstandingCents($agreementId);
            if ($owed <= 0) {
                throw new RuntimeException('Nothing is owed on this agreement.');
            }
            $cents = $amount === '' ? $owed : Money::cents($amount);
            if ($cents <= 0) {
                throw new RuntimeException('The amount received must be more than zero.');
            }
            if ($cents > $owed) {
                throw new RuntimeException('That is more than the ' . Money::pesos($owed) . ' still owed on this agreement.');
            }
            $paymentId = $this->payments->insertStaffPayment(['agreement_id' => $agreementId, 'purpose' => 'balance', 'method' => $method, 'amount' => intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT), 'external_reference' => $reference, 'recorded_by' => $actor]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($error instanceof \PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('That reference number is already recorded on another payment.', 0, $error);
            }
            throw $error;
        }
        return (string) ($this->payments->find($paymentId)['receipt_number'] ?? '');
    }

    private function assertWaitingForDownpayment(?array $agreement): void
    {
        if ($agreement === null) {
            throw new RuntimeException('Booking not found.');
        }
        if ($agreement['downpayment_status'] === 'received') {
            throw new RuntimeException('The downpayment for this booking has already been received.');
        }
        if ($agreement['status'] !== 'reserved' || $agreement['downpayment_status'] !== 'due') {
            throw new RuntimeException('This booking is not waiting for a downpayment.');
        }
        if ($agreement['hold_expires_at'] === null || new DateTimeImmutable((string) $agreement['hold_expires_at'], new DateTimeZone('UTC')) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new RuntimeException('The time to pay for this booking has run out. Please make a new booking.');
        }
        if ($this->proofs->hasPending((int) $agreement['agreement_id'])) {
            throw new RuntimeException('Your proof of payment is already waiting to be checked.');
        }
    }

    /** A checkout stays open for PAYMENT_PENDING_MINUTES (15), and never past the end of the hold. */
    private function pendingUntil(string $holdExpiresAt): string
    {
        $utc = new DateTimeZone('UTC');
        $minutes = max(1, min(120, Config::int('PAYMENT_PENDING_MINUTES', 15)));
        $limit = (new DateTimeImmutable('now', $utc))->modify('+' . $minutes . ' minutes');
        $hold = new DateTimeImmutable($holdExpiresAt, $utc);
        return min($limit, $hold)->format('Y-m-d H:i:s.u');
    }
}
