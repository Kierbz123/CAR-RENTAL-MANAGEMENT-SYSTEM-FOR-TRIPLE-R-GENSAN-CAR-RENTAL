<?php
/**
 * Removing and restoring customers and drivers:
 *   - a removed record leaves the lists and booking choices, and shows in the "removed" list;
 *   - the same person cannot be entered again (their document or licence is still on record),
 *     but the removed record can be restored, with its history;
 *   - every removal and restoration is written to record_lifecycle_logs, which is append-only.
 *
 * Writes test customers and drivers; run it against a test database.
 *   php bin/test-restore.php
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\CustomerRepository;
use TripleR\Repositories\DriverRepository;
use TripleR\Services\CustomerPiiCipher;
use TripleR\Services\CustomerService;
use TripleR\Services\DriverPiiCipher;
use TripleR\Services\DriverService;

require dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connection();
$customerRows = new CustomerRepository($db);
$customers = new CustomerService($db, $customerRows, new CustomerPiiCipher());
$driverRows = new DriverRepository($db);
$drivers = new DriverService($db, $driverRows, new DriverPiiCipher());
$actor = (int) $db->query("SELECT id FROM users WHERE role = 'system_admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$db->exec('SET @triple_r_actor_user_id = ' . $actor);
$tag = strtoupper(bin2hex(random_bytes(3)));
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label, string $detail = '') use (&$passed, &$failed): void {
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS' : 'FAIL') . ": {$label}" . ($condition || $detail === '' ? '' : " ({$detail})") . "\n";
};
$ids = static fn (array $rows, string $key): array => array_map('intval', array_column($rows, $key));

echo "== Customers\n";
$passport = 'P' . random_int(10000000, 99999999);
$customerId = $customers->create(['customer_type' => 'walk_in', 'full_name' => 'Restore Customer ' . $tag, 'phone' => '0917' . random_int(1000000, 9999999), 'document_type' => 'passport', 'document_number' => $passport, 'expires_on' => '2032-01-01'], $actor);
$customers->softDelete($customerId, $actor, 'Moved away');
$check(!in_array($customerId, $ids($customerRows->list(null, 'Restore Customer ' . $tag), 'customer_id'), true), 'a removed customer leaves the customer list');
$check(in_array($customerId, $ids($customerRows->list(null, 'Restore Customer ' . $tag, true), 'customer_id'), true), 'and shows in the removed list');
$check(!in_array($customerId, $ids($customerRows->eligibleForBooking(), 'customer_id'), true), 'and cannot be chosen for a booking');
try {
    $customers->create(['customer_type' => 'walk_in', 'full_name' => 'Same Person ' . $tag, 'document_type' => 'passport', 'document_number' => $passport, 'expires_on' => '2032-01-01'], $actor);
    $check(false, 'the same passport cannot be entered as a new customer', 'it was accepted');
} catch (PDOException $error) {
    $check((int) ($error->errorInfo[1] ?? 0) === 1062, 'the same passport cannot be entered as a new customer');
}
$customers->restore($customerId, $actor, 'Came back');
$check(in_array($customerId, $ids($customerRows->eligibleForBooking(), 'customer_id'), true), 'a restored customer can be booked again');
$history = $customers->lifecycleHistory($customerId);
$check(array_column($history, 'action') === ['restored', 'removed'] && $history[1]['reason'] === 'Moved away', 'the removal and the restoration are recorded, newest first, with reasons', json_encode(array_column($history, 'action')));
try {
    $customers->restore($customerId, $actor);
    $check(false, 'restoring a customer who is not removed is refused', 'it was accepted');
} catch (RuntimeException $error) {
    $check(str_contains($error->getMessage(), 'not removed'), 'restoring a customer who is not removed is refused');
}

echo "== Drivers\n";
$licence = 'N0' . random_int(1, 9) . '-' . random_int(10, 99) . '-' . random_int(100000, 999999);
$driverId = $drivers->create(['full_name' => 'Restore Driver ' . $tag, 'license_number' => $licence, 'license_expiry' => '2031-01-01'], $actor);
$drivers->softDelete($driverId, $actor);
$check(!in_array($driverId, $ids($drivers->selectableForAssignment(), 'driver_id'), true), 'a removed driver cannot be assigned');
$check(in_array($driverId, $ids($driverRows->list('Restore Driver ' . $tag, false, true), 'driver_id'), true), 'and shows in the removed list');
try {
    $drivers->create(['full_name' => 'Same Driver ' . $tag, 'license_number' => $licence, 'license_expiry' => '2031-01-01'], $actor);
    $check(false, 'the same licence cannot be entered as a new driver', 'it was accepted');
} catch (PDOException $error) {
    $check((int) ($error->errorInfo[1] ?? 0) === 1062, 'the same licence cannot be entered as a new driver');
}
$drivers->restore($driverId, $actor);
$check(in_array($driverId, $ids($drivers->selectableForAssignment(), 'driver_id'), true), 'a restored driver can be assigned again');
$check(array_column($drivers->lifecycleHistory($driverId), 'action') === ['restored', 'removed'], 'the driver\'s removal and restoration are recorded');

echo "== The record cannot be rewritten\n";
foreach (['UPDATE record_lifecycle_logs SET reason = NULL WHERE customer_id = ' . $customerId, 'DELETE FROM record_lifecycle_logs WHERE customer_id = ' . $customerId] as $sql) {
    try {
        $db->exec($sql);
        $check(false, 'record_lifecycle_logs refuses: ' . strtok($sql, ' '), 'it was accepted');
    } catch (PDOException $error) {
        // The runtime account has no DELETE privilege at all; UPDATE is stopped by the trigger.
        $check(str_contains($error->getMessage(), 'append-only') || str_contains($error->getMessage(), 'command denied'), 'record_lifecycle_logs refuses: ' . strtok($sql, ' '));
    }
}

echo $failed === 0 ? "ALL {$passed} RESTORE CHECKS PASSED\n" : "{$failed} FAILED, {$passed} passed\n";
exit($failed === 0 ? 0 : 1);
