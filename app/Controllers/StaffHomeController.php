<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use TripleR\Http\AuthMiddleware;
use TripleR\Http\Response;
use TripleR\Repositories\DashboardRepository;
use TripleR\Repositories\PaymentProofRepository;
use TripleR\Config;
use TripleR\Security\Access;
use TripleR\Security\Csrf;
use TripleR\Support\Format;

final class StaffHomeController
{
    public function __construct(
        private readonly AuthMiddleware $guard,
        private readonly DashboardRepository $dashboard,
        private readonly PaymentProofRepository $paymentProofs,
    ) {
    }

    /** A printable QR code that opens the public booking page. */
    public function bookingQr(): Response
    {
        $user = $this->guard->requireRoles(Access::STAFF);
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
        $user = $this->guard->requireRoles(Access::ROLES);
        if ($user instanceof Response) {
            return $user;
        }
        // A driver who lands here (an old bookmark, the site's front page) goes to their own page.
        if (!in_array($user['role'], Access::STAFF, true)) {
            return Response::redirect(Access::home((string) $user['role']));
        }
        $can = static fn (array $roles): bool => in_array($user['role'], $roles, true);
        $canManageFleet = $can(Access::FLEET_MANAGE);
        $canManageCustomers = $can(Access::CUSTOMERS);
        $canCreateRentals = $can(Access::CUSTOMERS);
        $canDecideDamage = $can(Access::DAMAGE_DECIDE);
        $canTakePayments = $can(Access::PAYMENTS);

        // Read-only counts, shown to every staff role: all three can open the fleet and the agreements.
        $today = Format::today();
        $vehicleCounts = $this->dashboard->vehicleCounts();
        $rentalCounts = $this->dashboard->rentalCounts($today);
        $schedule = $this->dashboard->todaySchedule($today);
        $proofsToCheck = $canTakePayments ? $this->paymentProofs->awaitingReviewCount() : 0;
        $csrfToken = Csrf::token();
        ob_start();
        require APP_ROOT . '/app/Views/staff/home.php';
        return Response::html((string) ob_get_clean());
    }
}
