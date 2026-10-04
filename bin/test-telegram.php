<?php
/**
 * Telegram notifications: end-to-end checks against a stand-in for Telegram's Bot API
 * (bin/support/telegram-stub.php), so no network and no real bot are needed.
 *
 * Covers: connecting with a one-time code, refused codes, guessing limits, repeated updates,
 * /stop, relinking, staff disconnect, channel choice when queueing, sending, retry after a
 * rate limit, a blocked bot ending the connection and falling back, the database's own guards,
 * and the polling worker.
 *
 * Writes test customers and notifications; run it against a test database, never a live one.
 *   php bin/test-telegram.php
 */
declare(strict_types=1);

use TripleR\Config;
use TripleR\Database;
use TripleR\Repositories\CustomerRepository;
use TripleR\Repositories\InboundSmsEventRepository;
use TripleR\Repositories\MagicLinkRepository;
use TripleR\Repositories\NotificationRepository;
use TripleR\Repositories\RulesAcceptanceRepository;
use TripleR\Repositories\TelegramLinkRepository;
use TripleR\Services\CustomerPiiCipher;
use TripleR\Services\CustomerService;
use TripleR\Services\MagicLinkService;
use TripleR\Services\NotificationService;
use TripleR\Services\RateLimiter;
use TripleR\Services\Sms\SmsProviderFactory;
use TripleR\Services\SmsMessageCipher;
use TripleR\Services\Telegram\TelegramApiException;
use TripleR\Services\Telegram\TelegramBotClient;
use TripleR\Services\TelegramLinkService;
use TripleR\Services\TelegramUpdateHandler;

/* ------------------------------ the stand-in ------------------------------ */
$probe = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$stubToken = '100000001:TEST-' . bin2hex(random_bytes(16)); // Only ever valid for the stand-in.
$statePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'triple-r-telegram-stub-' . bin2hex(random_bytes(6)) . '.json';
file_put_contents($statePath, json_encode(['token' => $stubToken, 'username' => 'TripleRTestBot']));

// Set before bootstrap so these win over .env for this process and the workers it starts.
putenv('TELEGRAM_BOT_TOKEN=' . $stubToken);
putenv('TELEGRAM_BOT_USERNAME=TripleRTestBot');
putenv('TELEGRAM_API_BASE=http://127.0.0.1:' . $port);
putenv('TELEGRAM_STUB_STATE=' . $statePath);
putenv('SMS_PROVIDER=semaphore');
putenv('SMS_SEMAPHORE_API_KEY='); // No SMS provider: nothing in this test may reach a real one.
putenv('SMS_PHILSMS_API_TOKEN=');
putenv('SMS_DAILY_LIMIT_PER_PHONE=10');

require dirname(__DIR__) . '/app/bootstrap.php';

if (SmsProviderFactory::smsConfigured()) {
    // On Windows an empty putenv() removes the variable, so a key in .env would still be read.
    fwrite(STDERR, 'This test will not run while an SMS provider key is configured: it must never reach a real provider.' . PHP_EOL);
    exit(2);
}

$stub = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/support/telegram-stub.php'], [['pipe', 'r'], ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $stubPipes);
register_shutdown_function(static function () use (&$stub, $statePath): void {
    if (is_resource($stub)) {
        proc_terminate($stub);
        proc_close($stub);
    }
    @unlink($statePath);
});
for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($socket !== false) {
        fclose($socket);
        break;
    }
    usleep(100000);
}

$passed = 0;
$failed = 0;
function check(bool $condition, string $label, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}" . ($detail === '' ? '' : " ({$detail})") . "\n";
}
function section(string $title): void
{
    echo "\n== {$title} ==\n";
}
function stubState(): array
{
    global $statePath;
    return json_decode((string) file_get_contents($statePath), true) ?: [];
}
function stubSet(string $key, mixed $value): void
{
    global $statePath;
    $state = stubState();
    $state[$key] = $value;
    file_put_contents($statePath, json_encode($state), LOCK_EX);
}
/** Messages the stand-in accepted for one chat. */
function sentTo(int $chat): array
{
    return array_values(array_filter(stubState()['sent'] ?? [], static fn (array $m): bool => $m['chat_id'] === (string) $chat));
}
function lastTextTo(int $chat): string
{
    $messages = sentTo($chat);
    return $messages === [] ? '' : (string) end($messages)['text'];
}

$db = Database::connection();
$cipher = new CustomerPiiCipher();
$customers = new CustomerRepository($db);
$customerService = new CustomerService($db, $customers, $cipher);
$linkRepository = new TelegramLinkRepository($db);
$links = TelegramLinkService::create($db);
$client = new TelegramBotClient();
$handler = new TelegramUpdateHandler($linkRepository, $links, $client);
$notificationRepository = new NotificationRepository($db);
$notifications = new NotificationService($notificationRepository, new InboundSmsEventRepository($db), new SmsMessageCipher(), new RulesAcceptanceRepository($db), $links);
$magicLinks = new MagicLinkService(new MagicLinkRepository($db), new RateLimiter($db), $notifications);

$run = bin2hex(random_bytes(4));
$staffId = (int) $db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$chatBase = random_int(1_000_000_000, 8_000_000_000) * 100;
$chat = static fn (int $n): int => $chatBase + $n; // Fresh chat ids each run, so guess limits start clean.
$updateId = random_int(1_000_000_000, 2_000_000_000);
$message = static function (int $chatId, string $text, string $type = 'private') use (&$updateId): array {
    return ['update_id' => ++$updateId, 'message' => ['message_id' => 1, 'chat' => ['id' => $chatId, 'type' => $type], 'text' => $text]];
};
$makeCustomer = static function (string $label) use ($customers, $customerService, $run): array {
    $id = $customers->create(['customer_type' => 'walk_in', 'full_name' => 'Telegram Test ' . $label . ' ' . $run, 'company_name' => null, 'referral_source' => null]);
    $phone = '09' . str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);
    $customerService->addContact($id, 'phone', $phone, true);
    return [$id, $phone];
};
$row = static function (int $id) use ($db): array {
    $statement = $db->prepare('SELECT * FROM notifications WHERE id = :id');
    $statement->execute(['id' => $id]);
    return $statement->fetch();
};
$activeLink = static fn (int $customerId): ?array => $linkRepository->activeForCustomer($customerId);
$connect = static function (int $customerId, int $chatId) use ($links, $handler, $message, $staffId): string {
    return $handler->handle($message($chatId, '/start ' . $links->createCode($customerId, $staffId)['code']));
};
$rejects = static function (callable $work, string $needle, string $label): void {
    try {
        $work();
        check(false, $label, 'the statement was accepted');
    } catch (PDOException $error) {
        check(str_contains(strtolower($error->getMessage()), strtolower($needle)), $label, $error->getMessage());
    }
};

echo "Telegram notifications acceptance (run {$run})\n";
check($links->isConfigured(), 'Telegram counts as set up once a token and bot username are configured');
[$customerA, $phoneA] = $makeCustomer('A');
[$customerB, $phoneB] = $makeCustomer('B');

/* ---------------------------------------------------------------------- */
section('A customer who has not connected still takes the SMS route');
$smsId = $notifications->enqueue($phoneA, 'rental.confirmed', 'Your rental is confirmed.', 'transactional', 'normal', "tg-{$run}-unconnected", false, $customerA);
$sms = $row($smsId);
check($sms['channel'] === 'sms' && $sms['telegram_link_id'] === null && $sms['provider'] === 'semaphore', 'the message is queued for SMS');
check((int) $sms['customer_id'] === $customerA, 'the customer is recorded on the queued message');

/* ---------------------------------------------------------------------- */
section('Connection codes');
$first = $links->createCode($customerA, $staffId);
check(preg_match('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/', $first['code']) === 1, 'a code is 8 characters from the unambiguous set');
check($first['link'] === 'https://t.me/TripleRTestBot?start=' . $first['code'], 'the link opens the bot with the code attached');
check($first['expires_at'] > time() + 14 * 60 && $first['expires_at'] <= time() + 15 * 60, 'the code is valid for 15 minutes');
$stored = $db->prepare('SELECT code_hash FROM telegram_link_codes WHERE customer_id = :c ORDER BY code_id DESC LIMIT 1');
$stored->execute(['c' => $customerA]);
$hash = (string) $stored->fetchColumn();
check(preg_match('/^[0-9a-f]{64}$/', $hash) === 1 && !str_contains($hash, strtolower($first['code'])), 'only a hash of the code is stored');
$second = $links->createCode($customerA, $staffId);
check($handler->handle($message($chat(1), '/start ' . $first['code'])) === 'invalid_code', 'creating a new code stops the earlier one from working');
check($activeLink($customerA) === null, 'a refused code connects nobody');

/* ---------------------------------------------------------------------- */
section('What the bot answers');
check($handler->handle($message($chat(2), '/start')) === 'needs_code' && str_contains(lastTextTo($chat(2)), 'connection code'), '/start without a code explains where a code comes from');
check(str_contains(lastTextTo($chat(2)), '09676355474'), 'the reply gives the office phone number');
check($handler->handle($message($chat(2), '/start ZZZZZZZZ')) === 'invalid_code', 'a wrong code is refused');
$before = count(stubState()['sent']);
check($handler->handle($message($chat(3), '/start ' . $second['code'], 'group')) === 'ignored' && count(stubState()['sent']) === $before && $activeLink($customerA) === null, 'a group chat is ignored: no reply, no connection');
$connectUpdate = $message($chat(4), '/start ' . $second['code']);
check($handler->handle($connectUpdate) === 'connected', 'the right code from a private chat connects the customer');
$linkA = $activeLink($customerA);
check($linkA !== null && $linkA['chat_fingerprint'] === $links->chatFingerprint((string) $chat(4)), 'the connection is stored against that chat');
check(str_contains(lastTextTo($chat(4)), 'connected') && str_contains(lastTextTo($chat(4)), '/stop'), 'the customer is told they are connected and how to stop');
check(!str_contains((string) $linkA['chat_id_ciphertext'], (string) $chat(4)) && $links->chatIdForLink((int) $linkA['link_id']) === (string) $chat(4), 'the chat id is stored encrypted and can be read back for sending');
$before = count(stubState()['sent']);
check($handler->handle($connectUpdate) === 'duplicate' && count(stubState()['sent']) === $before, 'the same update delivered twice changes nothing and sends no second reply');
check($handler->handle($message($chat(5), '/start ' . $second['code'])) === 'invalid_code', 'a used code cannot be used again from another chat');
$expired = $links->createCode($customerB, $staffId);
$db->prepare('UPDATE telegram_link_codes SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE customer_id = :c')->execute(['c' => $customerB]);
check($handler->handle($message($chat(5), '/start ' . $expired['code'])) === 'invalid_code' && $activeLink($customerB) === null, 'an expired code is refused');
$typed = $links->createCode($customerB, $staffId);
$byHand = strtolower(substr($typed['code'], 0, 4)) . '-' . strtolower(substr($typed['code'], 4));
check($handler->handle($message($chat(6), $byHand)) === 'connected' && $activeLink($customerB) !== null, 'a code typed by hand (lower case, with a dash) connects too');
check($handler->handle($message($chat(6), '/help')) === 'help', '/help is answered');
check($handler->handle($message($chat(6), 'When is my pickup?')) === 'fixed_reply' && str_contains(lastTextTo($chat(6)), 'not read'), 'any other message gets the fixed reply');
$columns = $db->query("SELECT GROUP_CONCAT(column_name) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'telegram_updates'")->fetchColumn();
check(!str_contains(strtolower((string) $columns), 'text'), 'the update log has no column for message text');

section('Guessing codes is limited per chat');
$outcomes = [];
for ($i = 0; $i < 5; $i++) {
    $outcomes[] = $handler->handle($message($chat(7), '/start ' . str_repeat('ABCDEFGH'[$i], 8)));
}
check($outcomes === array_fill(0, 5, 'invalid_code'), 'five wrong codes are each refused');
$real = $links->createCode($customerA, $staffId);
check($handler->handle($message($chat(7), '/start ' . $real['code'])) === 'rate_limited', 'the sixth attempt is refused even with a real code');
check((string) $activeLink($customerA)['link_id'] === (string) $linkA['link_id'], 'the customer\'s existing connection is untouched');

/* ---------------------------------------------------------------------- */
section('Sending to a connected customer');
$text = 'Your Triple R Gensan rental is confirmed. Agreement #' . $run . '.';
$queuedId = $notifications->enqueue($phoneA, 'rental.confirmed', $text, 'transactional', 'normal', "tg-{$run}-confirmed", false, $customerA);
$queued = $row($queuedId);
check($queued['channel'] === 'telegram' && $queued['provider'] === 'telegram' && (int) $queued['telegram_link_id'] === (int) $linkA['link_id'], 'the message is queued for Telegram on the customer\'s connection');
check(str_starts_with((string) $queued['rendered_message'], 'smsenc:v1:'), 'the queued text is encrypted at rest as before');
check($notifications->enqueue($phoneA, 'rental.confirmed', $text, 'transactional', 'normal', "tg-{$run}-confirmed", false, $customerA) === $queuedId, 'queueing the same message twice returns the first one');
$batch = $notifications->processBatch(50);
$sent = $row($queuedId);
check($sent['status'] === 'sent' && $sent['provider_status'] === 'accepted', 'the worker marks it sent');
check(str_starts_with((string) $sent['provider_message_id'], 'tg:' . $linkA['link_id'] . ':'), 'the Telegram message id is recorded with the connection id');
$delivered = array_values(array_filter(sentTo($chat(4)), static fn (array $m): bool => $m['text'] === $text));
check(count($delivered) === 1, 'the customer\'s chat received the message exactly once');
check(($delivered[0]['link_preview_options'] ?? '') === '{"is_disabled":true}', 'link previews are switched off');
check($row($smsId)['status'] === 'failed', 'the unconnected customer\'s SMS fails locally because no SMS provider is set up');
$suppressedId = $notifications->enqueue($phoneA, 'promo.news', 'Promo', 'non_transactional', 'normal', "tg-{$run}-promo", false, $customerA);
$suppressed = $row($suppressedId);
check($suppressed['status'] === 'suppressed_by_policy' && $suppressed['channel'] === 'telegram' && $suppressed['provider'] === 'telegram', 'non-transactional messages stay suppressed on every channel, and the row still says which channel it was for');
check(count(array_filter(sentTo($chat(4)), static fn (array $m): bool => $m['text'] === 'Promo')) === 0, 'a suppressed message is never sent');

$tokenId = $magicLinks->issue($phoneA, null, 'booking_manage', null, null, null, $customerA);
$linkRow = $db->prepare('SELECT * FROM notifications WHERE idempotency_key = :k');
$linkRow->execute(['k' => 'magic-link:' . $tokenId]);
$linkMessage = $linkRow->fetch();
check($linkMessage['channel'] === 'telegram' && $linkMessage['priority'] === 'high', 'the secure booking link is queued for Telegram with high priority');
$notifications->processBatch(50);
check(str_contains(lastTextTo($chat(4)), '/magic-link#token='), 'the customer receives the secure booking link in Telegram');
check($row((int) $linkMessage['id'])['rendered_message'] === '', 'the link text is erased from the queue once sent');

section('A rate limit from Telegram is retried');
stubSet('rate_limit', 1);
$retryId = $notifications->enqueue($phoneA, 'rental.active', 'Your rental pickup has been recorded.', 'transactional', 'normal', "tg-{$run}-retry", false, $customerA);
$batch = $notifications->processBatch(50);
$retry = $row($retryId);
check($batch['retrying'] === 1 && $retry['status'] === 'queued' && (int) $retry['retry_count'] === 1, 'the message goes back in the queue to be retried');
check(str_contains((string) $retry['last_error'], 'slow down'), 'the history says why in plain words');
$db->prepare('UPDATE notifications SET next_attempt_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE id = :id')->execute(['id' => $retryId]);
$notifications->processBatch(50);
check($row($retryId)['status'] === 'sent', 'the retry is delivered');

/* ---------------------------------------------------------------------- */
section('Connecting again');
check($connect($customerA, $chat(8)) === 'connected', 'the customer connects a different Telegram account');
$old = $linkRepository->find((int) $linkA['link_id']);
check($old['link_status'] === 'revoked' && $old['revoked_reason'] === 'relinked', 'the earlier connection is ended as "relinked"');
check($activeLink($customerA)['chat_fingerprint'] === $links->chatFingerprint((string) $chat(8)), 'the new chat is the active connection');
$linkB = $activeLink($customerB);
check($connect($customerB, $chat(8)) === 'connected', 'one Telegram account then connects to a second customer');
check($activeLink($customerA) === null, 'that account no longer receives the first customer\'s messages');
check($linkRepository->find((int) $linkB['link_id'])['revoked_reason'] === 'relinked' && $activeLink($customerB)['chat_fingerprint'] === $links->chatFingerprint((string) $chat(8)), 'the second customer\'s earlier connection is replaced');
$count = $db->prepare("SELECT COUNT(*) FROM customer_telegram_links WHERE customer_id IN (:a, :b) AND link_status = 'active'");
$count->execute(['a' => $customerA, 'b' => $customerB]);
check((int) $count->fetchColumn() === 1, 'exactly one active connection remains between the two customers');

section('/stop');
check($handler->handle($message($chat(8), '/stop')) === 'stopped' && $activeLink($customerB) === null, '/stop ends the connection at once');
$ended = $linkRepository->latestForCustomer($customerB);
check($ended['revoked_reason'] === 'customer_stop' && $ended['revoked_at'] !== null, 'it is recorded as stopped by the customer');
check(str_contains(lastTextTo($chat(8)), 'disconnected'), 'the customer gets a confirmation');
check($handler->handle($message($chat(8), '/stop')) === 'not_connected', 'a second /stop finds nothing to stop');
$afterStop = $row($notifications->enqueue($phoneB, 'rental.returned', 'Your vehicle return has been recorded.', 'transactional', 'normal', "tg-{$run}-after-stop", false, $customerB));
check($afterStop['channel'] === 'sms', 'later messages for that customer go back to the SMS route');

section('Staff disconnect');
check($connect($customerA, $chat(9)) === 'connected', 'the customer is connected again');
$unused = $links->createCode($customerA, $staffId);
check($links->disconnectByStaff($customerA, $staffId) === true && $activeLink($customerA) === null, 'staff can disconnect a customer');
$ended = $linkRepository->latestForCustomer($customerA);
check($ended['revoked_reason'] === 'staff' && (int) $ended['revoked_by_user_id'] === $staffId, 'the staff member is recorded');
check($links->disconnectByStaff($customerA, $staffId) === false, 'disconnecting twice reports that nothing was connected');
check($handler->handle($message($chat(10), '/start ' . $unused['code'])) === 'invalid_code' && $activeLink($customerA) === null, 'a code handed out before the disconnect no longer works');
$status = $links->statusFor($customerA);
check($status['connected'] === false && $status['last_ended_reason'] === 'staff' && $status['open_code_expires_at'] === null, 'the customer page status shows not connected and why');

/* ---------------------------------------------------------------------- */
section('The customer blocks the bot');
$notifications->processBatch(50); // Clear anything still due, so the next batch holds only this message.
check($connect($customerA, $chat(11)) === 'connected', 'the customer is connected');
$blockedLink = $activeLink($customerA);
$blockedId = $notifications->enqueue($phoneA, 'rental.returned', 'Your vehicle return has been recorded.', 'transactional', 'normal', "tg-{$run}-blocked", false, $customerA);
stubSet('blocked', [(string) $chat(11)]);
$batch = $notifications->processBatch(50);
$blocked = $row($blockedId);
check($linkRepository->find((int) $blockedLink['link_id'])['revoked_reason'] === 'bot_blocked' && $activeLink($customerA) === null, 'the refused send ends the connection as "bot blocked"');
check($blocked['status'] === 'failed' && (int) $blocked['retry_count'] === 0, 'with no SMS provider the message is failed, not retried');
check(str_contains((string) $blocked['last_error'], 'Telegram connection has ended') && str_contains((string) $blocked['last_error'], 'no SMS provider'), 'the history gives the reason in plain words');

check($connect($customerA, $chat(12)) === 'connected', 'the customer connects again');
$fallbackId = $notifications->enqueue($phoneA, 'rental.pickup_reminder', 'Reminder: pickup within 24 hours.', 'transactional', 'normal', "tg-{$run}-fallback", false, $customerA);
stubSet('blocked', [(string) $chat(12)]);
$dueSms = (int) $db->query("SELECT COUNT(*) FROM notifications WHERE status = 'queued' AND channel = 'sms' AND next_attempt_at <= UTC_TIMESTAMP()")->fetchColumn();
if ($dueSms === 0) {
    putenv('SMS_SEMAPHORE_API_KEY=not-a-real-key'); // Present only while this one Telegram row is claimed.
    Config::load(APP_ROOT);
    $batch = $notifications->processBatch(50);
    putenv('SMS_SEMAPHORE_API_KEY=');
    Config::load(APP_ROOT);
    $fallback = $row($fallbackId);
    check($batch['rerouted'] === 1 && $fallback['channel'] === 'sms' && $fallback['provider'] === 'semaphore' && $fallback['status'] === 'queued', 'with an SMS provider set up, the message is handed to the SMS route');
    check((int) $fallback['attempt_count'] === 0 && $fallback['telegram_link_id'] !== null, 'the Telegram attempt is not counted and the original connection stays on record');
    $notifications->processBatch(50); // No key again: the rerouted row fails locally, nothing leaves this machine.
    check($row($fallbackId)['status'] === 'failed', 'the rerouted message is then handled by the SMS worker');
} else {
    check(false, 'fallback to SMS could not be checked', 'other SMS rows were due');
}

check($connect($customerA, $chat(13)) === 'connected', 'the customer connects once more');
$lateId = $notifications->enqueue($phoneA, 'rental.return_reminder', 'Reminder: return within 24 hours.', 'transactional', 'normal', "tg-{$run}-late", false, $customerA);
$links->disconnectByStaff($customerA, $staffId);
$before = count(sentTo($chat(13)));
$notifications->processBatch(50);
check($row($lateId)['status'] === 'failed' && count(sentTo($chat(13))) === $before, 'a message queued before a disconnect is not sent to the old chat');

check($connect($customerB, $chat(14)) === 'connected', 'another customer is connected');
$kicked = ['update_id' => ++$updateId, 'my_chat_member' => ['chat' => ['id' => $chat(14), 'type' => 'private'], 'new_chat_member' => ['status' => 'kicked']]];
check($handler->handle($kicked) === 'blocked' && $linkRepository->latestForCustomer($customerB)['revoked_reason'] === 'bot_blocked', 'Telegram\'s own "blocked" notice ends the connection without waiting for a failed send');

/* ---------------------------------------------------------------------- */
section('The database enforces the rules on its own');
check($connect($customerB, $chat(15)) === 'connected', 'a connection exists to test against');
$live = $activeLink($customerB);
$revokedId = (int) $blockedLink['link_id'];
$rejects(fn () => $db->exec('UPDATE customer_telegram_links SET customer_id = ' . $customerA . ' WHERE link_id = ' . (int) $live['link_id']), 'append-only', 'a connection cannot be moved to another customer');
$rejects(fn () => $db->exec("UPDATE customer_telegram_links SET link_status = 'active', revoked_at = NULL, revoked_reason = NULL, revoked_by_user_id = NULL WHERE link_id = {$revokedId}"), 'cannot be changed', 'an ended connection cannot be brought back');
$rejects(function () use ($linkRepository, $customerB, $cipher): void {
    $linkRepository->insertLink($customerB, $cipher->encrypt('1', 'customer-telegram:chat'), $cipher->fingerprint('telegram-chat', 'second-' . bin2hex(random_bytes(4))), null);
}, 'duplicate', 'a customer cannot have two active connections');
$rejects(function () use ($linkRepository, $customerA, $cipher, $live): void {
    $linkRepository->insertLink($customerA, $cipher->encrypt('1', 'customer-telegram:chat'), (string) $live['chat_fingerprint'], null);
}, 'duplicate', 'a chat cannot be the active connection of two customers');
$rejects(fn () => $db->exec("INSERT INTO notifications (recipient_phone, channel, template_key, rendered_message, message_class, provider) VALUES ('+639170000000', 'telegram', 'guard.test', 'x', 'transactional', 'telegram')"), 'chk_notifications_telegram_link', 'a Telegram message without a connection is refused');
try {
    $db->exec('DELETE FROM customer_telegram_links WHERE link_id = ' . $revokedId);
    check(false, 'connections cannot be deleted', 'the DELETE was accepted');
} catch (PDOException $error) {
    check(true, 'connections cannot be deleted');
}

/* ---------------------------------------------------------------------- */
section('The polling worker');
$worker = static function (array $environment = []) : array {
    foreach ($environment as $name => $value) {
        putenv($name . '=' . $value);
    }
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/telegram-updates.php', '--once'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    return [proc_close($process), (string) $output, (string) $errors];
};
[$customerC, $phoneC] = $makeCustomer('C');
$workerCode = $links->createCode($customerC, $staffId);
stubSet('updates', [$message($chat(16), '/start ' . $workerCode['code'])]);
[$exit, $output] = $worker();
check($exit === 0 && str_contains($output, '@TripleRTestBot') && str_contains($output, ': connected'), 'the worker reads a waiting Start and connects the customer', trim($output));
check($activeLink($customerC) !== null, 'the customer is connected in the database');
check((stubState()['updates'] ?? []) === [], 'the worker tells Telegram the update was received');
[$exit, $output] = $worker();
check($exit === 0 && !str_contains($output, 'connected'), 'running the worker again finds nothing to do');
$wrongToken = '100000002:WRONG-' . bin2hex(random_bytes(16));
[$exit, $output, $errors] = $worker(['TELEGRAM_BOT_TOKEN' => $wrongToken]);
check($exit === 1 && str_contains($errors, 'rejected the bot token'), 'a wrong token stops the worker with a plain message');
check(!str_contains($output . $errors, $wrongToken) && !str_contains($output . $errors, 'http'), 'the token and the request address never appear in the output');
[$exit, $output, $errors] = $worker(['TELEGRAM_BOT_TOKEN' => 'not-a-token']);
check($exit === 1 && str_contains($errors, 'not set up'), 'without a token the worker says Telegram is not set up');
putenv('TELEGRAM_BOT_TOKEN=' . $stubToken);
[$exit, $output, $errors] = $worker(['TELEGRAM_BOT_USERNAME' => 'SomeOtherBot']);
check($exit === 1 && str_contains($errors, 'TELEGRAM_BOT_USERNAME'), 'a username that does not match the token is reported before any customer is sent to the wrong bot');
putenv('TELEGRAM_BOT_USERNAME=TripleRTestBot');

section('Failures never expose the token');
putenv('TELEGRAM_API_BASE=http://127.0.0.1:1'); // Nothing listens here.
Config::load(APP_ROOT);
try {
    (new TelegramBotClient())->sendMessage('1', 'x');
    check(false, 'an unreachable Telegram raises an error');
} catch (TelegramApiException $error) {
    check($error->isTemporary() && !str_contains($error->getMessage(), $stubToken) && !str_contains($error->getMessage(), '127.0.0.1'), 'an unreachable Telegram is a temporary error with no address or token in its text');
}
putenv('TELEGRAM_API_BASE=http://example.com');
Config::load(APP_ROOT);
try {
    (new TelegramBotClient())->sendMessage('1', 'x');
    check(false, 'a plain-http address on another machine is refused');
} catch (TelegramApiException $error) {
    check($error->reason === 'not_configured', 'the token is never sent over plain http to another machine');
}
putenv('TELEGRAM_API_BASE=http://127.0.0.1:' . $port);
Config::load(APP_ROOT);

/* ---------------------------------------------------------------------- */
section('The daily limit applies to both channels');
$limitLink = $activeLink($customerC);
$accepted = 0;
$refusal = '';
for ($i = 0; $i < 12; $i++) {
    try {
        $notifications->enqueue($phoneC, 'rental.confirmed', 'Limit check ' . $i, 'transactional', 'normal', "tg-{$run}-limit-{$i}", false, $customerC);
        $accepted++;
    } catch (DomainException $error) {
        $refusal = $error->getMessage();
        break;
    }
}
check($limitLink !== null && $accepted === 10 && str_contains($refusal, 'daily SMS limit'), 'the eleventh message in a day to one customer is refused, Telegram or not', "accepted {$accepted}");
$notifications->processBatch(50);
check(count(sentTo($chat(16))) === 11, 'the ten accepted messages (and the connect reply) reached the chat', (string) count(sentTo($chat(16))));

echo "\n" . ($failed === 0 ? "ALL {$passed} TELEGRAM CHECKS PASSED" : "{$failed} FAILED, {$passed} passed") . "\n";
exit($failed === 0 ? 0 : 1);
