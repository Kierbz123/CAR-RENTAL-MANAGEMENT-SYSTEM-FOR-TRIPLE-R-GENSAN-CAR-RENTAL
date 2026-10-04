<?php
/**
 * Double-booking and booking-period rules:
 *   - a vehicle cannot be handed to a second customer before the first returns it, on any day,
 *     including the hand-off day (pickup and return times are compared, not just dates);
 *   - two requests assigning one driver to overlapping rentals at the same moment cannot both win;
 *   - a vehicle out on a rental can still be booked and confirmed for later dates;
 *   - a pickup at most RENTAL_MAX_BACKDATE_DAYS in the past, a rental at most RENTAL_MAX_DAYS long.
 *
 * The driver race runs two copies of this script as separate processes (works on Windows too).
 * Writes test vehicles, customers, drivers and agreements; run it against a test database.
 *   php bin/test-booking-integrity.php
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\ChargeRepository;
use TripleR\Repositories\CustomerRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Services\BookingOverlapService;
use TripleR\Services\ChauffeurService;
use TripleR\Services\CustomerPiiCipher;
use TripleR\Services\CustomerService;
use TripleR\Services\RentalRuntimeFactory;

require dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connection();

// Child mode: assign a driver once the parent's start time arrives, report the result, exit.
if (($argv[1] ?? '') === '--assign') {
    [$agreementId, $driverId, $actor, $startAt] = [(int) $argv[2], (int) $argv[3], (int) $argv[4], (float) $argv[5]];
    $overlaps = new BookingOverlapService($db);
    $chauffeurs = new ChauffeurService($db, new RentalRepository($db, $overlaps), new ChargeRepository($db), new VehicleRepository($db), $overlaps);
    // Both children start together; the parent holds the driver row so they meet at the same lock.
    while (microtime(true) < $startAt) {
        usleep(1000);
    }
    try {
        $chauffeurs->assignDriver($agreementId, $driverId, $actor);
        echo 'assigned';
    } catch (Throwable $error) {
        echo 'refused: ' . $error->getMessage();
    }
    exit(0);
}

$rentals = RentalRuntimeFactory::service($db);
$customers = new CustomerService($db, new CustomerRepository($db), new CustomerPiiCipher());
$manila = new DateTimeZone('Asia/Manila');
$day = static fn (string $modify): string => (new DateTimeImmutable('today', $manila))->modify($modify)->format('Y-m-d');
$tag = strtoupper(bin2hex(random_bytes(3)));
$actor = (int) $db->query("SELECT id FROM users WHERE role = 'system_admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$db->exec('SET @triple_r_actor_user_id = ' . $actor);
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label, string $detail = '') use (&$passed, &$failed): void {
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS' : 'FAIL') . ": {$label}" . ($condition || $detail === '' ? '' : " ({$detail})") . "\n";
};
$attempt = static function (callable $work): string {
    try {
        $work();
        return 'accepted';
    } catch (RuntimeException $error) {
        return $error->getMessage();
    }
};
$vehicle = static function (string $suffix, bool $chauffeur = false) use ($db, $tag): int {
    $db->prepare("INSERT INTO vehicles (plate_number, make, model, model_year, color, body_type, transmission, fuel_type, seating_capacity, daily_rate, chauffeur_daily_rate) VALUES (:plate, 'Test', 'Integrity', 2024, 'White', 'sedan', 'automatic', 'gasoline', 5, 2000.00, :chauffeur)")
        ->execute(['plate' => 'BI-' . $tag . '-' . $suffix, 'chauffeur' => $chauffeur ? '1000.00' : null]);
    return (int) $db->lastInsertId();
};
$customer = static fn (): int => $customers->create(['customer_type' => 'walk_in', 'full_name' => 'Integrity ' . $tag, 'phone' => '0917' . random_int(1000000, 9999999)], $actor);
$book = static function (int $vehicleId, string $start, string $end, string $pickup, string $return, string $type = 'self_drive') use ($rentals, $customer, $actor): int {
    return $rentals->create(['customer_id' => $customer(), 'vehicle_id' => $vehicleId, 'rental_type' => $type, 'start_date' => $start, 'end_date' => $end, 'scheduled_pickup_at' => $start . 'T' . $pickup, 'scheduled_return_at' => $end . 'T' . $return, 'deposit_amount' => '0'], $actor);
};
$confirm = static function (int $agreementId) use ($rentals, $actor): void {
    $rentals->recordDownpayment($agreementId, 'BI' . random_int(10000000, 99999999), $actor);
    $rentals->transition($agreementId, 'confirm', $actor);
};

echo "== Vehicle conflicts compare dates and times\n";
$v = $vehicle('A');
$book($v, $day('+10 days'), $day('+12 days'), '09:00', '20:00');
$check(str_contains($attempt(fn () => $book($v, $day('+12 days'), $day('+14 days'), '08:00', '10:00')), 'rental at that time'), 'next pickup at 08:00 before the 20:00 return on the hand-off day is refused');
$check($attempt(fn () => $book($v, $day('+12 days'), $day('+14 days'), '21:00', '10:00')) === 'accepted', 'next pickup at 21:00 after the 20:00 return on the hand-off day is allowed');
$check(str_contains($attempt(fn () => $book($v, $day('+11 days'), $day('+13 days'), '09:00', '09:00')), 'rental at that time'), 'overlapping dates are refused');

echo "== Booking period limits\n";
$v = $vehicle('B');
$check(str_contains($attempt(fn () => $book($v, $day('-30 days'), $day('-28 days'), '09:00', '09:00')), 'in the past'), 'a pickup a month in the past is refused');
$check($attempt(fn () => $book($v, $day('-2 days'), $day('-1 day'), '09:00', '09:00')) === 'accepted', 'a pickup two days ago can be written down');
$check(str_contains($attempt(fn () => $book($v, $day('+40 days'), $day('+400 days'), '09:00', '09:00')), 'at most'), 'a rental longer than the maximum is refused');

echo "== A vehicle out on a rental can be booked for later\n";
$v = $vehicle('C');
$now = $book($v, $day('+0 days'), $day('+2 days'), '23:30', '10:00');
$confirm($now);
$rentals->transition($now, 'pickup', $actor, null, 1000);
$later = 0;
$result = $attempt(function () use (&$later, $book, $v, $day): void { $later = $book($v, $day('+20 days'), $day('+22 days'), '09:00', '09:00'); });
$check($result === 'accepted', 'a non-overlapping future booking is accepted while the vehicle is rented', $result);
$result = $later > 0 ? $attempt(fn () => $confirm($later)) : 'not booked';
$check($result === 'accepted', 'that booking can be confirmed while the vehicle is still out', $result);
$rentals->transition($now, 'return', $actor, null, 1100);
$status = $db->query('SELECT current_status FROM vehicles WHERE vehicle_id = ' . $v)->fetchColumn();
$check($status === 'reserved', 'returning the vehicle leaves it reserved for the confirmed booking', (string) $status);

echo "== The downpayment covers the chauffeur rate\n";
$v = $vehicle('F', true);
$chauffeurBooking = $book($v, $day('+40 days'), $day('+42 days'), '09:00', '09:00', 'chauffeur');
$row = $db->query('SELECT downpayment_amount, chauffeur_daily_rate FROM rental_agreements WHERE agreement_id = ' . $chauffeurBooking)->fetch();
$check($row['downpayment_amount'] === '1800.00' && $row['chauffeur_daily_rate'] === '1000.00', '2 days x (2,000 vehicle + 1,000 chauffeur) = 6,000; 30% = 1,800.00', json_encode($row));
$db->prepare('UPDATE vehicles SET chauffeur_daily_rate = 1500.00 WHERE vehicle_id = :id')->execute(['id' => $v]);
$db->prepare("INSERT INTO drivers (full_name, license_number_ciphertext, license_number_fingerprint, license_expiry) VALUES (:name, :cipher, :fingerprint, :expiry)")
    ->execute(['name' => 'Rate Driver ' . $tag, 'cipher' => 'rate-' . $tag, 'fingerprint' => hash('sha256', 'rate-' . $tag), 'expiry' => $day('+1 year')]);
(new ChauffeurService($db, new RentalRepository($db, new BookingOverlapService($db)), new ChargeRepository($db), new VehicleRepository($db), new BookingOverlapService($db)))->assignDriver($chauffeurBooking, (int) $db->lastInsertId(), $actor);
$fee = $db->query("SELECT amount FROM rental_charges WHERE agreement_id = {$chauffeurBooking} AND charge_type = 'chauffeur_fee'")->fetchColumn();
$check($fee === '2000.00', 'the chauffeur fee uses the rate fixed at booking, not the vehicle\'s later rate', (string) $fee);
try {
    $db->exec("UPDATE rental_agreements SET chauffeur_daily_rate = 1.00 WHERE agreement_id = {$chauffeurBooking}");
    $check(false, 'a booking\'s rates cannot be changed afterwards', 'the update was accepted');
} catch (PDOException $error) {
    $check(str_contains($error->getMessage(), 'fixed when it is made'), 'a booking\'s rates cannot be changed afterwards', $error->getMessage());
}

echo "== Two requests assigning one driver at the same moment\n";
$db->prepare("INSERT INTO drivers (full_name, license_number_ciphertext, license_number_fingerprint, license_expiry) VALUES (:name, :cipher, :fingerprint, :expiry)")
    ->execute(['name' => 'Integrity Driver ' . $tag, 'cipher' => 'test-' . $tag, 'fingerprint' => hash('sha256', 'integrity-' . $tag), 'expiry' => $day('+1 year')]);
$driverId = (int) $db->lastInsertId();
$first = $book($vehicle('D', true), $day('+30 days'), $day('+32 days'), '09:00', '09:00', 'chauffeur');
$second = $book($vehicle('E', true), $day('+31 days'), $day('+33 days'), '09:00', '09:00', 'chauffeur');
// Hold the driver row so both requests start and wait at the same point, then let them go.
$db->beginTransaction();
$db->query('SELECT driver_id FROM drivers WHERE driver_id = ' . $driverId . ' FOR UPDATE')->fetch();
$startAt = microtime(true) + 1.0;
$children = [];
foreach ([$first, $second] as $agreementId) {
    $process = proc_open([PHP_BINARY, __FILE__, '--assign', (string) $agreementId, (string) $driverId, (string) $actor, (string) $startAt], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $children[] = [$process, $pipes];
}
usleep(2_500_000);
$db->commit();
$outcomes = [];
foreach ($children as [$process, $pipes]) {
    $outcomes[] = trim((string) stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    proc_close($process);
}
$assigned = count(array_filter($outcomes, static fn (string $o): bool => $o === 'assigned'));
$holding = (int) $db->query('SELECT COUNT(*) FROM rental_agreements WHERE driver_id = ' . $driverId)->fetchColumn();
$check($assigned === 1 && $holding === 1, 'exactly one of the two simultaneous assignments wins', implode(' | ', $outcomes));

echo $failed === 0 ? "ALL {$passed} BOOKING INTEGRITY CHECKS PASSED\n" : "{$failed} FAILED, {$passed} passed\n";
exit($failed === 0 ? 0 : 1);
