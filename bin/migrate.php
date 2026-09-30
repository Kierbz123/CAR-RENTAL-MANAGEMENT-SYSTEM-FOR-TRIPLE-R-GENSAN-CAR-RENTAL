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
    $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (migration VARCHAR(191) NOT NULL PRIMARY KEY, checksum CHAR(64) CHARACTER SET ascii NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $migrationColumns = $db->query('SHOW COLUMNS FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN, 0);
    if (!in_array('checksum', $migrationColumns, true)) {
        // Existing installations predate checksum tracking. Their current files establish the baseline.
        $db->exec('ALTER TABLE schema_migrations ADD COLUMN checksum CHAR(64) CHARACTER SET ascii NULL AFTER migration');
    }
    $appliedRows = $db->query('SELECT migration, checksum FROM schema_migrations')->fetchAll(\PDO::FETCH_ASSOC);
    $applied = [];
    foreach ($appliedRows as $row) {
        $applied[$row['migration']] = $row['checksum'];
    }

    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [] as $path) {
        $name = basename($path);
        $checksum = hash_file('sha256', $path);
        if ($checksum === false) {
            throw new RuntimeException('Unable to calculate migration checksum: ' . $name);
        }
        if (array_key_exists($name, $applied)) {
            if ($applied[$name] === null) {
                $baseline = $db->prepare('UPDATE schema_migrations SET checksum = :checksum WHERE migration = :migration AND checksum IS NULL');
                $baseline->execute(['checksum' => $checksum, 'migration' => $name]);
                echo "Recorded legacy checksum baseline: {$name}\n";
                continue;
            }
            if (!hash_equals((string) $applied[$name], $checksum)) {
                throw new RuntimeException('Applied migration checksum mismatch: ' . $name . '. Migrations are forward-only; repair the schema manually and add a new migration.');
            }
            echo "Already applied and checksum verified: {$name}\n";
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
        $record = $db->prepare('INSERT INTO schema_migrations (migration, checksum) VALUES (:migration, :checksum)');
        $record->execute(['migration' => $name, 'checksum' => $checksum]);
        echo "Applied: {$name}\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
