<?php
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\InboundSmsEventRepository;
use TripleR\Repositories\NotificationRepository;
use TripleR\Services\NotificationService;
use TripleR\Services\SmsMessageCipher;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $db = Database::connection();
    $service = new NotificationService(
        new NotificationRepository($db),
        new InboundSmsEventRepository($db),
        new SmsMessageCipher(),
    );
    $result = $service->processBatch(25);
    echo json_encode(['at_utc' => gmdate('c'), ...$result], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    error_log('Notification worker failed: ' . get_class($error));
    fwrite(STDERR, 'Notification worker could not complete. Check application logs and database configuration.' . PHP_EOL);
    exit(1);
}
