<?php
declare(strict_types=1);

namespace TripleR\Support;

/** Reads the payment methods from config/payments.php, the one place they are listed. */
final class PaymentMethods
{
    private static ?array $data = null;

    public static function config(): array
    {
        return self::$data ??= require APP_ROOT . '/config/payments.php';
    }

    /** @return array<string, array{label:string,kind:string,online:bool,staff:bool,reference_label:?string}> */
    public static function all(): array
    {
        return self::config()['methods'];
    }

    /** Methods the customer can choose on the checkout. */
    public static function online(): array
    {
        return array_filter(self::all(), static fn (array $method): bool => $method['online']);
    }

    /** Methods finance can record for money received at the counter. */
    public static function staff(): array
    {
        return array_filter(self::all(), static fn (array $method): bool => $method['staff']);
    }

    public static function label(mixed $key): string
    {
        return self::all()[(string) $key]['label'] ?? StatusPresenter::label($key);
    }

    /** "GCash", or "Credit or debit card (Visa ending 4242)" when the payment kept a detail. */
    public static function describe(array $payment): string
    {
        $label = self::label($payment['method'] ?? '');
        $detail = trim((string) ($payment['method_detail'] ?? ''));
        return $detail === '' ? $label : $label . ' (' . $detail . ')';
    }

    /** Who took the payment, in words: the simulated checkout, a verified proof, or the counter. */
    public static function channelLabel(array $payment): string
    {
        if (($payment['channel'] ?? '') === 'online_demo') {
            return 'Demonstration checkout';
        }
        return ($payment['proof_id'] ?? null) !== null ? 'Proof verified by finance' : 'Recorded at the counter';
    }
}
