<?php
declare(strict_types=1);

namespace TripleR\Security;

use TripleR\Services\MagicLinkService;

/**
 * Which booking, if any, this browser may see as a customer. Customers have no account, so
 * access comes from one of two things: a secure link they opened, or a booking they just made
 * or found again with its reference and their phone number. Either way it covers one booking.
 */
final class CustomerBookingAccess
{
    private const KEY = '_customer_booking';
    private const SECONDS = 7200;

    /** Called after a booking is made, or found with its reference and phone number. */
    public static function grant(int $agreementId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION[self::KEY] = ['agreement_id' => $agreementId, 'granted_at' => time()];
    }

    public static function agreementId(MagicLinkService $links): ?int
    {
        $own = $_SESSION[self::KEY] ?? null;
        if (!is_array($own) || !isset($own['agreement_id'], $own['granted_at']) || time() - (int) $own['granted_at'] >= self::SECONDS) {
            unset($_SESSION[self::KEY]);
            $own = null;
        }
        $link = $links->currentSessionContext('booking_manage');
        $linked = $link !== null && $link['booking_id'] !== null ? (int) $link['booking_id'] : null;
        if ($own === null || $linked === null) {
            return $own === null ? $linked : (int) $own['agreement_id'];
        }
        // Both exist: whichever the customer opened last is the one they mean.
        $redeemedAt = strtotime((string) ($_SESSION['magic_link_context']['redeemed_at'] ?? '')) ?: 0;
        return $redeemedAt > (int) $own['granted_at'] ? $linked : (int) $own['agreement_id'];
    }
}
