<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use RuntimeException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Response;
use TripleR\Repositories\DashboardRepository;
use TripleR\Repositories\MaintenanceRepository;
use TripleR\Repositories\PaymentProofRepository;
use TripleR\Config;
use TripleR\Security\Csrf;
use TripleR\Services\MaintenanceService;
use TripleR\Support\Format;

final class StaffHomeController
{
    private const ROLES = ['system_admin', 'fleet_manager', 'front_desk', 'driver_coordinator', 'mechanic', 'finance_staff', 'auditor', 'support_staff'];

    public function __construct(
        private readonly AuthMiddleware $guard,
        private readonly DashboardRepository $dashboard,
        private readonly MaintenanceRepository $maintenance,
        private readonly MaintenanceService $maintenanceService,
        private readonly PaymentProofRepository $paymentProofs,
    ) {
    }

    /** A printable QR code that opens the public booking page. */
    public function bookingQr(): Response
    {
        $user = $this->guard->requireRoles(self::ROLES);
        if ($user instanceof Response) {
            return $user;
        }
        $bookingUrl = rtrim(Config::require('APP_BASE_URL'), '/') . '/book';
        $isLocalAddress = in_array(strtolower((string) parse_url($bookingUrl, PHP_URL_HOST)), ['localhost', '127.0.0.1'], true);
        ob_start();
        require APP_ROOT . '/app/Views/staff/booking-qr.php';
        return Response::html((string) ob_get_clean());
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
        $canViewFleet = in_array($user['role'], ['system_admin', 'fleet_manager', 'front_desk'], true);
        $canReadDrivers = in_array($user['role'], ['system_admin', 'fleet_manager', 'driver_coordinator'], true);
        $canManageCustomers = in_array($user['role'], ['system_admin', 'front_desk'], true);
        $canViewRentals = in_array($user['role'], ['system_admin','fleet_manager','front_desk','finance_staff','auditor','driver_coordinator'], true);
        $canCreateRentals = in_array($user['role'], ['system_admin', 'front_desk'], true);
        $canViewMaintenance = in_array($user['role'], ['system_admin','fleet_manager','mechanic','auditor'], true);

        // Read-only counts. Each block is loaded only for roles that can already open the matching module.
        $today = Format::today();
        $vehicleCounts = $canViewFleet ? $this->dashboard->vehicleCounts() : null;
        $rentalCounts = $canViewRentals ? $this->dashboard->rentalCounts($today) : null;
        $schedule = $canViewRentals ? $this->dashboard->todaySchedule($today) : [];
        $proofsToCheck = in_array($user['role'], ['system_admin', 'finance_staff'], true) ? $this->paymentProofs->awaitingReviewCount() : 0;
        $maintenanceDue = null;
        if ($canViewMaintenance) {
            try {
                $defaults = $this->maintenanceService->defaults();
                $maintenanceDue = $this->maintenance->dueSoon($defaults['days'], $defaults['kilometers']);
            } catch (RuntimeException) {
                $maintenanceDue = null;
            }
        }
        $csrfToken = Csrf::token();
        ob_start();
        require APP_ROOT . '/app/Views/staff/home.php';
        return Response::html((string) ob_get_clean());
    }
}
