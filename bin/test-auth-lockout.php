<?php
/**
 * Staff sign-in protection:
 *   - five wrong passwords lock the account, and the right password is then refused like a wrong one;
 *   - the lock lifts by itself after AUTH_LOCKOUT_MINUTES, so a stranger cannot keep it shut;
 *   - bin/unlock-user.php unlocks from the server and writes the security log;
 *   - an unknown email takes about as long to refuse as a wrong password;
 *   - a session unused for AUTH_IDLE_TIMEOUT_SECONDS ends.
 *
 * Writes test staff accounts; run it against a test database.
 *   php bin/test-auth-lockout.php
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\SecurityLogRepository;
use TripleR\Repositories\SessionRepository;
use TripleR\Repositories\StaffUserRepository;
use TripleR\Services\AuthService;
use TripleR\Services\RateLimiter;

require dirname(__DIR__) . '/app/bootstrap.php';

// Sign-in starts a PHP session, which cannot start once output has been sent, so the report is
// buffered and printed at the end.
ob_start();
$db = Database::connection();
$users = new StaffUserRepository($db);
$sessions = new SessionRepository($db);
$auth = new AuthService($db, $users, $sessions, new SecurityLogRepository($db), new RateLimiter($db));
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label, string $detail = '') use (&$passed, &$failed): void {
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS' : 'FAIL') . ": {$label}" . ($condition || $detail === '' ? '' : " ({$detail})") . "\n";
};
$password = 'Lockout-Test-Password-' . bin2hex(random_bytes(4));
$email = 'lockout-' . bin2hex(random_bytes(4)) . '@test.invalid';
$userId = $users->create($email, password_hash($password, PASSWORD_DEFAULT), 'front_desk');
$db->prepare('UPDATE users SET must_change_password = 0 WHERE id = :id')->execute(['id' => $userId]);
// Each attempt comes from its own address so the per-address throttle does not hide the account rule.
$attempt = static function (string $pw, ?string $who = null) use ($auth, $email): string {
    return $auth->login($who ?? $email, $pw, '198.51.100.' . random_int(1, 254), 'test-auth-lockout');
};
$clearThrottle = static fn () => $db->exec("UPDATE rate_counters SET hits = 0 WHERE scope = 'throttle'");
$lockedAt = static fn (): ?string => $db->query('SELECT locked_at FROM users WHERE id = ' . $userId)->fetchColumn() ?: null;

echo "== Lock after repeated wrong passwords\n";
$clearThrottle();
for ($i = 0; $i < 5; $i++) {
    $attempt('wrong-password-' . $i);
}
$check($lockedAt() !== null, 'five wrong passwords lock the account');
$clearThrottle();
$check($attempt($password) === 'failed', 'the right password is refused while locked, with the same answer as a wrong one');

echo "== The lock lifts by itself\n";
$db->prepare('UPDATE users SET locked_at = UTC_TIMESTAMP(6) - INTERVAL 16 MINUTE WHERE id = :id')->execute(['id' => $userId]);
$clearThrottle();
$check($attempt($password) === 'authenticated', 'after the lockout period the right password signs in');
$check($lockedAt() === null, 'and the lock is cleared');

echo "== Unlock from the command line\n";
$clearThrottle();
for ($i = 0; $i < 5; $i++) {
    $attempt('wrong-again-' . $i);
}
$output = [];
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/unlock-user.php') . ' ' . escapeshellarg($email) . ' 2>&1', $output, $exit);
$check($exit === 0 && $lockedAt() === null, 'bin/unlock-user.php unlocks the account', implode(' ', $output));
$logged = (int) $db->query("SELECT COUNT(*) FROM security_logs WHERE event_type = 'account_unlocked_cli' AND subject_user_id = " . $userId)->fetchColumn();
$check($logged === 1, 'the unlock is in the security log');

echo "== Unknown emails are not faster to refuse\n";
$time = static function (callable $work): float { $start = hrtime(true); $work(); return (hrtime(true) - $start) / 1e6; };
$known = $unknown = [];
for ($i = 0; $i < 3; $i++) {
    $clearThrottle();
    $db->prepare('UPDATE users SET failed_login_count = 0, locked_at = NULL WHERE id = :id')->execute(['id' => $userId]);
    $known[] = $time(fn () => $attempt('not-the-password'));
    $unknown[] = $time(fn () => $attempt('not-the-password', 'nobody-' . bin2hex(random_bytes(4)) . '@test.invalid'));
}
sort($known);
sort($unknown);
$check($unknown[1] > $known[1] * 0.5, 'an unknown email takes at least half as long as a wrong password', sprintf('known %.0f ms, unknown %.0f ms', $known[1], $unknown[1]));

echo "== Idle sessions end\n";
$db->prepare('UPDATE users SET failed_login_count = 0, locked_at = NULL WHERE id = :id')->execute(['id' => $userId]);
$clearThrottle();
$attempt($password);
$check($auth->currentUser() !== null, 'a fresh session is signed in');
$db->prepare('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP(6) - INTERVAL 31 MINUTE WHERE user_id = :id AND invalidated_at IS NULL')->execute(['id' => $userId]);
$check($auth->currentUser() === null, 'a session unused for 31 minutes is no longer signed in');

$db->prepare('UPDATE users SET is_active = 0, deleted_at = UTC_TIMESTAMP(6) WHERE id = :id')->execute(['id' => $userId]);
echo $failed === 0 ? "ALL {$passed} SIGN-IN PROTECTION CHECKS PASSED\n" : "{$failed} FAILED, {$passed} passed\n";
exit($failed === 0 ? 0 : 1);
