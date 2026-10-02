<?php
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\InboundSmsEventRepository;
use TripleR\Repositories\NotificationRepository;
use TripleR\Repositories\RulesAcceptanceRepository;
use TripleR\Services\NotificationService;
use TripleR\Services\SmsMessageCipher;
use TripleR\Services\TelegramLinkService;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $db = Database::connection();
    $service = new NotificationService(
        new NotificationRepository($db),
        new InboundSmsEventRepository($db),
        new SmsMessageCipher(),
        new RulesAcceptanceRepository($db),
        TelegramLinkService::create($db),
    );
} catch (Throwable $error) {
    error_log('Notification worker failed: ' . get_class($error));
    fwrite(STDERR, 'Notification worker could not start. Check application logs and database configuration.' . PHP_EOL);
    exit(1);
}

$runBatch = static function () use ($service): ?array {
    try {
        return $service->processBatch(25);
    } catch (Throwable $error) {
        error_log('Notification worker failed: ' . get_class($error));
        fwrite(STDERR, 'Notification worker could not complete. Check application logs and database configuration.' . PHP_EOL);
        return null;
    }
};

// Default: send one batch and exit (for a scheduler). With --watch[=seconds] the worker stays
// running and sends as messages are queued, which is what a presentation needs.
$watch = null;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--watch(?:=(\d{1,3}))?$/', $argument, $match) === 1) {
        $watch = max(1, (int) ($match[1] ?? 3));
    }
}
if ($watch === null) {
    $result = $runBatch();
    if ($result === null) {
        exit(1);
    }
    echo json_encode(['at_utc' => gmdate('c'), ...$result], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}
echo 'Sending queued notifications every ' . $watch . ' second(s). Press Ctrl+C to stop.' . PHP_EOL;
while (true) {
    $result = $runBatch();
    if ($result !== null && $result['claimed'] > 0) {
        echo json_encode(['at_utc' => gmdate('c'), ...$result], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }
    sleep($watch);
}
