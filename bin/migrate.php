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

    // MySQL DDL commits as it goes, so a migration that fails halfway cannot be rolled back.
    // Everything a pending migration needs is therefore checked before the first one starts.
    $pending = [];
    foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') ?: [] as $path) {
        if (!array_key_exists(basename($path), $applied)) {
            $pending[basename($path)] = (string) file_get_contents($path);
        }
    }
    if ($pending !== []) {
        $schema = (string) $db->query('SELECT DATABASE()')->fetchColumn();
        $grants = migrationGrants($db, $schema);
        $binlog = $db->query('SELECT @@log_bin AS log_bin, @@log_bin_trust_function_creators AS trusted')->fetch(\PDO::FETCH_ASSOC);
        foreach ($pending as $name => $sql) {
            if (preg_match('/\bCREATE\s+TRIGGER\b/i', $sql) === 1 && (int) $binlog['log_bin'] === 1
                && (int) $binlog['trusted'] !== 1 && !in_array('SUPER', $grants['global'], true)) {
                throw new RuntimeException($name . ' creates triggers, and this MySQL server has binary logging on. '
                    . 'Ask the database administrator to run "SET PERSIST log_bin_trust_function_creators = 1;" once, then run this again. Nothing was changed.');
            }
            foreach (['DELETE' => '/\bDELETE\s+FROM\b/i', 'DROP' => '/\bDROP\s+TABLE\b/i', 'TRIGGER' => '/\b(CREATE|DROP)\s+TRIGGER\b/i', 'ALTER' => '/\bALTER\s+TABLE\b/i'] as $privilege => $pattern) {
                if (preg_match($pattern, $sql) === 1 && !in_array($privilege, $grants['schema'], true)) {
                    throw new RuntimeException($name . ' needs the ' . $privilege . ' privilege on ' . $schema . ', which the migration account does not have. '
                        . 'Grant it (see README, "First run"), then run this again. Nothing was changed.');
                }
            }
            // "-- precheck: SELECT ..." lines name data that would make the migration fail. Each must return no rows.
            preg_match_all('/^--\s*precheck:\s*(.+)$/mi', $sql, $prechecks);
            foreach ($prechecks[1] as $query) {
                try {
                    $problems = $db->query(rtrim(trim($query), ';'))->fetchAll(\PDO::FETCH_COLUMN, 0);
                } catch (\PDOException $missing) {
                    // A table an earlier pending migration creates holds no data yet, so there is nothing to correct.
                    if (($missing->errorInfo[1] ?? 0) === 1146) {
                        continue;
                    }
                    throw $missing;
                }
                if ($problems !== []) {
                    throw new RuntimeException($name . ' cannot be applied until this data is corrected: ' . implode('; ', array_slice($problems, 0, 10))
                        . (count($problems) > 10 ? ' (and ' . (count($problems) - 10) . ' more)' : '') . '. Nothing was changed.');
                }
            }
        }
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

/**
 * The privileges the migration account holds, from SHOW GRANTS: 'global' (ON *.*) and
 * 'schema' (ON *.* or ON `schema`.*). ALL PRIVILEGES counts as every privilege.
 *
 * @return array{global:list<string>,schema:list<string>}
 */
function migrationGrants(\PDO $db, string $schema): array
{
    $every = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'TRIGGER', 'REFERENCES', 'SUPER'];
    $result = ['global' => [], 'schema' => []];
    foreach ($db->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(\PDO::FETCH_COLUMN, 0) as $line) {
        if (preg_match('/^GRANT (.+?) ON (\S+) TO /i', (string) $line, $match) !== 1) {
            continue;
        }
        $target = str_replace(['`', '\\'], '', $match[2]);
        $privileges = strtoupper($match[1]) === 'ALL PRIVILEGES' ? $every : array_map(static fn (string $p): string => strtoupper(trim($p)), explode(',', $match[1]));
        if ($target === '*.*') {
            $result['global'] = array_merge($result['global'], $privileges);
            $result['schema'] = array_merge($result['schema'], $privileges);
        } elseif (strcasecmp($target, $schema . '.*') === 0) {
            $result['schema'] = array_merge($result['schema'], $privileges);
        }
    }
    return ['global' => array_values(array_unique($result['global'])), 'schema' => array_values(array_unique($result['schema']))];
}
