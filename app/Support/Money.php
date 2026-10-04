<?php
declare(strict_types=1);

namespace TripleR\Support;

/**
 * Exact peso arithmetic. Amounts arrive from MySQL DECIMAL(10,2) columns as strings; they are
 * turned into whole centavos, added and multiplied as integers, and turned back into a
 * "1234.50" string for storage. Floats are never used for money.
 */
final class Money
{
    /** "1234.5" → 123450; "-0.25" → -25. Digits past the second decimal are dropped. */
    public static function cents(string $amount): int
    {
        $amount = trim($amount);
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '+-'), 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
        return $negative ? -$cents : $cents;
    }

    /** 123450 → "1234.50"; -25 → "-0.25". */
    public static function amount(int $cents): string
    {
        $abs = abs($cents);
        return ($cents < 0 ? '-' : '') . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** 200000 → "₱2,000"; 123450 → "₱1,234.50". For messages, not tables (see Format::money). */
    public static function pesos(int $cents): string
    {
        return '₱' . number_format($cents / 100, $cents % 100 === 0 ? 0 : 2);
    }
}
