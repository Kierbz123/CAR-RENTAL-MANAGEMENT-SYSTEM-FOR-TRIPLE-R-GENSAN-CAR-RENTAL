<?php
declare(strict_types=1);

namespace TripleR\Http;

final class Request
{
    private function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $form,
        public readonly string $rawBody,
        public readonly array $headers,
        public readonly string $ip,
        public readonly string $userAgent,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] ??= $value;
            }
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = $path !== '/' ? rtrim($path, '/') : '/';
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            file_get_contents('php://input') ?: '',
            $headers,
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512),
        );
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_scalar($value) ? (string) $value : null;
            }
        }
        return null;
    }

    public function json(): array
    {
        $data = json_decode($this->rawBody, true);
        return is_array($data) ? $data : [];
    }
}
