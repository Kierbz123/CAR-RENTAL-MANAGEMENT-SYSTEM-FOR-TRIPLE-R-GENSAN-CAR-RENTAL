<?php
declare(strict_types=1);

namespace TripleR\Controllers\Admin;

use PDO;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\SecurityLogRepository;
use TripleR\Repositories\SessionRepository;
use TripleR\Repositories\StaffUserRepository;
use TripleR\Security\Csrf;

final class SessionController
{
    public function __construct(
        private readonly PDO $db,
        private readonly AuthMiddleware $guard,
        private readonly StaffUserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly SecurityLogRepository $securityLogs,
    ) {
    }

    public function index(Request $request): Response
    {
        $actor = $this->guard->requireRoles(['system_admin']);
        if ($actor instanceof Response) {
            return $actor;
        }
        $userId = filter_var($request->query['user_id'] ?? null, FILTER_VALIDATE_INT);
        $target = $userId ? $this->users->findForAdmin((int) $userId) : null;
        if ($target === null) {
            return Response::html('User not found.', 404);
        }
        $sessions = $this->sessions->forUser((int) $userId);
        ob_start();
        require APP_ROOT . '/app/Views/admin/sessions.php';
        return Response::html((string) ob_get_clean());
    }

    public function invalidate(Request $request): Response
    {
        $actor = $this->guard->requireRoles(['system_admin']);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::json(['error' => 'Invalid request token.'], 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        $sessionId = filter_var($request->form['session_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || !$sessionId) {
            return Response::json(['error' => 'A valid user and session are required.'], 422);
        }
        $this->db->beginTransaction();
        try {
            $changed = $this->sessions->invalidateById((int) $userId, (int) $sessionId);
            if ($changed) {
                $this->securityLogs->append('admin_session_invalidated', (int) $userId, null, $request->ip, $request->userAgent, (int) $actor['id']);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return Response::redirect('/admin/sessions?user_id=' . (int) $userId);
    }
}
