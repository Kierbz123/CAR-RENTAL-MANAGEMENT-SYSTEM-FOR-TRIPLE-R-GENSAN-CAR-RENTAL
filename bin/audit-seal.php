<?php
/**
 * Seals the history tables as they are now (see app/Services/AuditSeal.php). Run it daily, for
 * example from cron after the other jobs, and keep its output somewhere the database accounts
 * cannot reach: those lines are what proves the seals themselves were not rewritten.
 *   php bin/audit-seal.php
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Services\AuditSeal;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    foreach ((new AuditSeal(Database::connection()))->sealAll() as $seal) {
        echo gmdate('c') . " sealed {$seal['table']} rows={$seal['row_count']} through={$seal['last_row_id']} sha256={$seal['chain_hash']}\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Sealing failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
