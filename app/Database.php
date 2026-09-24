<?php
declare(strict_types=1);

namespace TripleR;

use PDO;

final class Database
{
    private static ?PDO $connection = null;
    private static ?PDO $migrationConnection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            self::$connection = self::connect(Config::require('DB_USER'), Config::require('DB_PASSWORD'));
        }
        return self::$connection;
    }

    public static function migrationConnection(): PDO
    {
        if (self::$migrationConnection === null) {
            self::$migrationConnection = self::connect(Config::require('DB_MIGRATION_USER'), Config::require('DB_MIGRATION_PASSWORD'));
        }
        return self::$migrationConnection;
    }

    private static function connect(string $user, string $password): PDO
    {
        $host = Config::require('DB_HOST');
        $port = Config::get('DB_PORT', '3306');
        $name = Config::require('DB_NAME');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
        $connection = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $connection->exec("SET time_zone = '+00:00'");
        return $connection;
    }
}
