<?php
declare(strict_types=1);

namespace TripleR\Http;

final class Response
{
    public function __construct(
        private readonly string $body = '',
        private readonly int $status = 200,
        private readonly array $headers = ['Content-Type' => 'text/html; charset=utf-8'],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $status, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location, 'Cache-Control' => 'no-store']);
    }

    public function send(): never
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'");
        echo $this->body;
        exit;
    }
}
