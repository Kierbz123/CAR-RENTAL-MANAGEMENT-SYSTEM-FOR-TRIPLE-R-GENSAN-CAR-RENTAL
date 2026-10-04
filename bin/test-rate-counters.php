<?php
/**
 * The three limits that share the rate_counters table: request throttles, the per-phone daily
 * message limit, and the per-booking secure-link limit. Each must count on its own rows only.
 *
 * Writes test rows; run it against a test database that has at least one rental agreement.
 *   php bin/test-rate-counters.php
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\MagicLinkRepository;
use TripleR\Repositories\NotificationRepository;
use TripleR\Services\RateLimiter;

require dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connection();
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
};
$counter = static function (string $scope, string $key) use ($db): ?int {
    $statement = $db->prepare('SELECT hits FROM rate_counters WHERE scope = :scope AND counter_key = :key');
    $statement->execute(['scope' => $scope, 'key' => $key]);
    $hits = $statement->fetchColumn();
    return $hits === false ? null : (int) $hits;
};
$run = bin2hex(random_bytes(4));

echo "Rate counters acceptance (run {$run})\n\n== Throttle\n";
$limiter = new RateLimiter($db);
$identity = 'visitor-' . $run;
$results = [];
for ($i = 0; $i < 4; $i++) {
    $results[] = $limiter->allow('test-scope', $identity, 3, 60);
}
$check($results === [true, true, true, false], 'three requests are allowed in a window and the fourth is refused');
$check($limiter->allow('test-scope', 'someone-else-' . $run, 3, 60) === true, 'another identity has its own count');
$check($limiter->allow('other-scope', $identity, 3, 60) === true, 'the same identity under another limiter has its own count');
$key = hash('sha256', 'test-scope:' . $identity);
$check($counter('throttle', $key) === 4, 'the count is one rate_counters row in the throttle scope');
$db->prepare("UPDATE rate_counters SET window_started_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 61 SECOND) WHERE scope = 'throttle' AND counter_key = :key")->execute(['key' => $key]);
$check($limiter->allow('test-scope', $identity, 3, 60) === true && $counter('throttle', $key) === 1, 'once the window has passed, the same row starts again from one');

echo "\n== Daily message limit\n";
$notifications = new NotificationRepository($db);
$phone = '+63917' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$db->beginTransaction();
$before = $notifications->lockDailyBudget($phone, $today);
$notifications->incrementDailyBudget($phone, $today);
$notifications->incrementDailyBudget($phone, $today);
$after = $notifications->lockDailyBudget($phone, $today);
$nextDay = $notifications->lockDailyBudget($phone, $tomorrow);
$db->commit();
$check($before === 0 && $after === 2, 'a phone starts the day at zero and each message adds one');
$check($nextDay === 0, 'the next day has its own count');
// Keyed by the number's fingerprint, never the number itself (migration 026).
$fingerprint = (new \TripleR\Services\PhoneVault())->fingerprint($phone);
$check($counter('message_daily', $fingerprint . '|' . $today) === 2 && $counter('message_daily', $fingerprint . '|' . $tomorrow) === 0, 'each day is one rate_counters row keyed by the number\'s fingerprint and date');
$check($counter('message_daily', $phone . '|' . $today) === null, 'the number itself is not stored in the counter key');

echo "\n== Secure links per booking\n";
$agreementId = (int) $db->query('SELECT MAX(agreement_id) FROM rental_agreements')->fetchColumn();
if ($agreementId === 0) {
    $check(false, 'a rental agreement exists to issue links for');
} else {
    $links = new MagicLinkRepository($db);
    $start = $counter('magic_link_booking', (string) $agreementId) ?? 0;
    $limit = $start + 2;
    $expires = gmdate('Y-m-d H:i:s.u', time() + 3600);
    $issue = static fn (): int => $links->create(hash('sha256', random_bytes(32)), 'booking_manage', $agreementId, $expires, $limit);
    $first = $issue();
    $second = $issue();
    $check($counter('magic_link_booking', (string) $agreementId) === $limit, 'each secure link issued for a booking adds one to that booking\'s count');
    $refused = false;
    try {
        $issue();
    } catch (DomainException $error) {
        $refused = str_contains($error->getMessage(), 'limit');
    }
    $check($refused && $counter('magic_link_booking', (string) $agreementId) === $limit, 'a link beyond the limit is refused and not counted');
    $links->invalidate($second);
    $check($counter('magic_link_booking', (string) $agreementId) === $limit - 1, 'withdrawing an unused link gives its place back');
    $links->invalidate($second);
    $check($counter('magic_link_booking', (string) $agreementId) === $limit - 1, 'withdrawing it twice does not give back two places');
    $check($counter('throttle', (string) $agreementId) === null && $counter('message_daily', (string) $agreementId) === null, 'the booking count does not leak into the other scopes');
}

echo "\n== The database's own rule\n";
try {
    $db->exec("INSERT INTO rate_counters (scope, counter_key, window_started_at, hits) VALUES ('made_up', 'x-{$run}', UTC_TIMESTAMP(), 1)");
    $check(false, 'an unknown scope is refused');
} catch (PDOException $error) {
    $check(str_contains($error->getMessage(), 'chk_rate_counters_scope'), 'an unknown scope is refused');
}

echo "\n" . ($failed === 0 ? "ALL {$passed} RATE COUNTER CHECKS PASSED" : "{$failed} FAILED, {$passed} passed") . "\n";
exit($failed === 0 ? 0 : 1);
