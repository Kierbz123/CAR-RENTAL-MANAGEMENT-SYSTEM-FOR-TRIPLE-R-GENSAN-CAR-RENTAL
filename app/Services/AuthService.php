<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDO;
use Throwable;
use TripleR\Config;
use TripleR\Repositories\SecurityLogRepository;
use TripleR\Repositories\SessionRepository;
use TripleR\Repositories\StaffUserRepository;
use TripleR\Security\Csrf;

final class AuthService
{
    public function __construct(
        private readonly PDO $db,
        private readonly StaffUserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly SecurityLogRepository $securityLogs,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function login(string $email, string $password, string $ip, string $userAgent): string
    {
        $email = mb_strtolower(trim($email));
        $allowedIp = $this->rateLimiter->allow('staff-login-ip', $ip, 20, 60);
        $allowedEmail = $this->rateLimiter->allow('staff-login-email', $email, 5, 60);
        if (!$allowedIp || !$allowedEmail) {
            $this->securityLogs->append('login_throttled', null, $email, $ip, $userAgent);
            return 'throttled';
        }

        $this->db->beginTransaction();
        try {
            $user = $this->users->findActiveByEmailForUpdate($email);
            if ($user === null) {
                $this->securityLogs->append('login_failed', null, $email, $ip, $userAgent);
                $this->db->commit();
                return 'failed';
            }
            $userId = (int) $user['id'];
            if ($user['locked_at'] !== null) {
                $this->securityLogs->append('login_locked', $userId, $email, $ip, $userAgent);
                $this->db->commit();
                return 'failed';
            }
            if (!password_verify($password, (string) $user['password_hash'])) {
                $threshold = max(1, Config::int('AUTH_MAX_FAILED_LOGINS', 5));
                $becameLocked = $this->users->recordFailedLogin($userId, $threshold);
                $this->securityLogs->append('login_failed', $userId, $email, $ip, $userAgent);
                if ($becameLocked) {
                    $this->sessions->invalidateAllForUser($userId);
                    $this->securityLogs->append('account_locked', $userId, $email, $ip, $userAgent);
                }
                $this->db->commit();
                return 'failed';
            }

            Csrf::startSession();
            session_regenerate_id(true);
            unset($_SESSION['_csrf']);
            $this->users->clearFailedLogins($userId);
            $this->sessions->create(
                $userId,
                session_id(),
                Config::int('AUTH_SESSION_TTL_SECONDS', 43200),
                $ip,
                $userAgent,
            );
            $_SESSION['staff_user_id'] = $userId;
            $this->securityLogs->append('login_succeeded', $userId, $email, $ip, $userAgent);
            $this->db->commit();
            return (bool) $user['must_change_password'] ? 'password_change_required' : 'authenticated';
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function currentUser(): ?array
    {
        Csrf::startSession();
        $sessionUserId = filter_var($_SESSION['staff_user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$sessionUserId) {
            return null;
        }
        $user = $this->sessions->findCurrent(session_id());
        if ($user === null || (int) $user['id'] !== (int) $sessionUserId) {
            unset($_SESSION['staff_user_id'], $_SESSION['_csrf']);
            return null;
        }
        return $user;
    }

    public function establishVerifiedSession(array $verifiedUser, string $ip = '', string $userAgent = ''): void
    {
        $userId = (int) ($verifiedUser['id'] ?? 0);
        if ($userId <= 0) {
            throw new \InvalidArgumentException('A verified user is required to establish a session.');
        }
        Csrf::startSession();
        $this->db->beginTransaction();
        try {
            $user = $this->users->findByIdForUpdate($userId);
            if ($user === null || $user['locked_at'] !== null) {
                throw new \DomainException('This account cannot start a session.');
            }
            session_regenerate_id(true);
            unset($_SESSION['_csrf']);
            $this->users->clearFailedLogins($userId);
            $this->sessions->create($userId, session_id(), Config::int('AUTH_SESSION_TTL_SECONDS', 43200), $ip, $userAgent);
            $_SESSION['staff_user_id'] = $userId;
            $this->securityLogs->append('login_succeeded', $userId, (string) $user['email'], $ip, $userAgent);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function logout(string $ip = '', string $userAgent = ''): void
    {
        Csrf::startSession();
        $userId = filter_var($_SESSION['staff_user_id'] ?? null, FILTER_VALIDATE_INT);
        $this->db->beginTransaction();
        try {
            $this->sessions->invalidateCurrent(session_id());
            if ($userId) {
                $this->securityLogs->append('logout', (int) $userId, null, $ip, $userAgent);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword, string $ip, string $userAgent): bool
    {
        if (mb_strlen($newPassword, 'UTF-8') < 14 || mb_strlen($newPassword, 'UTF-8') > 200 || hash_equals($currentPassword, $newPassword)) {
            return false;
        }
        $this->db->beginTransaction();
        try {
            $user = $this->users->findByIdForUpdate($userId);
            if ($user === null || $user['locked_at'] !== null || !password_verify($currentPassword, (string) $user['password_hash'])) {
                $this->securityLogs->append('password_change_failed', $userId, null, $ip, $userAgent);
                $this->db->commit();
                return false;
            }
            if (!$this->users->changePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT))) {
                $this->db->rollBack();
                return false;
            }
            $this->sessions->invalidateOthers($userId, session_id());
            $this->securityLogs->append('password_changed', $userId, (string) $user['email'], $ip, $userAgent);
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }
}
