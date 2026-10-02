<?php
declare(strict_types=1);

namespace TripleR\Services\Payments;

use RuntimeException;
use TripleR\Support\PaymentMethods;

/**
 * A stand-in for a payment gateway, for demonstrations. NO REAL MONEY MOVES.
 *
 * It plays the gateway's part end to end: the customer is sent to its checkout page
 * (/pay/demo), an outcome is chosen there, and it reports the result to the booking system as
 * a signed message, the way a real gateway calls a webhook. The booking system treats that
 * message exactly as it would a real one, which is what makes the demonstration honest.
 *
 * It never asks for a wallet PIN, a one-time code or a bank password, and it accepts only the
 * test card numbers in config/payments.php, so nobody can hand it a real credential.
 */
final class SimulatedGateway implements PaymentGateway
{
    /** Outcome chosen on the checkout => [result reported, reason shown to the customer and staff]. */
    private const OUTCOMES = [
        'approved' => ['paid', null],
        'insufficient' => ['failed', 'Not enough balance to cover the payment'],
        'declined' => ['failed', 'Declined by the issuing bank'],
        'expired_card' => ['failed', 'The card has expired'],
        'verification_failed' => ['failed', 'The bank’s verification step was not passed'],
        'cancelled' => ['cancelled', null],
        'timed_out' => ['expired', null],
    ];

    public function __construct(private readonly string $secret)
    {
        if (strlen($secret) < 16) {
            throw new RuntimeException('PAYMENT_WEBHOOK_SECRET must be at least 16 characters.');
        }
    }

    public function channel(): string
    {
        return 'online_demo';
    }

    public function checkoutUrl(array $payment): string
    {
        return '/pay/demo?receipt=' . rawurlencode((string) $payment['receipt_number']);
    }

    public function verifiedResult(string $payload, string $signature): ?array
    {
        if ($signature === '' || !hash_equals($this->signature($payload), $signature)) {
            return null;
        }
        $data = json_decode($payload, true);
        if (!is_array($data) || !is_string($data['receipt'] ?? null) || !is_string($data['amount'] ?? null) || !in_array($data['result'] ?? null, ['paid', 'failed', 'cancelled', 'expired'], true)) {
            return null;
        }
        return [
            'receipt' => $data['receipt'],
            'result' => $data['result'],
            'amount' => $data['amount'],
            'reference' => is_string($data['reference'] ?? null) ? $data['reference'] : null,
            'detail' => is_string($data['detail'] ?? null) ? $data['detail'] : null,
            'reason' => is_string($data['reason'] ?? null) ? $data['reason'] : null,
        ];
    }

    /** @return list<string> the outcomes the checkout page may choose for a wallet or bank payment */
    public static function outcomes(): array
    {
        return array_keys(self::OUTCOMES);
    }

    /**
     * The message this gateway sends when a checkout ends with $outcome, and its signature.
     *
     * @return array{payload:string,signature:string}
     */
    public function result(array $payment, string $outcome, ?string $detail = null): array
    {
        if (!isset(self::OUTCOMES[$outcome])) {
            throw new RuntimeException('Choose what happens to the payment.');
        }
        [$result, $reason] = self::OUTCOMES[$outcome];
        $payload = (string) json_encode([
            'receipt' => (string) $payment['receipt_number'],
            'result' => $result,
            'amount' => (string) $payment['amount'],
            // The gateway's own transaction number, as a real one issues for a completed payment.
            'reference' => $result === 'paid' ? 'DEMO-' . strtoupper(bin2hex(random_bytes(6))) : null,
            'detail' => $detail,
            'reason' => $reason,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return ['payload' => $payload, 'signature' => $this->signature($payload)];
    }

    /**
     * What a typed card number stands for: its brand, the outcome it produces and the detail
     * kept on the payment ("Visa ending 4242"). Null for any number that is not a listed test card.
     *
     * @return array{brand:string,outcome:string,detail:string}|null
     */
    public function testCard(string $number): ?array
    {
        $digits = preg_replace('/[\s-]+/', '', $number) ?? '';
        $card = PaymentMethods::config()['test_cards'][$digits] ?? null;
        if ($card === null) {
            return null;
        }
        return ['brand' => $card['brand'], 'outcome' => $card['outcome'], 'detail' => $card['brand'] . ' ending ' . substr($digits, -4)];
    }

    private function signature(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
