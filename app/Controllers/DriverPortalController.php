<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use RuntimeException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\DriverRepository;
use TripleR\Security\Access;
use TripleR\Security\Csrf;
use TripleR\Services\ProfilePhotoService;
use TripleR\Services\VehicleTrackingService;

/** What a driver sees after signing in: their own trips and their own record, and nobody else's. */
final class DriverPortalController
{
    public function __construct(
        private readonly AuthMiddleware $guard,
        private readonly DriverRepository $drivers,
        private readonly VehicleTrackingService $tracking,
        private readonly ProfilePhotoService $photos,
    ) {
    }

    public function index(): Response
    {
        $user = $this->guard->requireRoles(Access::DRIVER);
        if ($user instanceof Response) {
            return $user;
        }
        $driver = $this->driver($user);
        $trips = $driver === null ? [] : $this->trips((int) $driver['driver_id']);
        $hasPhoto = $driver !== null && $this->photos->has('drivers', (int) $driver['driver_id']);
        $notice = $_SESSION['_driver_portal_notice'] ?? null;
        unset($_SESSION['_driver_portal_notice']);
        $csrfToken = Csrf::token();
        ob_start();
        require APP_ROOT . '/app/Views/driver/home.php';
        return Response::html((string) ob_get_clean());
    }

    /** The signed-in driver's own photo. */
    public function photo(): Response
    {
        $user = $this->guard->requireRoles(Access::DRIVER);
        if ($user instanceof Response) {
            return $user;
        }
        $driver = $this->driver($user);
        $photo = $driver === null ? null : $this->photos->read('drivers', (int) $driver['driver_id']);
        return $photo ? Response::binary($photo['body'], $photo['mime']) : Response::html('Photo not found.', 404);
    }

    /**
     * Turns this phone into the tracker for one of the driver's own trips. A rental has one live
     * tracker link, so sharing from a second phone switches the first one off.
     */
    public function track(Request $request): Response
    {
        $user = $this->guard->requireRoles(Access::DRIVER);
        if ($user instanceof Response) {
            return $user;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $driver = $this->driver($user);
        $agreementId = filter_var($request->form['agreement_id'] ?? null, FILTER_VALIDATE_INT);
        $own = $driver === null ? [] : array_column($this->drivers->trips((int) $driver['driver_id']), 'agreement_id');
        if (!$agreementId || !in_array((int) $agreementId, array_map('intval', $own), true)) {
            return Response::html('Trip not found.', 404);
        }
        try {
            $link = $this->tracking->createLink((int) $agreementId);
        } catch (RuntimeException $error) {
            $_SESSION['_driver_portal_notice'] = $error->getMessage();
            return Response::redirect('/driver');
        }
        // Stay on the address the driver is signed in on; the token rides in the fragment as usual.
        return Response::redirect('/track' . strstr($link, '#'));
    }

    /** The driver record behind this account, or null once it is inactive or removed. */
    private function driver(array $user): ?array
    {
        $driver = $this->drivers->find((int) ($user['driver_id'] ?? 0));
        return $driver !== null && $driver['status'] === 'active' ? $driver : null;
    }

    /** The driver's trips, each marked with whether location can be shared for it and whether a phone already is. */
    private function trips(int $driverId): array
    {
        $trips = $this->drivers->trips($driverId);
        foreach ($trips as &$trip) {
            $trip['can_share'] = in_array($trip['status'], ['confirmed', 'active'], true);
            $trip['sharing'] = $trip['can_share'] && $this->tracking->isConnected((int) $trip['agreement_id']);
        }
        unset($trip);
        return $trips;
    }
}
