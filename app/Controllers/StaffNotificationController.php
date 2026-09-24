<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Http\AuthMiddleware;
use TripleR\Repositories\NotificationRepository;
use TripleR\Security\Csrf;
use TripleR\Services\SmsMessageCipher;

final class StaffNotificationController
{
    public function __construct(
        private readonly AuthMiddleware $guard,
        private readonly NotificationRepository $notifications,
        private readonly SmsMessageCipher $messageCipher,
    ) {
    }

    public function index(): Response
    {
        $user = $this->guard->requireRoles(['system_admin', 'fleet_manager', 'support_staff']);
        if ($user instanceof Response) {
            return $user;
        }
        ob_start();
        require APP_ROOT . '/app/Views/staff/notifications.php';
        return Response::html((string) ob_get_clean());
    }

    public function history(Request $request): Response
    {
        $user = $this->guard->requireRoles(['system_admin', 'fleet_manager', 'support_staff'], true);
        if ($user instanceof Response) {
            return $user;
        }
        $limit = filter_var($request->query['limit'] ?? 50, FILTER_VALIDATE_INT);
        $limit = $limit === false ? 50 : max(1, min(200, $limit));
        $history = array_map(function (array $item): array {
            $item['message_preview'] = $this->messageCipher->staffPreview(
                (string) $item['rendered_message'],
                (string) $item['template_key'],
                (string) $item['recipient_phone'],
            );
            unset($item['rendered_message']);
            return $item;
        }, $this->notifications->history($limit));
        return Response::json([
            'notifications' => $history,
            'monthly_sent_count' => $this->notifications->monthlySentCount(),
        ]);
    }
}
