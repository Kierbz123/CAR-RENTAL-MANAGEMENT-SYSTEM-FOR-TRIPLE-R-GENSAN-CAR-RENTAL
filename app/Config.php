<?php
declare(strict_types=1);

namespace TripleR;

final class Config
{
    private static array $values = [];

    public static function load(string $projectRoot): void
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . '.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                if ($value !== '' && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                    $value = substr($value, 1, -1);
                }
                if (getenv($name) === false) {
                    putenv($name . '=' . $value);
                    $_ENV[$name] = $value;
                }
            }
        }
        self::$values = [];
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        if (!array_key_exists($name, self::$values)) {
            $value = getenv($name);
            self::$values[$name] = $value === false ? $default : $value;
        }
        return self::$values[$name];
    }

    public static function require(string $name): string
    {
        $value = self::get($name);
        if ($value === null || $value === '') {
            throw new \RuntimeException('Missing required configuration: ' . $name);
        }
        return $value;
    }

    public static function int(string $name, int $default): int
    {
        $value = self::get($name);
        return $value === null || $value === '' ? $default : max(0, (int) $value);
    }
}
