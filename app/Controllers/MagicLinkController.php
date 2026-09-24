<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use TripleR\Config;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Services\MagicLinkService;

final class MagicLinkController
{
    public function __construct(private readonly MagicLinkService $magicLinks)
    {
    }

    public function page(): Response
    {
        ob_start();
        require APP_ROOT . '/app/Views/magic-link.php';
        return Response::html((string) ob_get_clean());
    }

    public function redeem(Request $request): Response
    {
        $contentType = strtolower($request->header('Content-Type') ?? '');
        $mediaType = trim(explode(';', $contentType, 2)[0]);
        if ($mediaType !== 'application/json' || !$this->isSameOrigin($request->header('Origin'))) {
            return Response::json(['error' => 'This secure-link request could not be validated.'], 403);
        }
        if (array_key_exists('token', $request->query)) {
            $this->magicLinks->redeem('', '', $request->ip, $request->userAgent);
            return Response::json(['error' => 'The token must be submitted in the POST body.'], 400);
        }
        if (strlen($request->rawBody) > 2048) {
            $this->magicLinks->redeem('', '', $request->ip, $request->userAgent);
            return Response::json(['error' => 'This link is invalid, expired, already used, or intended for another action.'], 400);
        }
        $payload = $request->json();
        $token = is_string($payload['token'] ?? null) ? substr($payload['token'], 0, 64) : '';
        $purpose = is_string($payload['purpose'] ?? null) ? substr($payload['purpose'], 0, 64) : '';
        $context = $this->magicLinks->redeem($token, $purpose, $request->ip, $request->userAgent);
        if ($context === null) {
            return Response::json(['error' => 'This link is invalid, expired, already used, or intended for another action.'], 400);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['magic_link_context'] = [
            'token_id' => $context['id'],
            'purpose' => $context['purpose'],
            'booking_id' => $context['booking_id'],
            'expires_at' => $context['expires_at'],
            'redeemed_at' => gmdate('c'),
        ];
        return Response::json(['verified' => true, 'purpose' => $context['purpose']]);
    }

    public function sessionContext(Request $request): Response
    {
        $purpose = is_scalar($request->query['purpose'] ?? null) ? (string) $request->query['purpose'] : '';
        try {
            $context = $this->magicLinks->currentSessionContext($purpose);
        } catch (\InvalidArgumentException) {
            $context = null;
        }
        if ($context === null) {
            return Response::json(['error' => 'Secure-link access is unavailable or expired.'], 401);
        }
        return Response::json(['verified' => true, 'purpose' => $context['purpose']]);
    }

    private function isSameOrigin(?string $origin): bool
    {
        if ($origin === null) {
            return false;
        }
        $configured = parse_url(Config::require('APP_BASE_URL'));
        $provided = parse_url($origin);
        if (!is_array($configured) || !is_array($provided) || !isset($configured['scheme'], $configured['host'], $provided['scheme'], $provided['host'])
            || isset($provided['path']) || isset($provided['query']) || isset($provided['fragment']) || isset($provided['user']) || isset($provided['pass'])) {
            return false;
        }
        $configuredOrigin = self::origin($configured);
        $providedOrigin = self::origin($provided);
        return hash_equals($configuredOrigin, $providedOrigin);
    }

    private static function origin(array $parts): string
    {
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }
        return $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
    }
}
