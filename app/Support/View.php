<?php
declare(strict_types=1);

namespace TripleR\Support;

/**
 * Shared page layouts for PHP views.
 *
 * A view wraps its markup between View::begin() and View::end(); the captured
 * markup is handed to app/Views/layouts/<layout>.php as $content. Controllers
 * keep requiring views exactly as before.
 */
final class View
{
    private static ?array $user = null;
    /** @var list<array{0:string,1:array}> */
    private static array $stack = [];

    /** Called by AuthMiddleware once a staff session is verified, so layouts can render the sidebar. */
    public static function setUser(?array $user): void
    {
        self::$user = $user;
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function begin(string $layout, array $options = []): void
    {
        self::$stack[] = [$layout, $options];
        ob_start();
    }

    public static function end(): void
    {
        [$layout, $options] = array_pop(self::$stack) ?? ['entry', []];
        $content = (string) ob_get_clean();
        self::layout($layout, $content, $options);
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A heading whose words rise into place one after another (.rise in app.css).
     * Returns escaped HTML: each word in its own pair of spans, with plain spaces between.
     */
    public static function rise(string $text): string
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $spans = array_map(static fn (string $word): string => '<span class="w"><span class="w-in">' . self::e($word) . '</span></span>', $words);
        return '<span class="rise">' . implode(' ', $spans) . '</span>';
    }

    public static function partial(string $name, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        require APP_ROOT . '/app/Views/partials/' . $name . '.php';
    }

    /** A complete, styled page for a plain error message (used by Response for 4xx/5xx text bodies). */
    public static function errorPage(int $status, string $message): string
    {
        ob_start();
        require APP_ROOT . '/app/Views/errors/error.php';
        return (string) ob_get_clean();
    }

    public static function currentPath(): string
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return $path !== '/' ? rtrim($path, '/') : '/';
    }

    private static function layout(string $layout, string $content, array $options): void
    {
        $user = self::$user;
        require APP_ROOT . '/app/Views/layouts/' . $layout . '.php';
    }
}
