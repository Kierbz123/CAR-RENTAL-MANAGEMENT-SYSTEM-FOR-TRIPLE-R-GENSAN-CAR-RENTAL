<?php
/**
 * Unlocks a staff account from the server's command line, for when no administrator can sign in
 * to do it from the Staff accounts page (for example the only administrator is the one locked).
 * The unlock is written to the security log.
 *
 *   php bin/unlock-user.php admin@example.test
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\SecurityLogRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

$email = mb_strtolower(trim((string) ($argv[1] ?? '')));
if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usage: php bin/unlock-user.php <staff email>\n");
    exit(2);
}
try {
    $db = Database::connection();
    $find = $db->prepare('SELECT id, locked_at FROM users WHERE email = :email AND deleted_at IS NULL');
    $find->execute(['email' => $email]);
    $user = $find->fetch();
    if ($user === false) {
        fwrite(STDERR, "No active staff account has that email.\n");
        exit(1);
    }
    if ($user['locked_at'] === null) {
        echo "That account is not locked.\n";
        exit(0);
    }
    $db->beginTransaction();
    $db->prepare('UPDATE users SET locked_at = NULL, failed_login_count = 0 WHERE id = :id')->execute(['id' => $user['id']]);
    (new SecurityLogRepository($db))->append('account_unlocked_cli', (int) $user['id'], $email, '', 'bin/unlock-user.php');
    $db->commit();
    echo "Unlocked {$email}.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Unlock failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
