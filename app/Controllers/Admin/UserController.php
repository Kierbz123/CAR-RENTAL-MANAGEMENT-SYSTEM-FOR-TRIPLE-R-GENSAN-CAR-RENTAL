<?php
declare(strict_types=1);

namespace TripleR\Controllers\Admin;

use PDO;
use PDOException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\SecurityLogRepository;
use TripleR\Repositories\SessionRepository;
use TripleR\Repositories\StaffUserRepository;
use TripleR\Security\Csrf;

final class UserController
{
    private const ROLES = ['system_admin', 'fleet_manager', 'front_desk', 'driver_coordinator', 'mechanic', 'finance_staff', 'auditor', 'support_staff'];

    public function __construct(
        private readonly PDO $db,
        private readonly AuthMiddleware $guard,
        private readonly StaffUserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly SecurityLogRepository $securityLogs,
    ) {
    }

    public function index(): Response
    {
        $actor = $this->guard->requireRoles(['system_admin']);
        if ($actor instanceof Response) {
            return $actor;
        }
        return $this->render(['users' => $this->users->list(), 'csrfToken' => Csrf::token(), 'oneTimePassword' => null, 'notice' => null], 200);
    }

    public function create(Request $request): Response
    {
        $actor = $this->guard->requireRoles(['system_admin']);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $email = mb_strtolower(trim((string) ($request->form['email'] ?? '')));
        $role = (string) ($request->form['role'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191 || !in_array($role, self::ROLES, true)) {
            return $this->render(['users' => $this->users->list(), 'csrfToken' => Csrf::token(), 'oneTimePassword' => null, 'notice' => 'Enter a valid email and one of the listed roles.'], 422);
        }
        $temporaryPassword = self::temporaryPassword();
        $this->db->beginTransaction();
        try {
            $userId = $this->users->create($email, password_hash($temporaryPassword, PASSWORD_DEFAULT), $role);
            $this->securityLogs->append('admin_user_created', $userId, $email, $request->ip, $request->userAgent, (int) $actor['id']);
            $this->db->commit();
        } catch (PDOException $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                return $this->render(['users' => $this->users->list(), 'csrfToken' => Csrf::token(), 'oneTimePassword' => null, 'notice' => 'A user with that email already exists.'], 409);
            }
            throw $error;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return $this->render([
            'users' => $this->users->list(),
            'csrfToken' => Csrf::token(),
            'oneTimePassword' => $temporaryPassword,
            'notice' => 'User created. Copy this temporary password now and deliver it out of band; it will not be shown again.',
        ], 201);
    }

    public function edit(Request $request): Response
    {
        $actor = $this->guard->requireRoles(['system_admin']);
        if ($actor instanceof Response) {
            return $actor;
        }
        $userId = filter_var($request->query['user_id'] ?? null, FILTER_VALIDATE_INT);
        $user = $userId ? $this->users->findForAdmin((int) $userId) : null;
        if ($user === null) {
            return Response::html('User not found.', 404);
        }
        ob_start();
        $csrfToken = Csrf::token();
        require APP_ROOT . '/app/Views/admin/user-form.php';
        return Response::html((string) ob_get_clean());
    }

    public function update(Request $request): Response
    {
        $actor = $this->guard->requireRoles(['system_admin']);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        $email = mb_strtolower(trim((string) ($request->form['email'] ?? '')));
        $role = (string) ($request->form['role'] ?? '');
        if (!$userId || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191 || !in_array($role, self::ROLES, true)
            || ((int) $userId === (int) $actor['id'] && $role !== 'system_admin')) {
            return Response::html('The user details are invalid.', 422);
        }
        if ($this->users->findForAdmin((int) $userId) === null) {
            return Response::html('User not found.', 404);
        }
        $this->db->beginTransaction();
        try {
            $this->users->updateProfile((int) $userId, $email, $role);
            $this->sessions->invalidateAllForUser((int) $userId);
            $this->securityLogs->append('admin_user_updated', (int) $userId, $email, $request->ip, $request->userAgent, (int) $actor['id']);
            $this->db->commit();
        } catch (PDOException $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                return Response::html('A user with that email already exists.', 409);
            }
            throw $error;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return Response::redirect('/admin/users');
    }

    public function changeRole(Request $request): Response
    {
        $actor = $this->guard->requireRoles(['system_admin']);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        $role = (string) ($request->form['role'] ?? '');
        if (!$userId || !in_array($role, self::ROLES, true) || ((int) $userId === (int) $actor['id'] && $role !== 'system_admin')) {
            return $this->render(['users' => $this->users->list(), 'csrfToken' => Csrf::token(), 'oneTimePassword' => null, 'notice' => 'The role change is invalid.'], 422);
        }
        $this->db->beginTransaction();
        try {
            $changed = $this->users->setRole((int) $userId, $role);
            if ($changed) {
                $this->sessions->invalidateAllForUser((int) $userId);
                $this->securityLogs->append('admin_user_role_changed', (int) $userId, null, $request->ip, $request->userAgent, (int) $actor['id']);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return $this->index();
    }

    public function deactivate(Request $request): Response
    {
        $actor = $this->guard->requireRoles(['system_admin']);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || (int) $userId === (int) $actor['id']) {
            return $this->render(['users' => $this->users->list(), 'csrfToken' => Csrf::token(), 'oneTimePassword' => null, 'notice' => 'You cannot deactivate the current account.'], 422);
        }
        $this->db->beginTransaction();
        try {
            if ($this->users->deactivate((int) $userId)) {
                $this->sessions->invalidateAllForUser((int) $userId);
                $this->securityLogs->append('admin_user_deactivated', (int) $userId, null, $request->ip, $request->userAgent, (int) $actor['id']);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return $this->index();
    }

    public function reactivate(Request $request): Response
    {
        $actor = $this->guard->requireRoles(['system_admin']);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId) {
            $this->db->beginTransaction();
            try {
                if ($this->users->reactivate((int) $userId)) {
                    $this->securityLogs->append('admin_user_reactivated', (int) $userId, null, $request->ip, $request->userAgent, (int) $actor['id']);
                }
                $this->db->commit();
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }
        }
        return $this->index();
    }

    public function unlock(Request $request): Response
    {
        $actor = $this->guard->requireRoles(['system_admin']);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId) {
            $this->db->beginTransaction();
            try {
                if ($this->users->unlock((int) $userId)) {
                    $this->securityLogs->append('admin_account_unlocked', (int) $userId, null, $request->ip, $request->userAgent, (int) $actor['id']);
                }
                $this->db->commit();
            } catch (\Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }
        }
        return $this->index();
    }

    public function resetPassword(Request $request): Response
    {
        $actor = $this->guard->requireRoles(['system_admin']);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId) {
            return $this->index();
        }
        $temporaryPassword = self::temporaryPassword();
        $this->db->beginTransaction();
        try {
            if (!$this->users->setMustChangePassword((int) $userId, password_hash($temporaryPassword, PASSWORD_DEFAULT))) {
                $this->db->rollBack();
                return $this->index();
            }
            $this->sessions->invalidateAllForUser((int) $userId);
            $this->securityLogs->append('admin_password_reset', (int) $userId, null, $request->ip, $request->userAgent, (int) $actor['id']);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return $this->render([
            'users' => $this->users->list(),
            'csrfToken' => Csrf::token(),
            'oneTimePassword' => $temporaryPassword,
            'notice' => 'Temporary password generated. Copy it now and deliver it out of band; it will not be shown again.',
        ], 200);
    }

    private function render(array $data, int $status): Response
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require APP_ROOT . '/app/Views/admin/users.php';
        return Response::html((string) ob_get_clean(), $status);
    }

    private static function temporaryPassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
