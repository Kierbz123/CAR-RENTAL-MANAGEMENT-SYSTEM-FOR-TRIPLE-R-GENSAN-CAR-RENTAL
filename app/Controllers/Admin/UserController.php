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
use TripleR\Security\Access;
use TripleR\Security\Csrf;
use TripleR\Support\Pager;

final class UserController
{
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
        $actor = $this->guard->requireRoles(Access::ADMIN);
        if ($actor instanceof Response) {
            return $actor;
        }
        return $this->render(['csrfToken' => Csrf::token(), 'oneTimePassword' => null, 'notice' => null], 200);
    }

    public function create(Request $request): Response
    {
        $actor = $this->guard->requireRoles(Access::ADMIN);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $email = mb_strtolower(trim((string) ($request->form['email'] ?? '')));
        $role = (string) ($request->form['role'] ?? '');
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191 || !in_array($role, Access::ROLES, true)) {
            return $this->render(['csrfToken' => Csrf::token(), 'oneTimePassword' => null, 'notice' => 'Enter a valid email and one of the listed roles.'], 422);
        }
        // A driver's account is tied to one driver record; every other account is tied to none.
        $driverId = filter_var($request->form['driver_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $free = array_map('intval', array_column($this->users->driversWithoutAccount(), 'driver_id'));
        if (($role === 'driver') !== ($driverId !== null) || ($driverId !== null && !in_array($driverId, $free, true))) {
            return $this->render(['csrfToken' => Csrf::token(), 'oneTimePassword' => null, 'notice' => $role === 'driver'
                ? 'Choose the driver this account is for. Only active drivers who have no account yet are listed.'
                : 'A driver can be chosen only for the Driver role.'], 422);
        }
        $temporaryPassword = self::temporaryPassword();
        $this->db->beginTransaction();
        try {
            $userId = $this->users->create($email, password_hash($temporaryPassword, PASSWORD_DEFAULT), $role, $driverId);
            $this->securityLogs->append('admin_user_created', $userId, $email, $request->ip, $request->userAgent, (int) $actor['id']);
            $this->db->commit();
        } catch (PDOException $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                return $this->render(['csrfToken' => Csrf::token(), 'oneTimePassword' => null, 'notice' => 'A user with that email already exists.'], 409);
            }
            throw $error;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        return $this->render([
            'csrfToken' => Csrf::token(),
            'oneTimePassword' => $temporaryPassword,
            'notice' => 'User created. Copy this temporary password now and deliver it out of band; it will not be shown again.',
        ], 201);
    }

    public function edit(Request $request): Response
    {
        $actor = $this->guard->requireRoles(Access::ADMIN);
        if ($actor instanceof Response) {
            return $actor;
        }
        $userId = filter_var($request->query['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId && $this->users->isSystemAccount((int) $userId)) {
            return Response::html('The system account records automated actions and cannot be changed.', 422);
        }
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
        $actor = $this->guard->requireRoles(Access::ADMIN);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId && $this->users->isSystemAccount((int) $userId)) {
            return Response::html('The system account records automated actions and cannot be changed.', 422);
        }
        $email = mb_strtolower(trim((string) ($request->form['email'] ?? '')));
        $role = (string) ($request->form['role'] ?? '');
        $existing = $userId ? $this->users->findForAdmin((int) $userId) : null;
        // A driver's account stays a driver's account; only its email changes here. Staff accounts move among the staff roles.
        $isDriverAccount = $existing !== null && $existing['role'] === 'driver';
        if ($isDriverAccount) {
            $role = 'driver';
        }
        if (!$userId || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 191 || (!$isDriverAccount && !in_array($role, Access::STAFF_ROLES, true))
            || ((int) $userId === (int) $actor['id'] && $role !== 'system_admin')) {
            return Response::html('The user details are invalid.', 422);
        }
        if ($existing === null) {
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
        $actor = $this->guard->requireRoles(Access::ADMIN);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId && $this->users->isSystemAccount((int) $userId)) {
            return Response::html('The system account records automated actions and cannot be changed.', 422);
        }
        $role = (string) ($request->form['role'] ?? '');
        $target = $userId ? $this->users->findForAdmin((int) $userId) : null;
        // Staff move among the staff roles; an account is never turned into, or out of, a driver's account.
        if ($target === null || $target['role'] === 'driver' || !in_array($role, Access::STAFF_ROLES, true) || ((int) $userId === (int) $actor['id'] && $role !== 'system_admin')) {
            return $this->render(['csrfToken' => Csrf::token(), 'oneTimePassword' => null, 'notice' => 'The role change is invalid.'], 422);
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
        $actor = $this->guard->requireRoles(Access::ADMIN);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId && $this->users->isSystemAccount((int) $userId)) {
            return Response::html('The system account records automated actions and cannot be changed.', 422);
        }
        if (!$userId || (int) $userId === (int) $actor['id']) {
            return $this->render(['csrfToken' => Csrf::token(), 'oneTimePassword' => null, 'notice' => 'You cannot deactivate the current account.'], 422);
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
        $actor = $this->guard->requireRoles(Access::ADMIN);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId && $this->users->isSystemAccount((int) $userId)) {
            return Response::html('The system account records automated actions and cannot be changed.', 422);
        }
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
        $actor = $this->guard->requireRoles(Access::ADMIN);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId && $this->users->isSystemAccount((int) $userId)) {
            return Response::html('The system account records automated actions and cannot be changed.', 422);
        }
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
        $actor = $this->guard->requireRoles(Access::ADMIN);
        if ($actor instanceof Response) {
            return $actor;
        }
        if (!Csrf::valid($request)) {
            return Response::html('Invalid request token.', 403);
        }
        $userId = filter_var($request->form['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($userId && $this->users->isSystemAccount((int) $userId)) {
            return Response::html('The system account records automated actions and cannot be changed.', 422);
        }
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
            'csrfToken' => Csrf::token(),
            'oneTimePassword' => $temporaryPassword,
            'notice' => 'Temporary password generated. Copy it now and deliver it out of band; it will not be shown again.',
        ], 200);
    }

    private function render(array $data, int $status): Response
    {
        // The account list is read one page at a time.
        // Deactivated accounts cannot be deleted (the sign-in history refers to them), so they are kept out of the everyday list.
        $showDeactivated = ($_GET['show'] ?? '') === 'deactivated';
        $total = $this->users->count($showDeactivated);
        $window = Pager::window($total);
        $data['users'] = $this->users->list($window['limit'], $window['offset'], $showDeactivated);
        $data['total'] = $total;
        $data['showDeactivated'] = $showDeactivated;
        $data['deactivatedCount'] = $showDeactivated ? $total : $this->users->count(true);
        // ?driver_id= preselects a driver, e.g. from the "Create sign-in" link on that driver's page.
        $data['driversWithoutAccount'] = $this->users->driversWithoutAccount();
        $data['pickedDriver'] = (int) filter_var($_GET['driver_id'] ?? 0, FILTER_VALIDATE_INT);
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
