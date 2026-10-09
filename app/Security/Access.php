<?php
declare(strict_types=1);

namespace TripleR\Security;

/**
 * Who may do what, in one place. Controllers pass these lists to
 * AuthMiddleware::requireRoles(); views use them to decide which controls to show.
 */
final class Access
{
    /** Every role an account can hold. */
    public const ROLES = ['system_admin', 'fleet_manager', 'front_desk', 'driver'];
    /** The roles an administrator gives by hand. A driver account is made from a driver record instead. */
    public const STAFF_ROLES = ['system_admin', 'fleet_manager', 'front_desk'];

    private const FRONT = ['system_admin', 'front_desk'];
    private const FLEET = ['system_admin', 'fleet_manager'];

    /** Workspace, agreements, pickup and return, tracker phones, recording damage, notifications. */
    public const STAFF = self::STAFF_ROLES;
    /** Customers, new bookings, confirm / cancel / no-show, booking links and messages. */
    public const CUSTOMERS = self::FRONT;
    /** Money coming in: proofs, payments, charges, completing a rental, holding and releasing a deposit. */
    public const PAYMENTS = self::FRONT;
    /** Money going out: reversing a charge, refunding or forfeiting a deposit. */
    public const MONEY_OUT = self::ADMIN;
    public const FLEET_VIEW = self::STAFF;
    public const FLEET_MANAGE = self::FLEET;
    public const DRIVERS_VIEW = self::STAFF;
    public const DRIVERS_MANAGE = self::FLEET;
    /** Licence number, address and emergency contact. Front desk may reveal a driver's phone only. */
    public const DRIVER_REVEAL_ALL = self::FLEET;
    public const DRIVER_ASSIGN = self::STAFF;
    public const DAMAGE_DECIDE = self::FLEET;
    /** Staff accounts, sessions, deleting a location. */
    public const ADMIN = ['system_admin'];
    /** A driver's own trips and profile. */
    public const DRIVER = ['driver'];

    /** Where an account lands after signing in. */
    public static function home(string $role): string
    {
        return $role === 'driver' ? '/driver' : '/staff';
    }
}
