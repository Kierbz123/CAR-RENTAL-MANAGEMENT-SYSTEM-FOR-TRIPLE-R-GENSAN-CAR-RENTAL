<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use TripleR\Http\AuthMiddleware;
use TripleR\Http\Response;
use TripleR\Security\Csrf;

final class StaffHomeController
{
    private const ROLES = ['system_admin', 'fleet_manager', 'front_desk', 'driver_coordinator', 'mechanic', 'finance_staff', 'auditor', 'support_staff'];

    public function __construct(private readonly AuthMiddleware $guard)
    {
    }

    public function index(): Response
    {
        $user = $this->guard->requireRoles(self::ROLES);
        if ($user instanceof Response) {
            return $user;
        }
        $canManageUsers = $user['role'] === 'system_admin';
        $canViewNotifications = in_array($user['role'], ['system_admin', 'fleet_manager', 'support_staff'], true);
        $canManageFleet = in_array($user['role'], ['system_admin', 'fleet_manager'], true);
        $canReadDrivers = in_array($user['role'], ['system_admin', 'fleet_manager', 'driver_coordinator'], true);
        $canManageCustomers = in_array($user['role'], ['system_admin', 'front_desk'], true);
        $csrfToken = Csrf::token();
        ob_start();
        require APP_ROOT . '/app/Views/staff/home.php';
        return Response::html((string) ob_get_clean());
    }
}
