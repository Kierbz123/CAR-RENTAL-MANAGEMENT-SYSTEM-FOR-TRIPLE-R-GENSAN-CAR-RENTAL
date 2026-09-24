<?php
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\SessionRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $count = (new SessionRepository(Database::connection()))->sweepExpired();
    echo json_encode(['at_utc' => gmdate('c'), 'sessions_invalidated' => $count], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Session sweep failed: ' . get_class($error) . PHP_EOL);
    exit(1);
}
