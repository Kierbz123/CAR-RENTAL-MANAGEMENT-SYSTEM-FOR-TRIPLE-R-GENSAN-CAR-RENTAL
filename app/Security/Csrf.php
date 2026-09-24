<?php
declare(strict_types=1);

namespace TripleR\Security;

use TripleR\Http\Request;

final class Csrf
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('triple_r_staff');
        session_set_cookie_params([
            'httponly' => true,
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }

    public static function token(): string
    {
        self::startSession();
        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION['_csrf'];
    }

    public static function valid(Request $request): bool
    {
        self::startSession();
        $sent = $request->header('X-CSRF-Token') ?? (string) ($request->form['_csrf'] ?? '');
        return isset($_SESSION['_csrf']) && $sent !== '' && hash_equals((string) $_SESSION['_csrf'], $sent);
    }
}
