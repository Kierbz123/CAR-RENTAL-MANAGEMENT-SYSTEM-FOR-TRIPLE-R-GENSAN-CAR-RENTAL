<?php
declare(strict_types=1);

namespace TripleR\Security;

/**
 * Proof, held in this browser's session, that the visitor can read texts sent to a mobile number.
 *
 * An online booking is attached to the customer who owns its mobile number, so the number must be
 * the visitor's own: a 6-digit code is texted to it and typed back. Only a hash of the code is
 * kept, it expires after CODE_SECONDS, and five wrong tries end it. A confirmed number stays
 * confirmed in this session for VERIFIED_SECONDS so a second booking does not need a new code.
 */
final class BookingPhoneVerification
{
    private const CODE_KEY = '_booking_phone_code';
    private const VERIFIED_KEY = '_booking_phone_verified';
    private const PENDING_KEY = '_booking_pending_form';
    public const CODE_SECONDS = 600;
    private const VERIFIED_SECONDS = 1800;
    private const MAX_TRIES = 5;

    /** Starts a new code for $phone (already normalized) and returns it to be texted. */
    public static function issue(string $phone): string
    {
        $code = sprintf('%06d', random_int(0, 999999));
        $_SESSION[self::CODE_KEY] = ['phone' => $phone, 'hash' => self::hash($code, $phone), 'expires' => time() + self::CODE_SECONDS, 'tries' => 0];
        return $code;
    }

    /** The number a code is waiting for, or null. */
    public static function pendingPhone(): ?string
    {
        $state = $_SESSION[self::CODE_KEY] ?? null;
        return is_array($state) && (int) $state['expires'] > time() ? (string) $state['phone'] : null;
    }

    /** True when $code is right; the number is then confirmed for this session. */
    public static function confirm(string $code): bool
    {
        $state = $_SESSION[self::CODE_KEY] ?? null;
        if (!is_array($state) || (int) $state['expires'] <= time() || (int) $state['tries'] >= self::MAX_TRIES) {
            unset($_SESSION[self::CODE_KEY]);
            return false;
        }
        $_SESSION[self::CODE_KEY]['tries'] = (int) $state['tries'] + 1;
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($code) !== 6 || !hash_equals((string) $state['hash'], self::hash($code, (string) $state['phone']))) {
            return false;
        }
        unset($_SESSION[self::CODE_KEY]);
        $_SESSION[self::VERIFIED_KEY] = ['phone' => (string) $state['phone'], 'until' => time() + self::VERIFIED_SECONDS];
        return true;
    }

    public static function isVerified(string $phone): bool
    {
        $state = $_SESSION[self::VERIFIED_KEY] ?? null;
        return is_array($state) && (int) $state['until'] > time() && hash_equals((string) $state['phone'], $phone);
    }

    /** The booking form waiting for the code, kept in the session (never in the page). */
    public static function holdForm(array $form): void
    {
        unset($form['_csrf'], $form['code']);
        $_SESSION[self::PENDING_KEY] = $form;
    }

    public static function heldForm(): ?array
    {
        $form = $_SESSION[self::PENDING_KEY] ?? null;
        return is_array($form) ? $form : null;
    }

    public static function clearForm(): void
    {
        unset($_SESSION[self::PENDING_KEY]);
    }

    private static function hash(string $code, string $phone): string
    {
        return hash('sha256', $code . '|' . $phone);
    }
}
