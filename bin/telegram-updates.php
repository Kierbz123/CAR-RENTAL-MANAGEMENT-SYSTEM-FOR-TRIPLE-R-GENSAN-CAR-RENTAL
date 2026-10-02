<?php
/**
 * Reads what customers send to the Telegram bot (Start with a code, /stop, /help) and applies it.
 *
 * Polling needs only an internet connection, not a public address, so it works from a laptop.
 *
 *   php bin/telegram-updates.php          keep listening until Ctrl+C
 *   php bin/telegram-updates.php --once   read what is waiting now, then exit
 *
 * Safe to stop and restart at any time: every update is recorded by Telegram's own number and
 * applied once. Run one copy only; Telegram refuses a second listener for the same bot.
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\TelegramLinkRepository;
use TripleR\Services\Telegram\TelegramApiException;
use TripleR\Services\Telegram\TelegramBotClient;
use TripleR\Services\TelegramLinkService;
use TripleR\Services\TelegramUpdateHandler;

require dirname(__DIR__) . '/app/bootstrap.php';

$once = in_array('--once', array_slice($argv, 1), true);

if (!TelegramBotClient::isConfigured()) {
    fwrite(STDERR, 'Telegram is not set up. Put TELEGRAM_BOT_TOKEN and TELEGRAM_BOT_USERNAME in .env first.' . PHP_EOL);
    exit(1);
}

try {
    $db = Database::connection();
    $client = new TelegramBotClient();
    $handler = new TelegramUpdateHandler(new TelegramLinkRepository($db), TelegramLinkService::create($db), $client);
    $bot = $client->getMe();
} catch (TelegramApiException $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    error_log('Telegram listener could not start: ' . get_class($error));
    fwrite(STDERR, 'The Telegram listener could not start. Check application logs and database configuration.' . PHP_EOL);
    exit(1);
}

$configured = TelegramBotClient::botUsername();
if (strcasecmp($bot['username'], (string) $configured) !== 0) {
    fwrite(STDERR, 'TELEGRAM_BOT_USERNAME is "' . $configured . '" but this token belongs to @' . $bot['username'] . '. Correct .env so the connection link opens the right bot.' . PHP_EOL);
    exit(1);
}
echo 'Listening for messages to @' . $bot['username'] . ($once ? ' (once).' : '. Press Ctrl+C to stop.') . PHP_EOL;

$offset = null; // First request: everything Telegram has not yet been told we have.
$failures = 0;
while (true) {
    try {
        $updates = $client->getUpdates($offset, $once ? 0 : 25);
        $failures = 0;
    } catch (TelegramApiException $error) {
        fwrite(STDERR, gmdate('c') . ' ' . $error->getMessage() . PHP_EOL);
        if ($once || (!$error->isTemporary() && $error->reason !== 'conflict')) {
            exit(1);
        }
        sleep(min(60, 5 * ++$failures));
        continue;
    }
    foreach ($updates as $update) {
        $id = is_int($update['update_id'] ?? null) ? $update['update_id'] : null;
        try {
            $outcome = $handler->handle($update);
            echo gmdate('c') . ' update ' . ($id ?? '?') . ': ' . $outcome . PHP_EOL;
        } catch (Throwable $error) {
            // Left unconfirmed, so Telegram offers it again on the next request.
            error_log('Telegram update could not be applied: ' . get_class($error));
            fwrite(STDERR, gmdate('c') . ' update ' . ($id ?? '?') . ' could not be applied; it will be retried.' . PHP_EOL);
            if ($once) {
                exit(1);
            }
            sleep(5);
            continue 2;
        }
        if ($id !== null) {
            $offset = $id + 1;
        }
    }
    if ($once) {
        if ($updates !== [] && $offset !== null) {
            $client->getUpdates($offset, 0, 1); // Tell Telegram these were received.
        }
        exit(0);
    }
}
