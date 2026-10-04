<?php
/**
 * Recomputes the history tables and compares them with every seal bin/audit-seal.php recorded.
 * Exits 0 when everything matches and 1, naming each seal, when a sealed row was changed,
 * removed or added out of order.
 *   php bin/audit-verify.php
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Services\AuditSeal;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $problems = (new AuditSeal(Database::connection()))->verifyAll();
    if ($problems === []) {
        echo "All sealed history matches.\n";
        exit(0);
    }
    fwrite(STDERR, "Sealed history has changed:\n  " . implode("\n  ", $problems) . "\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, 'Verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(2);
}
