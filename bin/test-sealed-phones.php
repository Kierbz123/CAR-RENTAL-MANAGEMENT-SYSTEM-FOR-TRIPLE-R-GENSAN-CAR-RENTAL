<?php
/**
 * Mobile numbers outside the customer record are kept sealed (migration 026):
 *   - a queued SMS, an inbound SMS and a consent row hold a masked number, the number encrypted and
 *     its fingerprint, never the plain number, and a STOP reply is still recognised;
 *   - a row written before 026 is still read, and bin/seal-phone-numbers.php seals it once;
 *   - the append-only ledgers allow that one sealing and nothing else.
 *
 * Writes test notifications, SMS events and consent rows; run it against a test database.
 *   php bin/test-sealed-phones.php
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\InboundSmsEventRepository;
use TripleR\Repositories\NotificationRepository;
use TripleR\Repositories\RulesAcceptanceRepository;
use TripleR\Services\NotificationService;
use TripleR\Services\PhoneVault;
use TripleR\Services\SmsMessageCipher;
use TripleR\Services\TelegramLinkService;

require dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connection();
$vault = new PhoneVault();
$inbound = new InboundSmsEventRepository($db);
$rules = new RulesAcceptanceRepository($db);
$notifications = new NotificationService(new NotificationRepository($db), $inbound, new SmsMessageCipher(), $rules, TelegramLinkService::create($db));
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label, string $detail = '') use (&$passed, &$failed): void {
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS' : 'FAIL') . ": {$label}" . ($condition || $detail === '' ? '' : " ({$detail})") . "\n";
};
$number = '+63918' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
$plainAnywhere = static function (string $table, string $column) use ($db, $number): int {
    $q = $db->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :number");
    $q->execute(['number' => $number]);
    return (int) $q->fetchColumn();
};

echo "== New rows are sealed\n";
$id = $notifications->enqueue($number, 'test.sealed', 'Hello from the sealed-phone test.', 'transactional', 'normal', 'sealed-' . bin2hex(random_bytes(6)));
$row = $db->query('SELECT recipient_phone, recipient_ciphertext, recipient_fingerprint, template_key, rendered_message FROM notifications WHERE id = ' . $id)->fetch();
$check($row['recipient_phone'] === PhoneVault::mask($number) && $plainAnywhere('notifications', 'recipient_phone') === 0, 'a queued SMS keeps only the masked number in plain sight', (string) $row['recipient_phone']);
$check($vault->open((string) $row['recipient_ciphertext']) === $number && $row['recipient_fingerprint'] === $vault->fingerprint($number), 'its number can be decrypted, and its fingerprint is the customer-contact fingerprint');
$text = (new SmsMessageCipher())->decrypt((string) $row['rendered_message'], SmsMessageCipher::context($vault->numberOf($row['recipient_ciphertext'], (string) $row['recipient_phone']), (string) $row['template_key']));
$check($text === 'Hello from the sealed-phone test.', 'the message body is still readable for sending');
$counterKey = (string) $db->query("SELECT counter_key FROM rate_counters WHERE scope = 'message_daily' AND counter_key LIKE '" . $vault->fingerprint($number) . "|%'")->fetchColumn();
$check($counterKey !== '' && !str_contains($counterKey, $number), 'the daily SMS limit is counted under the fingerprint, not the number');

$messageId = 'stop-' . bin2hex(random_bytes(6));
$inbound->append($messageId, 'test', json_encode(['message_id' => $messageId, 'from' => $number, 'message' => 'STOP']), $number, 'stop', 'STOP');
$event = $db->query("SELECT sender_number, raw_payload, sender_fingerprint FROM inbound_sms_events WHERE provider_message_id = '{$messageId}'")->fetch();
$check(!str_contains((string) $event['raw_payload'], substr($number, 3)) && $plainAnywhere('inbound_sms_events', 'sender_number') === 0, 'an inbound SMS keeps neither the number nor the number inside its payload', (string) $event['raw_payload']);
$check($inbound->hasStop($number), 'a STOP reply is recognised by fingerprint');
$rules->consumeStopEvents();
$check($rules->hasStop($number) && $plainAnywhere('rules_acceptances', 'phone') === 0, 'the STOP reaches the consent ledger sealed');

echo "== Rows from before migration 026\n";
$legacy = '+63919' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
$legacyId = 'legacy-' . bin2hex(random_bytes(6));
$db->prepare("INSERT INTO inbound_sms_events (provider_message_id, provider, raw_payload, received_at, sender_number, event_type, message_text) VALUES (:id, 'test', :payload, UTC_TIMESTAMP(6), :number, 'stop', 'STOP')")
    ->execute(['id' => $legacyId, 'payload' => json_encode(['from' => $legacy, 'message' => 'STOP']), 'number' => $legacy]);
$check($inbound->hasStop($legacy), 'an unsealed STOP from before 026 is still recognised');
$output = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/seal-phone-numbers.php') . ' 2>&1', $output, $exit);
$sealed = $db->query("SELECT sender_number, raw_payload, sender_fingerprint FROM inbound_sms_events WHERE provider_message_id = '{$legacyId}'")->fetch();
$check($exit === 0 && $sealed['sender_fingerprint'] === $vault->fingerprint($legacy) && !str_contains((string) $sealed['raw_payload'], substr($legacy, 3)), 'bin/seal-phone-numbers.php seals it, payload included', implode(' | ', $output));
$check($inbound->hasStop($legacy), 'and it is still recognised afterwards');

echo "== The ledgers allow that one sealing and nothing else\n";
foreach ([
    "UPDATE inbound_sms_events SET sender_number = 'x', sender_fingerprint = REPEAT('b', 64), sender_ciphertext = 0x01 WHERE provider_message_id = '{$legacyId}'" => 'sealing a row a second time',
    "UPDATE inbound_sms_events SET event_type = 'message' WHERE provider_message_id = '{$messageId}'" => 'changing what the event was',
    "UPDATE rules_acceptances SET phone = 'x' WHERE phone_fingerprint IS NOT NULL ORDER BY acceptance_id DESC LIMIT 1" => 'changing a sealed consent row',
] as $sql => $label) {
    try {
        $db->exec($sql);
        $check(false, 'refused: ' . $label, 'it was accepted');
    } catch (PDOException $error) {
        $check(str_contains($error->getMessage(), 'append-only'), 'refused: ' . $label);
    }
}

echo $failed === 0 ? "ALL {$passed} SEALED PHONE CHECKS PASSED\n" : "{$failed} FAILED, {$passed} passed\n";
exit($failed === 0 ? 0 : 1);
