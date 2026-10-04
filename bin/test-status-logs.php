<?php
/**
 * status_logs holds the status history of vehicles, drivers, rentals and deposits in one
 * table. These checks prove the database itself still holds each kind to its own rules, as
 * the separate tables did: the right owner, the right statuses, the mandatory reasons, and
 * no edits or deletions.
 *
 * Every statement here is expected to be refused, so nothing is written. Needs a database that
 * already has at least one vehicle, driver, rental agreement and user.
 *   php bin/test-status-logs.php
 */
declare(strict_types=1);

use TripleR\Database;

require dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connection();
$passed = 0;
$failed = 0;
$refused = static function (string $sql, string $needle, string $label) use ($db, &$passed, &$failed): void {
    try {
        $db->exec($sql);
        $failed++;
        echo "FAIL: {$label} (the statement was accepted)\n";
    } catch (PDOException $error) {
        if (str_contains(strtolower($error->getMessage()), strtolower($needle))) {
            $passed++;
            echo "PASS: {$label}\n";
        } else {
            $failed++;
            echo "FAIL: {$label} (" . $error->getMessage() . ")\n";
        }
    }
};
$one = static fn (string $sql): int => (int) $db->query($sql)->fetchColumn();

$vehicle = $one('SELECT MAX(vehicle_id) FROM vehicles');
$driver = $one('SELECT MAX(driver_id) FROM drivers');
$agreement = $one('SELECT MAX(agreement_id) FROM rental_agreements');
$actor = $one('SELECT MIN(id) FROM users');
if ($vehicle === 0 || $driver === 0 || $agreement === 0 || $actor === 0) {
    fwrite(STDERR, "This test needs at least one vehicle, driver, rental agreement and user.\n");
    exit(2);
}
$before = $one('SELECT COUNT(*) FROM status_logs');
$insert = static fn (string $columns, string $values): string => "INSERT INTO status_logs ({$columns}, actor_user_id) VALUES ({$values}, {$actor})";

echo "Status log rules enforced by the database\n\n== Each kind of status belongs to its own kind of record\n";
$refused($insert('subject, driver_id, new_status', "'vehicle', {$driver}, 'available'"), 'chk_status_logs_owner', 'a vehicle status cannot be attached to a driver');
$refused($insert('subject, new_status', "'rental', 'confirmed'"), 'chk_status_logs_owner', 'a status with no record at all is refused');
$refused($insert('subject, vehicle_id, agreement_id, new_status', "'vehicle', {$vehicle}, {$agreement}, 'available'"), 'chk_status_logs_owner', 'a status cannot belong to two records');
$refused($insert('subject, vehicle_id, new_status', "'vehicle', 999999999, 'available'"), 'foreign key', 'a status for a vehicle that does not exist is refused by the foreign key');
$refused($insert('subject, agreement_id, new_status', "'rental', 999999999, 'confirmed'"), 'foreign key', 'a status for a rental that does not exist is refused by the foreign key');
$refused($insert('subject, driver_id, new_status', "'made_up', {$driver}, 'active'"), 'subject', 'an unknown kind of status is refused');

echo "\n== Each kind keeps its own list of statuses\n";
$refused($insert('subject, vehicle_id, new_status', "'vehicle', {$vehicle}, 'confirmed'"), 'chk_status_logs_statuses', 'a vehicle cannot be given a rental status');
$refused($insert('subject, driver_id, new_status', "'driver', {$driver}, 'available'"), 'chk_status_logs_statuses', 'a driver cannot be given a vehicle status');
$refused($insert('subject, agreement_id, new_status', "'rental', {$agreement}, 'held'"), 'chk_status_logs_statuses', 'a rental cannot be given a deposit status');
$refused($insert('subject, agreement_id, old_status, new_status', "'rental', {$agreement}, 'nonsense', 'confirmed'"), 'chk_status_logs_statuses', 'the previous status is checked as well');

echo "\n== Mandatory facts\n";
$refused($insert('subject, agreement_id, new_status', "'rental', {$agreement}, 'cancelled'"), 'chk_status_logs_reason', 'cancelling a rental needs a reason');
$refused($insert('subject, agreement_id, new_status, reason', "'rental', {$agreement}, 'no_show', '   '"), 'chk_status_logs_reason', 'a blank reason does not count');
$refused($insert('subject, agreement_id, new_status, new_amount', "'deposit', {$agreement}, 'held', 1000"), 'chk_status_logs_reason', 'a deposit change needs a reason');
$refused($insert('subject, agreement_id, new_status, reason', "'deposit', {$agreement}, 'held', 'Paid in cash'"), 'chk_status_logs_amounts', 'a deposit change needs its amount');
$refused($insert('subject, agreement_id, new_status, reason, new_amount', "'deposit', {$agreement}, 'held', 'Paid in cash', -1"), 'chk_status_logs_amounts', 'a deposit amount cannot be negative');
$refused($insert('subject, agreement_id, new_status, new_amount', "'rental', {$agreement}, 'confirmed', 500"), 'chk_status_logs_amounts', 'only a deposit change carries an amount');
$refused($insert('subject, driver_id, new_status, mileage', "'driver', {$driver}, 'active', 12000"), 'chk_status_logs_vehicle_facts', 'only a vehicle status carries mileage');
$refused($insert('subject, driver_id, new_status, reason', "'driver', {$driver}, 'inactive', 'On leave'"), 'chk_status_logs_reason', 'a driver status carries no reason, as before');

echo "\n== History cannot be rewritten\n";
$existing = $one('SELECT MIN(status_log_id) FROM status_logs');
if ($existing === 0) {
    $failed++;
    echo "FAIL: an existing history row is needed to test edits (none found)\n";
} else {
    $refused("UPDATE status_logs SET new_status = new_status WHERE status_log_id = {$existing}", 'append-only', 'a history row cannot be edited');
    try {
        $db->exec("DELETE FROM status_logs WHERE status_log_id = {$existing}");
        $failed++;
        echo "FAIL: a history row cannot be deleted (the DELETE was accepted)\n";
    } catch (PDOException $error) {
        $passed++; // The application account has no DELETE right; an account that has one meets the trigger.
        echo "PASS: a history row cannot be deleted\n";
    }
}

if ($one('SELECT COUNT(*) FROM status_logs') === $before) {
    $passed++;
    echo "PASS: nothing was written by the refused statements\n";
} else {
    $failed++;
    echo "FAIL: the number of history rows changed\n";
}

echo "\n" . ($failed === 0 ? "ALL {$passed} STATUS LOG CHECKS PASSED" : "{$failed} FAILED, {$passed} passed") . "\n";
exit($failed === 0 ? 0 : 1);
