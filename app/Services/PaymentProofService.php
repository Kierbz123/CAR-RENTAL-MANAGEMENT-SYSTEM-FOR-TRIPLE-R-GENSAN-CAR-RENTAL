<?php
declare(strict_types=1);

namespace TripleR\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use TripleR\Repositories\PaymentProofRepository;
use TripleR\Repositories\PaymentRepository;
use TripleR\Repositories\RentalRepository;

/**
 * A customer submits proof of their GCash downpayment; finance verifies or rejects it.
 *
 * Verifying records the downpayment on the agreement in the same transaction; front desk then
 * confirms the reservation, as for a payment recorded at the counter. Rejecting leaves the
 * downpayment due and tells the customer why, so they can send a correct proof.
 */
final class PaymentProofService
{
    public function __construct(
        private readonly PDO $db,
        private readonly PaymentProofRepository $proofs,
        private readonly RentalRepository $rentals,
        private readonly RentalService $rentalService,
        private readonly VehiclePhotoService $files,
        private readonly RateLimiter $rateLimiter,
        private readonly PaymentRepository $payments,
    ) {
    }

    /** The customer's side: a reference number and a screenshot, once, while the hold is running. */
    public function submit(int $agreementId, string $reference, array $file, string $ip): void
    {
        $reference = RentalService::normalizePaymentReference($reference, 'GCash reference number');
        if (!$this->rateLimiter->allow('payment-proof-submit', (string) $agreementId, 5, 3600)) {
            throw new RuntimeException('Too many proofs were sent for this booking. Please wait an hour or call the rental office.');
        }
        $this->assertOpenForProof($this->rentals->find($agreementId));
        if ($this->proofs->referenceUsedElsewhere($reference, $agreementId)) {
            throw new RuntimeException('That GCash reference number was already used for another booking. Check the number and try again.');
        }
        $stored = $this->files->storeEvidence($file, 'payments/' . $agreementId);
        $this->db->beginTransaction();
        try {
            // Checked again under the lock, so two submissions cannot both get in.
            $this->assertOpenForProof($this->rentals->find($agreementId, true));
            if ($this->proofs->hasPending($agreementId)) {
                throw new RuntimeException('Your proof of payment is already waiting to be checked.');
            }
            if ($this->payments->hasPending($agreementId)) {
                throw new RuntimeException('You have an online payment in progress. Finish or cancel it before sending a proof.');
            }
            $this->proofs->insert($agreementId, $reference, $stored, filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->files->removeEvidence($stored['storage_path']);
            if ($error instanceof \PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('Your proof of payment is already waiting to be checked.', 0, $error);
            }
            throw $error;
        }
    }

    /** @return string what happened, for the notice shown to the staff member */
    public function verify(int $proofId, int $actor): string
    {
        $proof = $this->proofs->find($proofId);
        if ($proof === null) {
            throw new RuntimeException('Payment proof not found.');
        }
        if ($proof['proof_status'] !== 'submitted') {
            throw new RuntimeException('This proof has already been decided.');
        }
        $agreementId = (int) $proof['agreement_id'];
        $this->rentalService->recordDownpaymentFromProof($agreementId, (string) $proof['reference_number'], $actor, function () use ($proofId, $actor): void {
            if (!$this->proofs->markVerified($proofId, $actor)) {
                throw new RuntimeException('This proof was decided in another request. Reload and try again.');
            }
        }, $proofId);
        return 'Payment verified and the downpayment recorded. Front desk can now confirm the reservation.';
    }

    public function reject(int $proofId, string $reason, int $actor): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new RuntimeException('Say why the proof is rejected, in up to 500 characters. The customer is sent this reason.');
        }
        $proof = $this->proofs->find($proofId);
        if ($proof === null) {
            throw new RuntimeException('Payment proof not found.');
        }
        if (!$this->proofs->markRejected($proofId, $actor, $reason)) {
            throw new RuntimeException('This proof has already been decided.');
        }
        $agreement = $this->rentals->find((int) $proof['agreement_id']);
        if ($agreement !== null) {
            $this->rentalService->notifyBooking(
                $agreement,
                'rental.proof_rejected',
                'Triple R Gensan could not verify your GCash payment for booking ' . $agreement['booking_reference'] . ': ' . $reason . ' Please send your proof again from your booking page, or call the rental office.',
                'proof-rejected-' . $proofId,
            );
        }
    }

    /** @return array{body:string,mime:string} */
    public function screenshot(int $proofId): array
    {
        $proof = $this->proofs->find($proofId);
        if ($proof === null) {
            throw new RuntimeException('Payment proof not found.');
        }
        return ['body' => $this->files->readEvidence((string) $proof['storage_path']), 'mime' => (string) $proof['mime']];
    }

    private function assertOpenForProof(?array $agreement): void
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
    }
}
