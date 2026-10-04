<?php
/**
 * What happens when a vehicle comes back:
 *   - a return more than LATE_RETURN_GRACE_MINUTES after the scheduled time is charged per started
 *     day at the daily rate (plus the chauffeur rate for a chauffeur rental); within the grace, or
 *     with LATE_RETURN_CHARGE=off, nothing is added;
 *   - damage recorded during the rental takes the vehicle off the road at return (severe → out of
 *     service, moderate → maintenance, minor → unchanged), and it cannot then be booked.
 * The same hold for damage found at the return inspection is exercised over HTTP by the photo
 * upload in bin/test-m7-http.php's flow; here it is checked through VehicleService directly.
 *
 * Writes test vehicles, customers, drivers and agreements; run it against a test database.
 *   php bin/test-return-rules.php
 */
declare(strict_types=1);

use TripleR\Config;
use TripleR\Database;
use TripleR\Repositories\ChargeRepository;
use TripleR\Repositories\CustomerRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Repositories\VehicleStatusLogRepository;
use TripleR\Services\BookingOverlapService;
use TripleR\Services\ChauffeurService;
use TripleR\Services\CustomerPiiCipher;
use TripleR\Services\CustomerService;
use TripleR\Services\RentalRuntimeFactory;
use TripleR\Services\VehicleService;

require dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connection();
$rentals = RentalRuntimeFactory::service($db);
$customers = new CustomerService($db, new CustomerRepository($db), new CustomerPiiCipher());
$overlaps = new BookingOverlapService($db);
$chauffeurs = new ChauffeurService($db, new RentalRepository($db, $overlaps), new ChargeRepository($db), new VehicleRepository($db), $overlaps);
$vehicles = new VehicleService($db, new VehicleRepository($db), new VehicleStatusLogRepository($db));
$manila = new DateTimeZone('Asia/Manila');
$utc = new DateTimeZone('UTC');
$tag = strtoupper(bin2hex(random_bytes(3)));
$actor = (int) $db->query("SELECT id FROM users WHERE role = 'system_admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$db->exec('SET @triple_r_actor_user_id = ' . $actor);
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label, string $detail = '') use (&$passed, &$failed): void {
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS' : 'FAIL') . ": {$label}" . ($condition || $detail === '' ? '' : " ({$detail})") . "\n";
};
$setting = static function (string $name, string $value): void {
    putenv($name . '=' . $value);
    Config::load(APP_ROOT);
};
$vehicle = static function (?string $chauffeurRate = null) use ($db, $tag): int {
    static $n = 0;
    $db->prepare("INSERT INTO vehicles (plate_number, make, model, model_year, color, body_type, transmission, fuel_type, seating_capacity, daily_rate, chauffeur_daily_rate) VALUES (:plate, 'Test', 'Return', 2024, 'White', 'sedan', 'automatic', 'gasoline', 5, 1500.00, :chauffeur)")
        ->execute(['plate' => 'RR-' . $tag . '-' . ++$n, 'chauffeur' => $chauffeurRate]);
    return (int) $db->lastInsertId();
};
/** A rental that is out now and was due back $dueAgo ago (Manila time, e.g. '-26 hours'). */
$outAndDue = static function (int $vehicleId, string $dueAgo, string $type = 'self_drive') use ($rentals, $customers, $chauffeurs, $db, $actor, $manila, $tag): int {
    $due = (new DateTimeImmutable('now', $manila))->modify($dueAgo);
    $pickup = $due->modify('-2 days');
    $id = $rentals->create(['customer_id' => $customers->create(['customer_type' => 'walk_in', 'full_name' => 'Return ' . $tag, 'phone' => '0917' . random_int(1000000, 9999999)], $actor), 'vehicle_id' => $vehicleId, 'rental_type' => $type,
        'start_date' => $pickup->format('Y-m-d'), 'end_date' => $due->format('Y-m-d'), 'scheduled_pickup_at' => $pickup->format('Y-m-d\TH:i'), 'scheduled_return_at' => $due->format('Y-m-d\TH:i'), 'deposit_amount' => '0'], $actor);
    if ($type === 'chauffeur') {
        $db->prepare("INSERT INTO drivers (full_name, license_number_ciphertext, license_number_fingerprint, license_expiry) VALUES (:name, :cipher, :fp, '2035-01-01')")
            ->execute(['name' => 'Return Driver ' . $tag, 'cipher' => 'test-' . bin2hex(random_bytes(4)), 'fp' => hash('sha256', random_bytes(16))]);
        $chauffeurs->assignDriver($id, (int) $db->lastInsertId(), $actor);
    }
    $rentals->recordDownpayment($id, 'RR' . random_int(10000000, 99999999), $actor);
    $rentals->transition($id, 'confirm', $actor);
    $rentals->transition($id, 'pickup', $actor, null, 1000);
    return $id;
};
$lateFee = static function (int $id) use ($db): ?string {
    $fee = $db->query("SELECT amount FROM rental_charges WHERE agreement_id = {$id} AND charge_type = 'fee' AND description LIKE 'Late return:%'")->fetchColumn();
    return $fee === false ? null : (string) $fee;
};
$status = static fn (int $vehicleId): string => (string) $db->query('SELECT current_status FROM vehicles WHERE vehicle_id = ' . $vehicleId)->fetchColumn();

echo "== Late returns\n";
$setting('LATE_RETURN_CHARGE', 'on');
$setting('LATE_RETURN_GRACE_MINUTES', '60');
$id = $outAndDue($vehicle(), '-26 hours');
$rentals->transition($id, 'return', $actor, null, 1200);
$check($lateFee($id) === '3000.00', 'returned 26 hours late: two started days at 1,500 = 3,000.00', (string) $lateFee($id));
$id = $outAndDue($vehicle(), '-30 minutes');
$rentals->transition($id, 'return', $actor, null, 1200);
$check($lateFee($id) === null, 'returned 30 minutes late, within the grace: no charge');
$id = $outAndDue($vehicle('1000.00'), '-3 hours', 'chauffeur');
$rentals->transition($id, 'return', $actor, null, 1200);
$check($lateFee($id) === '2500.00', 'a late chauffeur rental adds the chauffeur rate: 1,500 + 1,000 = 2,500.00', (string) $lateFee($id));
$setting('LATE_RETURN_CHARGE', 'off');
$id = $outAndDue($vehicle(), '-26 hours');
$rentals->transition($id, 'return', $actor, null, 1200);
$check($lateFee($id) === null, 'LATE_RETURN_CHARGE=off adds nothing');
$setting('LATE_RETURN_CHARGE', 'on');

echo "== Damage recorded during the rental\n";
$recordDuring = static function (int $agreementId, string $severity) use ($db, $actor): void {
    $db->prepare("INSERT INTO damage_reports (agreement_id, phase, has_damage, location, damage_type, severity, recorded_by) VALUES (:id, 'during', 1, 'Front bumper', 'Dent', :severity, :actor)")
        ->execute(['id' => $agreementId, 'severity' => $severity, 'actor' => $actor]);
};
foreach (['severe' => 'out_of_service', 'moderate' => 'maintenance', 'minor' => 'available'] as $severity => $expected) {
    $v = $vehicle();
    $id = $outAndDue($v, '+1 day');
    $recordDuring($id, $severity);
    $rentals->transition($id, 'return', $actor, null, 1100);
    $check($status($v) === $expected, "{$severity} damage: the vehicle is {$expected} after return", $status($v));
}
$start = (new DateTimeImmutable('today +5 days', $manila))->format('Y-m-d');
$end = (new DateTimeImmutable('today +6 days', $manila))->format('Y-m-d');
$damaged = (int) $db->query("SELECT vehicle_id FROM vehicles WHERE plate_number LIKE 'RR-{$tag}-%' AND current_status = 'out_of_service' LIMIT 1")->fetchColumn();
try {
    $rentals->create(['customer_id' => $customers->create(['customer_type' => 'walk_in', 'full_name' => 'Return ' . $tag, 'phone' => '0917' . random_int(1000000, 9999999)], $actor), 'vehicle_id' => $damaged,
        'start_date' => $start, 'end_date' => $end, 'scheduled_pickup_at' => $start . 'T09:00', 'scheduled_return_at' => $end . 'T09:00', 'deposit_amount' => '0'], $actor);
    $check(false, 'an out-of-service vehicle cannot be booked', 'it was booked');
} catch (RuntimeException $error) {
    $check(true, 'an out-of-service vehicle cannot be booked');
}

echo "== Which vehicles a damage hold moves\n";
$db->beginTransaction();
$v = $vehicle();
$check($vehicles->holdForDamageInTransaction($v, 'minor', $actor) === null && $status($v) === 'available', 'minor damage leaves the vehicle available');
$check($vehicles->holdForDamageInTransaction($v, 'moderate', $actor) === 'maintenance', 'moderate damage puts it in maintenance');
$check($vehicles->holdForDamageInTransaction($v, 'severe', $actor) === 'out_of_service', 'severe damage overrides maintenance');
$db->prepare("UPDATE vehicles SET current_status = 'rented' WHERE vehicle_id = :id")->execute(['id' => $v]);
$check($vehicles->holdForDamageInTransaction($v, 'severe', $actor) === null && $status($v) === 'rented', 'a vehicle out on a rental is not moved');
$db->rollBack();

echo $failed === 0 ? "ALL {$passed} RETURN RULE CHECKS PASSED\n" : "{$failed} FAILED, {$passed} passed\n";
exit($failed === 0 ? 0 : 1);
