<?php
declare(strict_types=1);

namespace TripleR\Http;

use TripleR\Security\StaffAuth;

final class AuthMiddleware
{
    public function __construct(private readonly StaffAuth $auth)
    {
    }

    public function requireRoles(array $roles, bool $api = false): array|Response
    {
        $user = $this->requireAuthenticated($api);
        if ($user instanceof Response) {
            return $user;
        }
        if (!in_array($user['role'], $roles, true)) {
            return $api
                ? Response::json(['error' => 'This account is not authorized for this action.'], 403)
                : Response::html('Forbidden', 403);
        }
        return $user;
    }

    public function requireAuthenticated(bool $api = false, bool $allowPasswordChange = false): array|Response
    {
        $user = $this->auth->user();
        if ($user === null) {
            return $api ? Response::json(['error' => 'Authentication required.'], 401) : Response::redirect('/staff/login');
        }
        if ($user['must_change_password'] && !$allowPasswordChange) {
            return $api
                ? Response::json(['code' => 'password_change_required', 'error' => 'A password change is required before continuing.'], 403)
                : Response::redirect('/auth/change-password');
        }
        return $user;
    }
}
