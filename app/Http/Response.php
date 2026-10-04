<?php
declare(strict_types=1);

namespace TripleR\Http;

use TripleR\Support\View;

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
        // Controllers return short plain-text messages for errors ("Vehicle not found.").
        // Give those the shared error page instead of a bare line of text.
        if ($status >= 400 && !str_contains($body, '<html')) {
            $message = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($status >= 500) {
                // Server faults can carry database or file details. Keep those in the log, not on the page.
                error_log('Request failed with status ' . $status . ': ' . $message);
                $message = 'Something went wrong on our side and the page could not be loaded. Please try again in a moment.';
            }
            $body = View::errorPage($status, $message);
        }
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $status, ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function binary(string $body, string $mime): self
    {
        $allowed=['image/jpeg','image/png','image/webp'];if(!in_array($mime,$allowed,true))return self::html('Unsupported file type.',415);
        return new self($body,200,['Content-Type'=>$mime,'Cache-Control'=>'private, no-store','Content-Disposition'=>'inline']);
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location, 'Cache-Control' => 'no-store']);
    }

    /**
     * The same response, with pictures also allowed from one named outside server. Used by the
     * live map for its map tiles, on that page only; scripts and styles stay "this site only".
     */
    public function withImagesFrom(string $origin): self
    {
        if (preg_match('#^https://[a-z0-9.-]+$#', $origin) !== 1) {
            throw new \InvalidArgumentException('Images can only be allowed from a plain https origin.');
        }
        return new self($this->body, $this->status, $this->headers + ['X-Image-Origin' => $origin]);
    }

    public function send(): never
    {
        http_response_code($this->status);
        $imageOrigin = $this->headers['X-Image-Origin'] ?? null;
        foreach ($this->headers as $name => $value) {
            if ($name !== 'X-Image-Origin') {
                header($name . ': ' . $value);
            }
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        // No page uses inline scripts or inline style attributes, so neither is allowed.
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; " . ($imageOrigin === null ? '' : "img-src 'self' " . $imageOrigin . '; ') . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        echo $this->body;
        exit;
    }
}
