<?php
declare(strict_types=1);

namespace TripleR\Support;

final class PhoneNumber
{
    public static function normalize(string $value): string
    {
        $number = preg_replace('/[\s().-]+/', '', trim($value)) ?? '';
        if (str_starts_with($number, '00')) {
            $number = '+' . substr($number, 2);
        } elseif (str_starts_with($number, '0') && preg_match('/^09\d{9}$/', $number) === 1) {
            $number = '+63' . substr($number, 1);
        } elseif (preg_match('/^63\d{10}$/', $number) === 1) {
            $number = '+' . $number;
        } elseif ($number !== '' && $number[0] !== '+') {
            $number = '+' . $number;
        }
        if (preg_match('/^\+[1-9]\d{7,14}$/', $number) !== 1) {
            throw new \InvalidArgumentException('Enter a valid phone number in international format.');
        }
        return $number;
    }
}
