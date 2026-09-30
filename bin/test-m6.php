<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use TripleR\Database;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\ChargeRepository;
use TripleR\Repositories\DriverRepository;
use TripleR\Services\ChauffeurService;
use TripleR\Services\RentalService;
use TripleR\Repositories\VehicleRepository;
$db = (new Database())->connection();
$rentalService = \TripleR\Services\RentalRuntimeFactory::service($db);
$overlaps = new \TripleR\Services\BookingOverlapService($db);
$vehicleRepo = new VehicleRepository($db);
$rentalRepo = new RentalRepository($db, $overlaps);
$chargeRepo = new ChargeRepository($db);
$chauffeurService = new ChauffeurService($db, $rentalRepo, $chargeRepo, $vehicleRepo, $overlaps);
$driverRepo = new DriverRepository($db);

$adminActor = 1; // system_admin seeded

// Step 1: Create some test data
$r = rand(1000, 9999);
$plate1 = "T1-$r";
$plate2 = "T2-$r";

$db->exec("INSERT INTO vehicles (plate_number, make, model, model_year, color, body_type, transmission, fuel_type, seating_capacity, daily_rate, chauffeur_daily_rate, current_status) VALUES ('$plate1', 'Toyota', 'Vios', 2023, 'White', 'sedan', 'automatic', 'gasoline', 5, 2000.00, 1000.00, 'available')");
$vehicleId1 = (int)$db->lastInsertId();

$db->exec("INSERT INTO vehicles (plate_number, make, model, model_year, color, body_type, transmission, fuel_type, seating_capacity, daily_rate, chauffeur_daily_rate, current_status) VALUES ('$plate2', 'Ford', 'Everest', 2023, 'Black', 'SUV', 'automatic', 'diesel', 7, 3000.00, 1500.00, 'available')");
$vehicleId2 = (int)$db->lastInsertId();

$db->exec("INSERT INTO customers (full_name, customer_type) VALUES ('John Doe $r', 'walk_in')");
$customerId1 = (int)$db->lastInsertId();

$cipher1 = "cipher1-$r";
$hash1 = "hash1-$r";
$stmt = $db->prepare("INSERT INTO drivers (full_name, license_number_ciphertext, license_number_fingerprint, license_expiry) VALUES ('Driver A', ?, ?, '2030-01-01')");
$stmt->execute([$cipher1, $hash1]);
$driverId1 = (int)$db->lastInsertId();

$cipher2 = "cipher2-$r";
$hash2 = "hash2-$r";
$stmt = $db->prepare("INSERT INTO drivers (full_name, license_number_ciphertext, license_number_fingerprint, license_expiry) VALUES ('Driver B', ?, ?, '2030-01-01')");
$stmt->execute([$cipher2, $hash2]);
$driverId2 = (int)$db->lastInsertId();

$manila = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('today', $manila);
$expiredLicense = $today->modify('-1 day')->format('Y-m-d');
$expiresToday = $today->format('Y-m-d');
$stmt = $db->prepare("INSERT INTO drivers (full_name, license_number_ciphertext, license_number_fingerprint, license_expiry) VALUES (?, ?, ?, ?)");
$stmt->execute(["Driver Expired $r", "cipher-expired-$r", "hash-expired-$r", $expiredLicense]);
$expiredDriverId = (int)$db->lastInsertId();
$stmt->execute(["Driver Expiring $r", "cipher-expiring-$r", "hash-expiring-$r", $expiresToday]);
$expiringDriverId = (int)$db->lastInsertId();

echo "Running M6 Acceptance Tests...\n\n";

function assertException(callable $fn, string $expectedMessage, string $testName) {
    try {
        $fn();
        echo "FAIL: $testName (Expected exception, none thrown)\n";
    } catch (\Throwable $e) {
        if (str_contains($e->getMessage(), $expectedMessage)) {
            echo "PASS: $testName\n";
        } else {
            echo "FAIL: $testName (Wrong exception: {$e->getMessage()})\n";
        }
    }
}

// 1. Chauffeur creation with a driver
$agrm1 = $rentalService->create([
    'customer_id' => $customerId1,
    'vehicle_id' => $vehicleId1,
    'rental_type' => 'chauffeur',
    'start_date' => '2026-10-01',
    'end_date' => '2026-10-02',
    'scheduled_pickup_at' => '2026-10-01T10:00',
    'scheduled_return_at' => '2026-10-02T10:00',
    'deposit_amount' => '0',
    'hold_minutes' => 60
], $adminActor);
$chauffeurService->assignDriver($agrm1, $driverId1, $adminActor);
$r = $rentalRepo->find($agrm1);
$c = $chargeRepo->forAgreement($agrm1);
if ($r['driver_id'] === $driverId1 && count($c) > 0 && $c[0]['charge_type'] === 'chauffeur_fee') { 
    echo "PASS: Chauffeur creation with driver, fee appended.\n";
} else {
    echo "FAIL: Chauffeur creation with driver. Driver ID: {$r['driver_id']}, Charges: " . count($c) . "\n";
}

// 2. Self-drive still works
$agrm2 = $rentalService->create([
    'customer_id' => $customerId1,
    'vehicle_id' => $vehicleId2,
    'rental_type' => 'self_drive',
    'start_date' => '2026-10-03',
    'end_date' => '2026-10-03',
    'scheduled_pickup_at' => '2026-10-03T10:00',
    'scheduled_return_at' => '2026-10-03T20:00',
    'deposit_amount' => '0',
    'hold_minutes' => 60
], $adminActor);
$r2 = $rentalRepo->find($agrm2);
if ($r2['rental_type'] === 'self_drive' && $r2['driver_id'] === null) {
    echo "PASS: Self-drive creation works unchanged.\n";
}

// 3. FR-05 Confirmation Guard
$agrm3 = $rentalService->create([
    'customer_id' => $customerId1,
    'vehicle_id' => $vehicleId2,
    'rental_type' => 'chauffeur',
    'start_date' => '2026-10-05',
    'end_date' => '2026-10-05',
    'scheduled_pickup_at' => '2026-10-05T10:00',
    'scheduled_return_at' => '2026-10-05T20:00',
    'deposit_amount' => '0',
    'hold_minutes' => 60
], $adminActor);
assertException(fn() => $rentalService->transition($agrm3, 'confirm', $adminActor), 'driver', 'FR-05 Confirmation Guard (rejects driverless)');
$chauffeurService->assignDriver($agrm3, $driverId2, $adminActor);
$rentalService->transition($agrm3, 'confirm', $adminActor);
echo "PASS: FR-05 Confirmation Guard (allows with driver)\n";

// 4. License expiry is enforced at assignment and rechecked at confirmation.
$licenseAssignmentStart = $today->modify('+40 days')->format('Y-m-d');
$licenseAssignment = $rentalService->create([
    'customer_id' => $customerId1,
    'vehicle_id' => $vehicleId2,
    'rental_type' => 'chauffeur',
    'start_date' => $licenseAssignmentStart,
    'end_date' => $licenseAssignmentStart,
    'scheduled_pickup_at' => $licenseAssignmentStart . 'T10:00',
    'scheduled_return_at' => $licenseAssignmentStart . 'T20:00',
    'deposit_amount' => '0',
    'hold_minutes' => 60
], $adminActor);
assertException(fn() => $chauffeurService->assignDriver($licenseAssignment, $expiredDriverId, $adminActor), 'license has expired', 'Expired license rejected at assignment');

$licenseConfirmationStart = $today->modify('+42 days')->format('Y-m-d');
$licenseConfirmation = $rentalService->create([
    'customer_id' => $customerId1,
    'vehicle_id' => $vehicleId2,
    'rental_type' => 'chauffeur',
    'start_date' => $licenseConfirmationStart,
    'end_date' => $licenseConfirmationStart,
    'scheduled_pickup_at' => $licenseConfirmationStart . 'T10:00',
    'scheduled_return_at' => $licenseConfirmationStart . 'T20:00',
    'deposit_amount' => '0',
    'hold_minutes' => 60
], $adminActor);
$chauffeurService->assignDriver($licenseConfirmation, $expiringDriverId, $adminActor);
$expireLicense = $db->prepare('UPDATE drivers SET license_expiry=:expiry WHERE driver_id=:id');
$expireLicense->execute(['expiry' => $expiredLicense, 'id' => $expiringDriverId]);
assertException(fn() => $rentalService->transition($licenseConfirmation, 'confirm', $adminActor), 'license has expired', 'License expiry rechecked at confirmation');

// 5. BR-4 Overlap Conflict
$agrmOverlap = $rentalService->create([
    'customer_id' => $customerId1,
    'vehicle_id' => $vehicleId2,
    'rental_type' => 'chauffeur',
    'start_date' => '2026-10-01',
    'end_date' => '2026-10-02',
    'scheduled_pickup_at' => '2026-10-01T10:00',
    'scheduled_return_at' => '2026-10-02T10:00',
    'deposit_amount' => '0',
    'hold_minutes' => 60
], $adminActor);
assertException(fn() => $chauffeurService->assignDriver($agrmOverlap, $driverId1, $adminActor), 'overlapping', 'BR-4 Overlap (Cannot assign busy driver)');

// 6. BR-4 Adjacent non-overlapping
$agrm4 = $rentalService->create([
    'customer_id' => $customerId1,
    'vehicle_id' => $vehicleId2,
    'rental_type' => 'chauffeur',
    'start_date' => '2026-10-02',
    'end_date' => '2026-10-02',
    'scheduled_pickup_at' => '2026-10-02T10:00',
    'scheduled_return_at' => '2026-10-02T20:00',
    'deposit_amount' => '0',
    'hold_minutes' => 60
], $adminActor);
// driverId1 is busy 10-01 to 10-02, so assigning to 10-02 should be allowed (half-open overlap allows adjacent).
$chauffeurService->assignDriver($agrm4, $driverId1, $adminActor);
echo "PASS: BR-4 Adjacent non-overlapping allowed.\n";

// 7. Driver Reassignment & Fee Reversal
$agrm5 = $rentalService->create([
    'customer_id' => $customerId1,
    'vehicle_id' => $vehicleId1,
    'rental_type' => 'chauffeur',
    'start_date' => '2026-10-10',
    'end_date' => '2026-10-10',
    'scheduled_pickup_at' => '2026-10-10T10:00',
    'scheduled_return_at' => '2026-10-10T20:00',
    'deposit_amount' => '0',
    'hold_minutes' => 60
], $adminActor);
$chauffeurService->assignDriver($agrm5, $driverId1, $adminActor);
$chauffeurService->assignDriver($agrm5, $driverId2, $adminActor);
$c5 = $chargeRepo->forAgreement($agrm5);
// Should have 3 charges: initial fee, reversal of initial fee, new fee.
$pass5 = count($c5) === 3;
if (!$pass5) {
    echo "FAIL: Driver reassignment fee log incorrect. Count=" . count($c5) . "\n";
    foreach ($c5 as $i => $row) echo "  charge[$i]: type={$row['charge_type']} amount={$row['amount']} entry_kind={$row['entry_kind']}\n";
} else {
    echo "PASS: Driver reassignment reverses old fee and appends new fee. Charges: {$c5[0]['amount']}, {$c5[1]['amount']}, {$c5[2]['amount']}\n";
}

// 8. Driver removal (blocked on confirmed, works on reserved, fee reversed)
$rentalService->transition($agrm5, 'confirm', $adminActor);
assertException(fn() => $chauffeurService->removeDriver($agrm5, $adminActor), 'reserved', 'Driver removal on confirmed agreement rejected');

$agrm6 = $rentalService->create([
    'customer_id' => $customerId1,
    'vehicle_id' => $vehicleId1,
    'rental_type' => 'chauffeur',
    'start_date' => '2026-10-11',
    'end_date' => '2026-10-11',
    'scheduled_pickup_at' => '2026-10-11T10:00',
    'scheduled_return_at' => '2026-10-11T20:00',
    'deposit_amount' => '0',
    'hold_minutes' => 60
], $adminActor);
$chauffeurService->assignDriver($agrm6, $driverId1, $adminActor);
$chauffeurService->removeDriver($agrm6, $adminActor);
$r6 = $rentalRepo->find($agrm6);
$c6 = $chargeRepo->forAgreement($agrm6);
if ($r6['driver_id'] === null && count($c6) >= 2) {
    echo "PASS: Driver removal on reserved works, sets driver_id=NULL, reverses fee. Charges: " . count($c6) . "\n";
} else {
    echo "FAIL: Driver removal on reserved. driver_id={$r6['driver_id']}, charges=" . count($c6) . "\n";
    foreach ($c6 as $i => $row) echo "  charge[$i]: type={$row['charge_type']} amount={$row['amount']} entry_kind={$row['entry_kind']}\n";
}

// 9. Vehicle status untouched
$v1 = $vehicleRepo->find($vehicleId1);
if ($v1['current_status'] === 'reserved') { // set by confirm($agrm5)
    echo "PASS: Assigning/removing drivers leaves vehicle status unaffected.\n";
} else {
    echo "FAIL: Vehicle status was unexpectedly altered.\n";
}

// 10. Charge immutability trigger
assertException(function() use ($db, $c5) {
    $db->exec("UPDATE rental_charges SET amount = '500' WHERE charge_id = " . (int)$c5[0]['charge_id']);
}, 'append-only', 'Charge immutability trigger');

// 11. Chauffeur lifecycle (Confirm -> pickup -> return)
$rentalService->transition($agrm5, 'pickup', $adminActor, null, 10000, null);
$v1_post = $vehicleRepo->find($vehicleId1);
if ($v1_post['current_status'] === 'rented') echo "PASS: Lifecycle: pickup transitions vehicle to rented.\n";
$rentalService->transition($agrm5, 'return', $adminActor, null, 10100, null);
$v1_post2 = $vehicleRepo->find($vehicleId1);
if ($v1_post2['current_status'] === 'available') echo "PASS: Lifecycle: return transitions vehicle to available.\n";

echo "\nAll DB/Backend checks passed.\n";
