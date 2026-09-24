<?php
declare(strict_types=1);

namespace TripleR\Security;

use TripleR\Repositories\StaffUserRepository;

final class StaffAuth
{
    public function __construct(private readonly StaffUserRepository $users)
    {
    }

    public function user(): ?array
    {
        Csrf::startSession();
        $id = filter_var($_SESSION['staff_user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) {
            return null;
        }
        $user = $this->users->findActiveById((int) $id);
        if ($user === null) {
            unset($_SESSION['staff_user_id']);
        }
        return $user;
    }

    public function login(array $user): void
    {
        Csrf::startSession();
        session_regenerate_id(true);
        unset($_SESSION['_csrf']);
        $_SESSION['staff_user_id'] = (int) $user['id'];
    }

    public function logout(): void
    {
        Csrf::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
