<?php
/**
 * A stand-in for Telegram's Bot API, used only by bin/test-telegram.php so the tests need no
 * network and no real bot. Run with PHP's built-in server:
 *
 *   TELEGRAM_STUB_STATE=/path/state.json php -S 127.0.0.1:<port> bin/support/telegram-stub.php
 *
 * The state file holds: token, username, sent (every sendMessage it accepted), updates (what
 * getUpdates should hand out), blocked (chat ids that answer "bot was blocked") and
 * rate_limit (how many sends to refuse with HTTP 429 first).
 */
declare(strict_types=1);

$statePath = getenv('TELEGRAM_STUB_STATE');
if (!is_string($statePath) || $statePath === '') {
    http_response_code(500);
    exit;
}

$answer = static function (int $status, array $body): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
};
$refuse = static fn (int $code, string $description, array $parameters = []) => $answer($code, ['ok' => false, 'error_code' => $code, 'description' => $description] + ($parameters ? ['parameters' => $parameters] : []));

$handle = fopen($statePath, 'c+');
flock($handle, LOCK_EX);
$state = json_decode((string) stream_get_contents($handle), true) ?: [];
$state += ['token' => '', 'username' => 'StubBot', 'sent' => [], 'updates' => [], 'blocked' => [], 'rate_limit' => 0];
$save = static function () use ($handle, &$state): void {
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
};

$path = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/bot([^/]+)/([A-Za-z]+)$#', $path, $match) !== 1) {
    $save();
    $refuse(404, 'Not Found');
    return;
}
if (!hash_equals((string) $state['token'], $match[1])) {
    $save();
    $refuse(401, 'Unauthorized');
    return;
}

switch ($match[2]) {
    case 'getMe':
        $answer(200, ['ok' => true, 'result' => ['id' => 1, 'is_bot' => true, 'username' => $state['username']]]);
        break;
    case 'sendMessage':
        $chatId = (string) ($_POST['chat_id'] ?? '');
        if ($state['rate_limit'] > 0) {
            $state['rate_limit']--;
            $refuse(429, 'Too Many Requests: retry after 1', ['retry_after' => 1]);
        } elseif (in_array($chatId, array_map('strval', $state['blocked']), true)) {
            $refuse(403, 'Forbidden: bot was blocked by the user');
        } else {
            $state['sent'][] = ['chat_id' => $chatId, 'text' => (string) ($_POST['text'] ?? ''), 'link_preview_options' => (string) ($_POST['link_preview_options'] ?? '')];
            $answer(200, ['ok' => true, 'result' => ['message_id' => count($state['sent']), 'chat' => ['id' => (int) $chatId, 'type' => 'private']]]);
        }
        break;
    case 'getUpdates':
        if (isset($_POST['offset'])) {
            $offset = (int) $_POST['offset'];
            $state['updates'] = array_values(array_filter($state['updates'], static fn (array $update): bool => $update['update_id'] >= $offset));
        }
        $answer(200, ['ok' => true, 'result' => array_slice($state['updates'], 0, max(1, (int) ($_POST['limit'] ?? 100)))]);
        break;
    default:
        $refuse(404, 'Not Found');
}
$save();
