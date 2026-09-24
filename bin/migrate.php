<?php
declare(strict_types=1);

use TripleR\Config;
use TripleR\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $storagePath = Config::get('STORAGE_PATH', 'storage') ?? 'storage';
    $storagePath = str_starts_with($storagePath, DIRECTORY_SEPARATOR) ? $storagePath : dirname(__DIR__) . DIRECTORY_SEPARATOR . $storagePath;
    if (!is_dir($storagePath) && !mkdir($storagePath, 0770, true) && !is_dir($storagePath)) {
        throw new RuntimeException('Unable to create the private storage directory.');
    }

    $db = Database::migrationConnection();
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(191) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $applied = $db->query('SELECT migration FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
    $applied = array_fill_keys($applied, true);

    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [] as $path) {
        $name = basename($path);
        if (isset($applied[$name])) {
            echo "Already applied: {$name}\n";
            continue;
        }
        $sql = file($path, FILE_IGNORE_NEW_LINES);
        if ($sql === false) {
            throw new RuntimeException('Unable to read migration: ' . $name);
        }
        $delimiter = ';';
        $buffer = '';
        foreach ($sql as $line) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $matches) === 1) {
                $delimiter = $matches[1];
                continue;
            }
            $buffer .= $line . "\n";
            $trimmed = rtrim($buffer);
            if ($trimmed !== '' && str_ends_with($trimmed, $delimiter)) {
                $statement = trim(substr($trimmed, 0, -strlen($delimiter)));
                if ($statement !== '') {
                    $db->exec($statement);
                }
                $buffer = '';
            }
        }
        if (trim($buffer) !== '') {
            $db->exec(trim($buffer));
        }
        $record = $db->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
        $record->execute(['migration' => $name]);
        echo "Applied: {$name}\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
