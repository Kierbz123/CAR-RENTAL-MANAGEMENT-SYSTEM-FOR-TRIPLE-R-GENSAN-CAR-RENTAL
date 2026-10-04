<?php
/**
 * The smaller hardening steps (migration 028 and the low-priority audit items):
 *   - audit seals: a sealed history row changed or removed behind the triggers is reported;
 *   - the system account: automated actions are recorded under it and it cannot sign in;
 *   - SMS webhooks: a timestamped signature is accepted only within 5 minutes;
 *   - delivery reports: a final report is never replaced by a later one;
 *   - the simulated checkout is refused outside the demonstration site;
 *   - Money: exact peso arithmetic.
 *
 * Needs DB_MIGRATION_USER/DB_MIGRATION_PASSWORD: it removes a trigger for a moment to tamper with a
 * history row, as an intruder with that account could, then puts the row and the trigger back.
 * Run it against a test database.
 *   php bin/test-hardening.php
 */
declare(strict_types=1);

use TripleR\Config;
use TripleR\Database;
use TripleR\Http\Request;
use TripleR\Repositories\NotificationRepository;
use TripleR\Repositories\StaffUserRepository;
use TripleR\Controllers\SmsWebhookController;
use TripleR\Services\AuditSeal;
use TripleR\Services\Payments\PaymentGatewayFactory;
use TripleR\Support\Money;
use TripleR\Support\SiteProfile;

require dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connection();
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label, string $detail = '') use (&$passed, &$failed): void {
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS' : 'FAIL') . ": {$label}" . ($condition || $detail === '' ? '' : " ({$detail})") . "\n";
};

echo "== Audit seals\n";
$seal = new AuditSeal($db);
$db->prepare("INSERT INTO security_logs (event_type, ip_address, user_agent) VALUES ('test_seal', '127.0.0.1', :agent)")
    ->execute(['agent' => 'seal-test-' . bin2hex(random_bytes(4))]);
$problemsBefore = $seal->verifyAll();
$check($problemsBefore === [], 'the existing seals match before the test', implode(' | ', $problemsBefore));
$sealed = $seal->sealAll();
$check(count($sealed) === count(AuditSeal::TABLES), 'every history table is sealed');
$check($seal->verifyAll() === [], 'a fresh seal matches');
$db->prepare("INSERT INTO security_logs (event_type, ip_address, user_agent) VALUES ('test_seal', '127.0.0.1', 'added after the seal')")->execute();
$check($seal->verifyAll() === [], 'rows added after a seal do not disturb it');

$target = (int) $db->query("SELECT MAX(id) FROM security_logs WHERE event_type = 'test_seal' AND user_agent LIKE 'seal-test-%'")->fetchColumn();
$original = (string) $db->query("SELECT user_agent FROM security_logs WHERE id = {$target}")->fetchColumn();
$migration = Database::migrationConnection();
$trigger = $migration->query('SHOW CREATE TRIGGER security_logs_no_update')->fetch(PDO::FETCH_ASSOC);
// Recreated under the migration account itself, whoever created it first.
$definition = (string) preg_replace('/^CREATE\s+DEFINER\s*=\s*\S+\s+/i', 'CREATE ', (string) ($trigger['SQL Original Statement'] ?? ''));
$check(str_starts_with($definition, 'CREATE'), 'the update trigger can be read back to restore it');
$tamper = static function (string $agent) use ($migration, $definition, $target): void {
    $migration->exec('DROP TRIGGER security_logs_no_update');
    try {
        $migration->prepare('UPDATE security_logs SET user_agent = :agent WHERE id = :id')->execute(['agent' => $agent, 'id' => $target]);
    } finally {
        $migration->exec($definition);
    }
};
$tamper('quietly changed');
$problems = $seal->verifyAll();
$check($problems !== [] && str_contains(implode("\n", $problems), 'security_logs'), 'a sealed row changed behind the trigger is reported', implode(' | ', $problems));
$tamper($original);
$check($seal->verifyAll() === [], 'putting the row back makes the seal match again');
$check($migration->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'security_logs_no_update'")->fetchColumn() == 1, 'the trigger is back');
try {
    $db->exec('UPDATE audit_seals SET chain_hash = REPEAT(\'0\', 64) ORDER BY seal_id DESC LIMIT 1');
    $check(false, 'the seals themselves cannot be changed', 'the update was accepted');
} catch (PDOException $error) {
    $check(str_contains($error->getMessage(), 'append-only'), 'the seals themselves cannot be changed');
}
$output = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/audit-verify.php') . ' 2>&1', $output, $exit);
$check($exit === 0, 'bin/audit-verify.php exits 0 when everything matches', implode(' | ', $output));

echo "== System account\n";
$users = new StaffUserRepository($db);
$system = $db->query("SELECT id, is_active, locked_at, deleted_at, password_hash FROM users WHERE email = 'system@triple-r.invalid'")->fetch();
$check($system !== false && $users->systemActorId() === (int) $system['id'], 'automated actions are recorded under the system account');
$check($system !== false && (int) $system['is_active'] === 0 && $system['locked_at'] !== null && $system['deleted_at'] !== null && password_get_info((string) $system['password_hash'])['algo'] === null,
    'it is inactive, locked and removed, and no password matches it');
$check($system !== false && $users->isSystemAccount((int) $system['id']) && !$users->isSystemAccount((int) $db->query("SELECT id FROM users WHERE role = 'system_admin' AND email <> 'system@triple-r.invalid' ORDER BY id LIMIT 1")->fetchColumn()),
    'only that account is treated as the system account');

echo "== SMS webhook timestamps\n";
$secret = str_repeat('s', 48);
putenv('SMS_WEBHOOK_SECRET=' . $secret);
putenv('SMS_WEBHOOK_REQUIRE_TIMESTAMP=false');
Config::load(APP_ROOT);
$request = Closure::bind(static fn (string $body, array $headers): Request => new Request('POST', '/webhooks/sms/inbound', [], [], [], $body, $headers, '127.0.0.1', 'test'), null, Request::class);
$body = '{"message_id":"x"}';
$signed = static fn (string $payload): string => 'sha256=' . hash_hmac('sha256', $payload, $secret);
$now = (string) time();
$check(SmsWebhookController::validSignature($request($body, ['X-Webhook-Signature' => $signed($body)])), 'a signature over the body alone is still accepted');
$check(SmsWebhookController::validSignature($request($body, ['X-Webhook-Signature' => $signed($now . '.' . $body), 'X-Webhook-Timestamp' => $now])), 'a current timestamped signature is accepted');
$old = (string) (time() - 301);
$check(!SmsWebhookController::validSignature($request($body, ['X-Webhook-Signature' => $signed($old . '.' . $body), 'X-Webhook-Timestamp' => $old])), 'a replayed callback older than 5 minutes is refused');
$check(!SmsWebhookController::validSignature($request($body, ['X-Webhook-Signature' => $signed($body), 'X-Webhook-Timestamp' => $now])), 'a timestamp the signature does not cover is refused');
putenv('SMS_WEBHOOK_REQUIRE_TIMESTAMP=true');
Config::load(APP_ROOT);
$check(!SmsWebhookController::validSignature($request($body, ['X-Webhook-Signature' => $signed($body)])), 'SMS_WEBHOOK_REQUIRE_TIMESTAMP=true refuses a callback without one');

echo "== Delivery reports\n";
$messageId = 'hardening-' . bin2hex(random_bytes(6));
$notifications = new NotificationRepository($db);
$db->prepare("INSERT INTO notifications (recipient_phone, template_key, rendered_message, message_class, provider, status, provider_message_id, idempotency_key, sent_at)
    VALUES ('+639*****0000', 'test.hardening', 'x', 'transactional', 'test', 'sent', :message, :key, UTC_TIMESTAMP(6))")
    ->execute(['message' => $messageId, 'key' => $messageId]);
$check($notifications->recordDelivery($messageId, 'delivered', null), 'a delivered report is recorded');
$check(!$notifications->recordDelivery($messageId, 'failed', 'late report'), 'a later failure report does not replace it');
$row = $db->query("SELECT status, provider_status FROM notifications WHERE provider_message_id = '{$messageId}'")->fetch();
$check($row['status'] === 'sent' && $row['provider_status'] === 'delivered', 'the message stays delivered', json_encode($row));

echo "== Simulated checkout\n";
$profile = new ReflectionProperty(SiteProfile::class, 'data');
$live = SiteProfile::all();
putenv('PAYMENT_GATEWAY=simulated');
putenv('PAYMENT_WEBHOOK_SECRET=' . str_repeat('p', 48));
Config::load(APP_ROOT);
$profile->setValue(null, ['is_demo' => true] + $live);
$check(PaymentGatewayFactory::create() !== null, 'the simulated checkout runs on the demonstration site');
$profile->setValue(null, ['is_demo' => false] + $live);
$check(PaymentGatewayFactory::create() === null, 'it is switched off on the live site');
$profile->setValue(null, $live);

echo "== Money\n";
$check(Money::cents('1234.5') === 123450 && Money::cents('0.05') === 5 && Money::cents('7') === 700 && Money::cents('-0.25') === -25, 'amounts become whole centavos');
$check(Money::amount(123450) === '1234.50' && Money::amount(5) === '0.05' && Money::amount(-25) === '-0.25', 'centavos become stored amounts');
$check(Money::pesos(200000) === '₱2,000' && Money::pesos(123450) === '₱1,234.50', 'pesos are written for messages');

echo $failed === 0 ? "ALL {$passed} HARDENING CHECKS PASSED\n" : "{$failed} FAILED, {$passed} passed\n";
exit($failed === 0 ? 0 : 1);
