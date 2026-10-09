<?php
declare(strict_types=1);

namespace TripleR\Controllers\Fleet;

use RuntimeException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\RentalRepository;
use TripleR\Security\Access;
use TripleR\Security\Csrf;
use TripleR\Services\VehicleTrackingService;

/**
 * Staff side of live tracking: the positions the map on /fleet/locations asks for, and the
 * page where a phone is connected to a rental.
 */
final class TrackingController
{
    public function __construct(
        private readonly AuthMiddleware $guard,
        private readonly VehicleTrackingService $tracking,
        private readonly RentalRepository $agreements,
    ) {
    }

    /** The live feed: every vehicle out on rental with its latest position. Asked for every few seconds by the map. */
    public function positions(): Response
    {
        $user = $this->guard->requireRoles(Access::FLEET_VIEW, true);
        if ($user instanceof Response) {
            return $user;
        }
        return Response::json(['vehicles' => $this->tracking->feed()]);
    }

    public function connectForm(Request $request): Response
    {
        $user = $this->guard->requireRoles(Access::STAFF);
        if ($user instanceof Response) {
            return $user;
        }
        $agreement = $this->agreement($request->query['agreement_id'] ?? null);
        if ($agreement === null) {
            return Response::html('Rental agreement not found.', 404);
        }
        return $this->render($user, $agreement, null, $_SESSION['_tracking_notice'] ?? null);
    }

    /** Creates a fresh tracker link and shows it once, as a QR code to scan with the phone. */
    public function connect(Request $request): Response
    {
        $user = $this->guard->requireRoles(Access::STAFF);
        if ($user instanceof Response) {
            return $user;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $agreement = $this->agreement($request->form['agreement_id'] ?? null);
        if ($agreement === null) {
            return Response::html('Rental agreement not found.', 404);
        }
        try {
            $link = $this->tracking->createLink((int) $agreement['agreement_id']);
        } catch (RuntimeException $error) {
            return $this->render($user, $agreement, null, $error->getMessage());
        }
        return $this->render($user, $agreement, $link, null);
    }

    public function disconnect(Request $request): Response
    {
        $user = $this->guard->requireRoles(Access::STAFF);
        if ($user instanceof Response) {
            return $user;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $agreement = $this->agreement($request->form['agreement_id'] ?? null);
        if ($agreement === null) {
            return Response::html('Rental agreement not found.', 404);
        }
        $_SESSION['_tracking_notice'] = $this->tracking->disconnect((int) $agreement['agreement_id'])
            ? 'The phone was disconnected. It can no longer report this vehicle’s position.'
            : 'No phone was connected.';
        return Response::redirect('/fleet/tracking/connect?agreement_id=' . (int) $agreement['agreement_id']);
    }

    private function agreement(mixed $id): ?array
    {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        return $id === false || $id < 1 ? null : $this->agreements->find((int) $id);
    }

    private function render(array $user, array $agreement, ?string $link, ?string $notice): Response
    {
        unset($_SESSION['_tracking_notice']);
        $connected = $this->tracking->isConnected((int) $agreement['agreement_id']);
        // The page itself says whether the phone is reporting, so nobody has to open the map to find out.
        $phoneStatus = $this->tracking->statusFor((int) $agreement['agreement_id']);
        $canConnect = in_array($agreement['status'], ['confirmed', 'active'], true);
        $canWatch = in_array($user['role'], Access::FLEET_VIEW, true);
        $isLocalAddress = $link !== null && in_array(strtolower((string) parse_url($link, PHP_URL_HOST)), ['localhost', '127.0.0.1'], true);
        $csrfToken = Csrf::token();
        ob_start();
        require APP_ROOT . '/app/Views/fleet/tracker-connect.php';
        return Response::html((string) ob_get_clean());
    }
}
