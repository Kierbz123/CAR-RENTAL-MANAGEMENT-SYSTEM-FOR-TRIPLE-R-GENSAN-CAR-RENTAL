<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use TripleR\Database;

$db = Database::connection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$tag = bin2hex(random_bytes(5));
$failures = 0;

function reportCheck(bool $passed, string $name, string $detail = ''): void
{
    global $failures;
    if ($passed) {
        echo "PASS: {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL: {$name}" . ($detail === '' ? '' : " ({$detail})") . "\n";
}

function expectSqlRejection(PDO $db, string $sql, string $messagePart, string $name): void
{
    try {
        $db->exec($sql);
        reportCheck(false, $name, 'statement unexpectedly succeeded');
    } catch (PDOException $error) {
        reportCheck(str_contains($error->getMessage(), $messagePart), $name, $error->getMessage());
    }
}

echo "Running migration 009 raw-SQL guard checks...\n";

$db->exec("INSERT INTO vehicles (plate_number, make, model, model_year, color, body_type, transmission, fuel_type, seating_capacity, daily_rate, chauffeur_daily_rate, current_status) VALUES ('RAW-$tag', 'Toyota', 'Vios', 2023, 'White', 'sedan', 'automatic', 'gasoline', 5, 2000.00, 1000.00, 'rented')");
$vehicleId = (int)$db->lastInsertId();
$db->exec("INSERT INTO customers (full_name, customer_type) VALUES ('Raw Guard $tag', 'walk_in')");
$customerId = (int)$db->lastInsertId();
$driverInsert = $db->prepare("INSERT INTO drivers (full_name, license_number_ciphertext, license_number_fingerprint, license_expiry) VALUES (?, ?, ?, '2030-01-01')");
$driverInsert->execute(["Guard A $tag", "cipher-a-$tag", hash('sha256', "fingerprint-a-$tag")]);
$driverA = (int)$db->lastInsertId();
$driverInsert->execute(["Guard B $tag", "cipher-b-$tag", hash('sha256', "fingerprint-b-$tag")]);
$driverB = (int)$db->lastInsertId();

$agreementInsert = $db->prepare("INSERT INTO rental_agreements (customer_id, vehicle_id, rental_type, start_date, end_date, daily_rate, status, driver_id, created_by_user_id) VALUES (:customer, :vehicle, :type, :start, :end, '2000.00', :status, :driver, 1)");
$createAgreement = static function (string $date, string $type, string $status, ?int $driverId) use ($db, $agreementInsert, $customerId, $vehicleId): int {
    $agreementInsert->execute(['customer' => $customerId, 'vehicle' => $vehicleId, 'type' => $type, 'start' => $date, 'end' => $date, 'status' => $status, 'driver' => $driverId]);
    return (int)$db->lastInsertId();
};

$activeId = $createAgreement('2026-11-01', 'chauffeur', 'active', $driverA);
expectSqlRejection($db, "UPDATE rental_agreements SET driver_id=$driverB WHERE agreement_id=$activeId", 'immutable after confirmation/pickup', 'Active agreement rejects raw driver reassignment');

try {
    $db->exec("UPDATE rental_agreements SET actual_return_at=UTC_TIMESTAMP(6) WHERE agreement_id=$activeId");
    $actualReturn = $db->query("SELECT actual_return_at FROM rental_agreements WHERE agreement_id=$activeId")->fetchColumn();
    reportCheck($actualReturn !== false && $actualReturn !== null, 'Unrelated update on active agreement succeeds');
} catch (PDOException $error) {
    reportCheck(false, 'Unrelated update on active agreement succeeds', $error->getMessage());
}

$completedId = $createAgreement('2026-11-02', 'chauffeur', 'completed', $driverA);
expectSqlRejection($db, "UPDATE rental_agreements SET driver_id=$driverB WHERE agreement_id=$completedId", 'immutable after confirmation/pickup', 'Completed agreement rejects raw driver reassignment');

expectSqlRejection(
    $db,
    "INSERT INTO rental_agreements (customer_id, vehicle_id, rental_type, start_date, end_date, daily_rate, status, driver_id, created_by_user_id) VALUES ($customerId, $vehicleId, 'chauffeur', '2026-11-03', '2026-11-03', '2000.00', 'active', NULL, 1)",
    'chk_rentals_chauffeur_driver',
    'CHECK rejects chauffeur insert with active status and NULL driver'
);

$reservedId = $createAgreement('2026-11-04', 'chauffeur', 'reserved', $driverA);
$db->exec("UPDATE rental_agreements SET driver_id=NULL WHERE agreement_id=$reservedId");
expectSqlRejection($db, "UPDATE rental_agreements SET status='confirmed' WHERE agreement_id=$reservedId", 'chk_rentals_chauffeur_driver', 'CHECK rejects confirmation update with NULL driver');

try {
    $reservedNullId = $createAgreement('2026-11-05', 'chauffeur', 'reserved', null);
    reportCheck($reservedNullId > 0, 'CHECK permits reserved chauffeur agreement with NULL driver');
} catch (PDOException $error) {
    reportCheck(false, 'CHECK permits reserved chauffeur agreement with NULL driver', $error->getMessage());
}

try {
    $selfDriveId = $createAgreement('2026-11-06', 'self_drive', 'active', null);
    reportCheck($selfDriveId > 0, 'CHECK permits active self-drive agreement with NULL driver');
} catch (PDOException $error) {
    reportCheck(false, 'CHECK permits active self-drive agreement with NULL driver', $error->getMessage());
}

if ($failures > 0) {
    fwrite(STDERR, "Migration 009 raw-SQL checks failed: {$failures}\n");
    exit(1);
}
echo "All migration 009 raw-SQL checks passed.\n";
