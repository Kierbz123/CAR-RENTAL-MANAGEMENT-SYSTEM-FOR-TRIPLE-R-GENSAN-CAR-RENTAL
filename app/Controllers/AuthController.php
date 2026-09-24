<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Services\AuthService;
use TripleR\Security\Csrf;
use TripleR\Security\StaffAuth;

final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly StaffAuth $auth,
    ) {
    }

    public function showLogin(): Response
    {
        $user = $this->auth->user();
        if ($user !== null) {
            if ($user['must_change_password']) {
                return Response::redirect('/auth/change-password');
            }
            return Response::redirect('/staff');
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
        $result = $this->authService->login($email, $password, $request->ip, $request->userAgent);
        if ($result === 'throttled') {
            return $this->render('login.php', ['csrfToken' => Csrf::token(), 'error' => 'Too many attempts. Wait a minute and try again.'], 429);
        }
        if ($result === 'failed') {
            return $this->render('login.php', ['csrfToken' => Csrf::token(), 'error' => 'Email or password is incorrect.'], 401);
        }
        if ($result === 'password_change_required') {
            return Response::redirect('/auth/change-password');
        }
        return Response::redirect('/staff');
    }

    public function showChangePassword(): Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/staff/login');
        }
        if (!$user['must_change_password']) {
            return Response::redirect('/staff');
        }
        return $this->render('change-password.php', ['csrfToken' => Csrf::token(), 'error' => null], 200);
    }

    public function changePassword(Request $request): Response
    {
        if (!Csrf::valid($request)) {
            return $this->render('change-password.php', ['csrfToken' => Csrf::token(), 'error' => 'Your session expired. Please try again.'], 403);
        }
        $user = $this->auth->user();
        if ($user === null) {
            return Response::redirect('/staff/login');
        }
        $current = (string) ($request->form['current_password'] ?? '');
        $password = (string) ($request->form['new_password'] ?? '');
        $confirmation = (string) ($request->form['confirm_password'] ?? '');
        if ($password !== $confirmation) {
            return $this->render('change-password.php', ['csrfToken' => Csrf::token(), 'error' => 'The new passwords do not match.'], 422);
        }
        if (!$this->authService->changePassword((int) $user['id'], $current, $password, $request->ip, $request->userAgent)) {
            return $this->render('change-password.php', ['csrfToken' => Csrf::token(), 'error' => 'The current password is incorrect or the new password does not meet the requirements.'], 422);
        }
        return Response::redirect('/staff');
    }

    public function logout(Request $request): Response
    {
        if (!Csrf::valid($request)) {
            return Response::json(['error' => 'Invalid request token.'], 403);
        }
        $this->auth->logout($request->ip, $request->userAgent);
        return Response::redirect('/staff/login');
    }

    private function render(string $view, array $data, int $status): Response
    {
        extract($data, EXTR_SKIP);
        ob_start();
        require APP_ROOT . '/app/Views/auth/' . $view;
        $body = (string) ob_get_clean();
        return Response::html($body, $status);
    }
}
