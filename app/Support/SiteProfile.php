<?php
declare(strict_types=1);

namespace TripleR\Support;

/** Reads the public business details from config/site.php (demo placeholders live there, nowhere else). */
final class SiteProfile
{
    private static ?array $data = null;

    public static function all(): array
    {
        return self::$data ??= require APP_ROOT . '/config/site.php';
    }

    public static function get(string $path, mixed $default = null): mixed
    {
        $value = self::all();
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }
            $value = $value[$key];
        }
        return $value;
    }
}
