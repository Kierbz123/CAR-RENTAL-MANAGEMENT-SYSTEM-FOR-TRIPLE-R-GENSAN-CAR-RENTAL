<?php
declare(strict_types=1);

namespace TripleR\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Display formatting for money, distances and dates. Stored values are never changed. */
final class Format
{
    private const DISPLAY_ZONE = 'Asia/Manila';

    /** 2000 → ₱2,000.00. Null or non-numeric input shows a dash. */
    public static function money(mixed $amount): string
    {
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            return '—';
        }
        $value = (float) $amount;
        return ($value < 0 ? '−' : '') . '₱' . number_format(abs($value), 2);
    }

    public static function km(mixed $value): string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return '—';
        }
        return number_format((float) $value) . ' km';
    }

    /** A calendar date stored without a time zone (rental dates, expiry dates): 2026-10-01 → Oct 1, 2026. */
    public static function date(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            return (new DateTimeImmutable((string) $value))->format('M j, Y');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    /** A timestamp stored in UTC, shown in Manila time: Oct 1, 2026, 2:30 PM. */
    public static function datetime(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            $utc = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
            return $utc->setTimezone(new DateTimeZone(self::DISPLAY_ZONE))->format('M j, Y, g:i A');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    /** The Manila calendar date of a UTC timestamp: Oct 1, 2026. */
    public static function utcDate(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            $utc = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
            return $utc->setTimezone(new DateTimeZone(self::DISPLAY_ZONE))->format('M j, Y');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    /** Time of day in Manila for a UTC timestamp: 2:30 PM. */
    public static function time(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        try {
            $utc = new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
            return $utc->setTimezone(new DateTimeZone(self::DISPLAY_ZONE))->format('g:i A');
        } catch (Throwable) {
            return (string) $value;
        }
    }

    public static function today(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::DISPLAY_ZONE)))->format('Y-m-d');
    }

    public static function plural(int $count, string $singular, ?string $plural = null): string
    {
        return number_format($count) . ' ' . ($count === 1 ? $singular : ($plural ?? $singular . 's'));
    }
}
