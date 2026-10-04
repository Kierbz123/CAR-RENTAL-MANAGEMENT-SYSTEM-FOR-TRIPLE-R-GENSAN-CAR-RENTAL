<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use TripleR\Controllers\Customers\CustomerController;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Http\AuthMiddleware;
use TripleR\Repositories\NotificationRepository;
use TripleR\Security\Csrf;
use TripleR\Services\PhoneVault;
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
        $user = $this->guard->requireRoles(['system_admin', 'fleet_manager']);
        if ($user instanceof Response) {
            return $user;
        }
        ob_start();
        require APP_ROOT . '/app/Views/staff/notifications.php';
        return Response::html((string) ob_get_clean());
    }

    public function history(Request $request): Response
    {
        $user = $this->guard->requireRoles(['system_admin', 'fleet_manager'], true);
        if ($user instanceof Response) {
            return $user;
        }
        $limit = filter_var($request->query['limit'] ?? 50, FILTER_VALIDATE_INT);
        $limit = $limit === false ? 50 : max(1, min(200, $limit));
        // Customer numbers are shown in full only to roles that may reveal customer contacts.
        $fullNumbers = in_array($user['role'], CustomerController::REVEAL_ROLES, true);
        $vault = new PhoneVault();
        $history = array_map(function (array $item) use ($fullNumbers, $vault): array {
            // Numbers are stored sealed (migration 026); older rows still hold them plainly.
            try {
                $number = $vault->numberOf($item['recipient_ciphertext'] ?? null, (string) $item['recipient_phone']);
            } catch (\Throwable) {
                $number = (string) $item['recipient_phone'];
            }
            $item['message_preview'] = $this->messageCipher->staffPreview(
                (string) $item['rendered_message'],
                (string) $item['template_key'],
                $number,
            );
            unset($item['rendered_message'], $item['recipient_ciphertext']);
            $item['recipient_phone'] = $fullNumbers ? $number : PhoneVault::mask($number);
            return $item;
        }, $this->notifications->history($limit));
        return Response::json([
            'notifications' => $history,
            'monthly_sent_count' => $this->notifications->monthlySentCount(),
        ]);
    }
}
