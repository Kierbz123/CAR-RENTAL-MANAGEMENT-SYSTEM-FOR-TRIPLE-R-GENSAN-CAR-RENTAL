<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\NotificationRepository;
use TripleR\Security\Csrf;
use TripleR\Security\StaffAuth;

final class StaffNotificationController
{
    public function __construct(
        private readonly StaffAuth $auth,
        private readonly NotificationRepository $notifications,
    ) {
    }

    public function index(): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/staff/login');
        }
        ob_start();
        require APP_ROOT . '/app/Views/staff/notifications.php';
        return Response::html((string) ob_get_clean());
    }

    public function history(Request $request): Response
    {
        if ($this->auth->user() === null) {
            return Response::json(['error' => 'Authentication required.'], 401);
        }
        $limit = filter_var($request->query['limit'] ?? 50, FILTER_VALIDATE_INT);
        $limit = $limit === false ? 50 : max(1, min(200, $limit));
        return Response::json([
            'notifications' => $this->notifications->history($limit),
            'monthly_sent_count' => $this->notifications->monthlySentCount(),
        ]);
    }
}
