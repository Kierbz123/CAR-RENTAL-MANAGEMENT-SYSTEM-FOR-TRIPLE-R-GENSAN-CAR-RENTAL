<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\StaffUserRepository;
use TripleR\Security\Csrf;
use TripleR\Security\StaffAuth;
use TripleR\Services\RateLimiter;

final class AuthController
{
    public function __construct(
        private readonly StaffUserRepository $users,
        private readonly StaffAuth $auth,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public function showLogin(): Response
    {
        if ($this->auth->user() !== null) {
            return Response::redirect('/staff/notifications');
        }
        return $this->render('login.php', ['csrfToken' => Csrf::token(), 'error' => null], 200);
    }

    public function login(Request $request): Response
    {
        if (!Csrf::valid($request)) {
            return $this->render('login.php', ['csrfToken' => Csrf::token(), 'error' => 'Your session expired. Please try again.'], 403);
        }
        $email = mb_strtolower(trim((string) ($request->form['email'] ?? '')));
        $password = (string) ($request->form['password'] ?? '');
        $allowedIp = $this->rateLimiter->allow('staff-login-ip', $request->ip, 20, 60);
        $allowedEmail = $this->rateLimiter->allow('staff-login-email', $email, 5, 60);
        if (!$allowedIp || !$allowedEmail) {
            return $this->render('login.php', ['csrfToken' => Csrf::token(), 'error' => 'Too many attempts. Wait a minute and try again.'], 429);
        }
        $user = $this->users->findActiveByEmail($email);
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return $this->render('login.php', ['csrfToken' => Csrf::token(), 'error' => 'Email or password is incorrect.'], 401);
        }
        if (!in_array($user['role'], ['system_admin', 'fleet_manager'], true)) {
            return $this->render('login.php', ['csrfToken' => Csrf::token(), 'error' => 'This account cannot access the staff console.'], 403);
        }
        $this->auth->login($user);
        return Response::redirect('/staff/notifications');
    }

    public function logout(Request $request): Response
    {
        if (!Csrf::valid($request)) {
            return Response::json(['error' => 'Invalid request token.'], 403);
        }
        $this->auth->logout();
        return Response::redirect('/staff/login');
    }

    private function render(string $view, array $data, int $status): Response
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require APP_ROOT . '/app/Views/staff/' . $view;
        $body = (string) ob_get_clean();
        return Response::html($body, $status);
    }
}
