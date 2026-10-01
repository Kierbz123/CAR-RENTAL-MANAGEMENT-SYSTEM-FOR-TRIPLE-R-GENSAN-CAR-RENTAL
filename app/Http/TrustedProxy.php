<?php
declare(strict_types=1);

namespace TripleR\Http;

use TripleR\Config;

/**
 * Reads the visitor's real address and scheme when the app sits behind a tunnel or
 * reverse proxy (Cloudflare Tunnel, ngrok, a load balancer).
 *
 * Forwarded headers are believed only when the connection itself comes from an
 * address listed in TRUSTED_PROXIES (comma-separated, empty by default). With the
 * setting empty, behaviour is exactly as before: the connecting address is the
 * visitor, and HTTPS is whatever the web server reports.
 */
final class TrustedProxy
{
    public static function clientIp(array $server): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? 'unknown');
        $trusted = self::trustedAddresses();
        if (!in_array($remote, $trusted, true)) {
            return $remote;
        }
        // The right-most address that is not one of our own proxies is the one the proxy saw.
        $forwarded = array_reverse(array_map('trim', explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($forwarded as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false && !in_array($candidate, $trusted, true)) {
                return $candidate;
            }
        }
        return $remote;
    }

    public static function isHttps(array $server): bool
    {
        if (!empty($server['HTTPS']) && $server['HTTPS'] !== 'off') {
            return true;
        }
        return in_array((string) ($server['REMOTE_ADDR'] ?? ''), self::trustedAddresses(), true)
            && strtolower(trim((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))) === 'https';
    }

    /** @return list<string> */
    private static function trustedAddresses(): array
    {
        $raw = (string) (Config::get('TRUSTED_PROXIES', '') ?? '');
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $ip): bool => $ip !== ''));
    }
}
