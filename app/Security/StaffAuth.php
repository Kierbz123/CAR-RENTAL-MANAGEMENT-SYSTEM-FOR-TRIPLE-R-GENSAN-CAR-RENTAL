<?php
declare(strict_types=1);

namespace TripleR\Security;

use TripleR\Services\AuthService;

final class StaffAuth
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function user(): ?array
    {
        return $this->auth->currentUser();
    }

    public function login(array $user): void
    {
        $this->auth->establishVerifiedSession($user);
    }

    public function logout(string $ip = '', string $userAgent = ''): void
    {
        $this->auth->logout($ip, $userAgent);
    }
}
