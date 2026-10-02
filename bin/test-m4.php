<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use TripleR\Database;
use TripleR\Repositories\DriverRepository;
use TripleR\Repositories\ChargeRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Services\BookingOverlapService;
use TripleR\Services\DriverPiiCipher;
use TripleR\Services\DriverService;
use TripleR\Services\ChauffeurService;
use TripleR\Services\RentalRuntimeFactory;

$db = Database::connection();
$guardDb = Database::migrationConnection();
$repo = new DriverRepository($db);
$cipher = new DriverPiiCipher();
$service = new DriverService($db, $repo, $cipher);
$failures = 0;
$tag = bin2hex(random_bytes(6));
$manila = new DateTimeZone('Asia/Manila');
$today = new DateTimeImmutable('today', $manila);
$todayText = $today->format('Y-m-d');

function checkResult(bool $condition, string $name, string $detail = ''): void
{
    global $failures;
    if ($condition) {
        echo "PASS: {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL: {$name}" . ($detail === '' ? '' : " ({$detail})") . "\n";
}

function expectFailure(callable $operation, string $messagePart, string $name): void
{
    global $failures;
    try {
        $operation();
        $failures++;
        echo "FAIL: {$name} (operation unexpectedly succeeded)\n";
    } catch (Throwable $error) {
        if (str_contains($error->getMessage(), $messagePart)) {
            echo "PASS: {$name}\n";
        } else {
            $failures++;
            echo "FAIL: {$name} (unexpected error: {$error->getMessage()})\n";
        }
    }
}

echo "Running M4 driver runtime checks...\n";

$license = 'M4-' . strtoupper($tag) . '- 1234';
$driverId = $service->create([
    'full_name' => 'M4 Runtime ' . $tag,
    'license_number' => $license,
    'license_expiry' => $todayText,
], 1);
$driver = $repo->find($driverId);
$revealed = $service->reveal($driverId, 'license');
checkResult($revealed === $license, 'Encrypted license round-trips through the authorized service');
checkResult($service->masked($driver['license_number_ciphertext'], 'license') === '****1234', 'License mask reveals only the final four normalized characters');
$selectableIdsAtStart = array_map('intval', array_column($service->selectableForAssignment(), 'driver_id'));
checkResult(in_array($driverId, $selectableIdsAtStart, true), 'Active driver with license valid today is selectable');

expectFailure(function () use ($service, $tag, $todayText): void {
    $service->create([
        'full_name' => 'M4 Duplicate ' . $tag,
        'license_number' => 'm4 ' . strtolower($tag) . ' 1234',
        'license_expiry' => $todayText,
    ], 1);
}, 'Duplicate', 'License fingerprint rejects formatting-equivalent duplicate');

$updatedName = 'M4 Updated ' . $tag;
$service->update($driverId, ['full_name' => $updatedName, 'license_number' => '', 'license_expiry' => $todayText, 'address' => 'Updated test address']);
$updatedDriver = $repo->find($driverId);
checkResult($updatedDriver['full_name'] === $updatedName && $service->reveal($driverId, 'license') === $license, 'Driver edit preserves encrypted license and updates profile');
checkResult($service->reveal($driverId, 'address') === 'Updated test address', 'Driver edit stores updated address encrypted');

$phonePrimaryId = $service->addContact($driverId, 'phone', '+63 917 123 4567', true);
$phoneSecondaryId = $service->addContact($driverId, 'phone', '+63 917 987 6543', false);
$contactDb = new PDO(
    'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
    (string)getenv('DB_USER'),
    (string)getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$contactDb->exec("SET time_zone = '+00:00'");
$contactDb->exec('SET SESSION innodb_lock_wait_timeout=1');
$db->beginTransaction();
$repo->find($driverId, true);
$contendedRepo = new DriverRepository($contactDb);
$contendedService = new DriverService($contactDb, $contendedRepo, $cipher);
expectFailure(fn() => $contendedService->updateContact($driverId, $phoneSecondaryId, 'phone', '+63 917 987 6543', true), 'Lock wait timeout', 'Concurrent primary-contact update waits on the driver row lock');
$db->commit();
$contendedService->updateContact($driverId, $phoneSecondaryId, 'phone', '+63 917 987 6543', true);
$phoneContacts = array_values(array_filter($repo->contacts($driverId), static fn(array $contact): bool => $contact['contact_type'] === 'phone'));
$primaryPhones = array_values(array_filter($phoneContacts, static fn(array $contact): bool => (int)$contact['is_primary'] === 1));
checkResult(count($primaryPhones) === 1 && (int)$primaryPhones[0]['contact_id'] === $phoneSecondaryId && $phonePrimaryId !== $phoneSecondaryId, 'Serialized primary-contact updates leave exactly one primary');

$expiredId = $service->create([
    'full_name' => 'M4 Expired ' . $tag,
    'license_number' => 'M4-EXPIRED-' . strtoupper($tag),
    'license_expiry' => $today->modify('-1 day')->format('Y-m-d'),
], 1);
$selectableIds = array_map('intval', array_column($service->selectableForAssignment(), 'driver_id'));
checkResult(!in_array($expiredId, $selectableIds, true), 'Expired license is excluded from assignment selection');

$available = array_map('intval', array_column($service->availableForAssignment(
    $today->modify('+10 days')->format('Y-m-d'),
    $today->modify('+11 days')->format('Y-m-d')
), 'driver_id'));
checkResult(in_array($driverId, $available, true), 'Date-filtered assignment list includes an eligible non-conflicting driver');

$inactiveId = $service->create([
    'full_name' => 'M4 Inactive ' . $tag,
    'license_number' => 'M4-INACTIVE-' . strtoupper($tag),
    'license_expiry' => $todayText,
], 1);
$service->changeStatus($inactiveId, 'inactive', 1);
$selectedAfterInactive = array_map('intval', array_column($service->selectableForAssignment(), 'driver_id'));
checkResult(!in_array($inactiveId, $selectedAfterInactive, true), 'Inactive driver is excluded from assignment selection');
$service->changeStatus($inactiveId, 'active', 1);
$service->softDelete($inactiveId);
$selectedAfterDelete = array_map('intval', array_column($service->selectableForAssignment(), 'driver_id'));
checkResult(!in_array($inactiveId, $selectedAfterDelete, true), 'Soft-deleted driver is excluded from assignment selection');

$service->changeStatus($driverId, 'inactive', 1);
$service->changeStatus($driverId, 'active', 1);
$history = $db->prepare('SELECT status_log_id FROM status_logs WHERE driver_id=:id AND subject=\'driver\' ORDER BY status_log_id');
$history->execute(['id' => $driverId]);
$logIds = array_map('intval', $history->fetchAll(PDO::FETCH_COLUMN));
checkResult(count($logIds) === 3, 'Driver status changes append status history');
if ($logIds !== []) {
    expectFailure(fn() => $guardDb->exec('UPDATE status_logs SET new_status=new_status WHERE status_log_id=' . $logIds[0]), 'append-only', 'Status history rejects direct UPDATE');
    expectFailure(fn() => $guardDb->exec('DELETE FROM status_logs WHERE status_log_id=' . $logIds[0]), 'append-only', 'Status history rejects direct DELETE');
}

$rentalService = RentalRuntimeFactory::service($db);
$overlaps = new BookingOverlapService($db);
$chauffeurService = new ChauffeurService($db, new RentalRepository($db, $overlaps), new ChargeRepository($db), new VehicleRepository($db), $overlaps);
$plate = 'M4-' . strtoupper($tag);
$db->prepare("INSERT INTO vehicles (plate_number,make,model,model_year,color,body_type,transmission,fuel_type,seating_capacity,daily_rate,chauffeur_daily_rate,current_status) VALUES (:plate,'Toyota','Vios',2024,'White','sedan','automatic','gasoline',5,2000.00,1000.00,'available')")
    ->execute(['plate' => $plate]);
$vehicleId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO customers (full_name,customer_type) VALUES (:name,'walk_in')")->execute(['name' => 'M4 Open Agreement ' . $tag]);
$customerId = (int)$db->lastInsertId();
$rentalDate = $today->modify('+20 days')->format('Y-m-d');
$agreementId = $rentalService->create([
    'customer_id' => $customerId,
    'vehicle_id' => $vehicleId,
    'rental_type' => 'chauffeur',
    'start_date' => $rentalDate,
    'end_date' => $rentalDate,
    'scheduled_pickup_at' => $rentalDate . 'T10:00',
    'scheduled_return_at' => $rentalDate . 'T20:00',
    'deposit_amount' => '0',
    'hold_minutes' => 60,
], 1);
$chauffeurService->assignDriver($agreementId, $driverId, 1);
expectFailure(fn() => $service->softDelete($driverId), 'open rental agreement', 'Driver soft-delete is blocked while a reserved agreement references the driver');

if ($failures > 0) {
    fwrite(STDERR, "M4 runtime checks failed: {$failures}\n");
    exit(1);
}
echo "All M4 driver runtime checks passed.\n";
