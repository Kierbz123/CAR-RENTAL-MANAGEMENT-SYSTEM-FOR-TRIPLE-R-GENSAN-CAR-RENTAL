<?php
/**
 * Live vehicle tracking: the link a phone opens, the positions it reports, what the live map
 * is told (live, last seen, overdue, outside the service area), and the database's own rules.
 *
 * Writes test vehicles, a customer and agreements; run it against a test database.
 *   php bin/test-tracking.php
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\CustomerRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\SecurityLogRepository;
use TripleR\Repositories\VehicleTrackingRepository;
use TripleR\Services\BookingOverlapService;
use TripleR\Services\CustomerPiiCipher;
use TripleR\Services\CustomerService;
use TripleR\Services\RateLimiter;
use TripleR\Services\RentalRuntimeFactory;
use TripleR\Services\VehicleTrackingService;

require dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connection();
$rentals = RentalRuntimeFactory::service($db);
$tracking = new VehicleTrackingService(new VehicleTrackingRepository($db), new RentalRepository($db, new BookingOverlapService($db)), new RateLimiter($db), new SecurityLogRepository($db));
$settings = $tracking->settings();

$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label, string $detail = '') use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}" . ($detail === '' ? '' : " ({$detail})") . "\n";
};
$refused = static function (callable $work, string $needle, string $label) use ($check): void {
    try {
        $work();
        $check(false, $label, 'it was accepted');
    } catch (RuntimeException | PDOException $error) {
        $check(str_contains(strtolower($error->getMessage()), strtolower($needle)), $label, $error->getMessage());
    }
};

$run = strtoupper(bin2hex(random_bytes(3)));
$actor = (int) $db->query("SELECT id FROM users WHERE role = 'system_admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$customers = new CustomerRepository($db);
$customerId = $customers->create(['customer_type' => 'walk_in', 'full_name' => 'Tracking Test ' . $run, 'company_name' => null, 'referral_source' => null]);
(new CustomerService($db, $customers, new CustomerPiiCipher()))->addContact($customerId, 'phone', '09' . str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT), true);

$vehicleNumber = 0;
$book = static function () use ($db, $rentals, $run, $customerId, $actor, &$vehicleNumber): int {
    $insert = $db->prepare("INSERT INTO vehicles (plate_number, make, model, model_year, color, body_type, transmission, fuel_type, seating_capacity, daily_rate, current_status) VALUES (:plate, 'Toyota', 'Vios', 2024, 'White', 'sedan', 'automatic', 'gasoline', 5, '2000.00', 'available')");
    $insert->execute(['plate' => 'TK' . $run . (++$vehicleNumber)]);
    $manila = new DateTimeZone('Asia/Manila');
    $start = (new DateTimeImmutable('+10 days', $manila))->format('Y-m-d');
    $end = (new DateTimeImmutable('+12 days', $manila))->format('Y-m-d');
    return $rentals->create(['customer_id' => $customerId, 'vehicle_id' => (int) $db->lastInsertId(), 'rental_type' => 'self_drive', 'start_date' => $start, 'end_date' => $end, 'scheduled_pickup_at' => $start . 'T09:00', 'scheduled_return_at' => $end . 'T09:00', 'deposit_amount' => '0'], $actor);
};
$tokenOf = static fn (string $link): string => substr($link, strpos($link, '#t=') + 3);
$position = static function (int $agreementId) use ($db): array {
    $statement = $db->prepare('SELECT p.* FROM vehicle_positions p JOIN rental_agreements r ON r.vehicle_id = p.vehicle_id WHERE r.agreement_id = :id');
    $statement->execute(['id' => $agreementId]);
    return $statement->fetch() ?: [];
};
$entry = static function (int $agreementId) use ($tracking): ?array {
    foreach ($tracking->feed() as $vehicle) {
        if ($vehicle['agreement_id'] === $agreementId) {
            return $vehicle;
        }
    }
    return null;
};
$count = static fn (string $sql): int => (int) $db->query($sql)->fetchColumn();
// A point in General Santos, and one about 220 metres north-east of it.
$here = ['latitude' => 6.116400, 'longitude' => 125.171600, 'accuracy' => 8.4, 'speed' => null, 'heading' => null];
$there = ['latitude' => 6.117800, 'longitude' => 125.173000, 'accuracy' => 6.0, 'speed' => 11.5, 'heading' => 44.6];

echo "Tracking acceptance (run {$run})\n\n== Connecting a phone\n";
$a = $book();
$refused(fn () => $tracking->createLink($a), 'once the reservation is confirmed', 'a phone cannot be connected to a reservation that is not confirmed yet');
$rentals->recordDownpayment($a, '', $actor, 'cash');
$rentals->transition($a, 'confirm', $actor);
$link = $tracking->createLink($a);
$token = $tokenOf($link);
$check(preg_match('#/track\#t=[A-Za-z0-9_-]{43}$#', $link) === 1 && $tracking->isConnected($a), 'a confirmed rental gets a tracker link, with the token after the "#" so it never reaches a server log', $link);
$check($count("SELECT COUNT(*) FROM booking_access_tokens WHERE booking_id = {$a} AND purpose = 'vehicle_tracker' AND token_hash = '" . hash('sha256', $token) . "'") === 1 && $count("SELECT COUNT(*) FROM booking_access_tokens WHERE token_hash = '{$token}'") === 0, 'only the hash of the token is stored');
$session = $tracking->session($token);
$check(($session['state'] ?? '') === 'waiting' && str_contains($session['vehicle'], 'TK' . $run . '1') && $session['interval'] === (int) $settings['interval_seconds'], 'the phone is told which vehicle it is for, and that sharing waits for the pickup');
$check($tracking->report($token, $here) === 'waiting' && $position($a) === [], 'a position sent before the pickup is not saved');

echo "\n== Reporting positions\n";
$rentals->transition($a, 'pickup', $actor, null, 100);
$check($entry($a) !== null && $entry($a)['state'] === 'none' && $entry($a)['status'] === 'Waiting for the phone' && $entry($a)['position'] === null, 'after pickup the vehicle is listed as out, with a phone connected and no position yet');
$check($tracking->report($token, $here) === 'tracking', 'the first position is accepted');
$first = $position($a);
$check(($first['latitude'] ?? '') === '6.116400' && $first['longitude'] === '125.171600' && (int) $first['accuracy_m'] === 8 && (int) $first['agreement_id'] === $a && $first['stopped_since'] === null, 'it is stored with its accuracy and the rental it belongs to');
$db->exec("UPDATE vehicle_positions SET recorded_at = DATE_SUB(recorded_at, INTERVAL 10 SECOND) WHERE agreement_id = {$a}");
$tracking->report($token, $there);
$second = $position($a);
$check($count("SELECT COUNT(*) FROM vehicle_positions WHERE agreement_id = {$a}") === 1 && $second['latitude'] === '6.117800', 'a newer position replaces the old one: one row per vehicle, no trail');
$check($second['speed_kph'] === '41.4' && (int) $second['heading_degrees'] === 45, 'speed and heading come from the phone when it gives them (11.5 m/s is 41.4 km/h)', $second['speed_kph'] . ' km/h, ' . $second['heading_degrees']);
$db->exec("UPDATE vehicle_positions SET recorded_at = DATE_SUB(recorded_at, INTERVAL 20 SECOND) WHERE agreement_id = {$a}");
$tracking->report($token, ['latitude' => $here['latitude'], 'longitude' => $here['longitude']]);
$third = $position($a);
$check((float) $third['speed_kph'] > 30 && (float) $third['speed_kph'] < 50 && (int) $third['heading_degrees'] > 215 && (int) $third['heading_degrees'] < 235, 'when the phone gives neither, they are worked out from the last position (about 220 m in 20 s, heading south-west)', $third['speed_kph'] . ' km/h, ' . $third['heading_degrees']);
$db->exec("UPDATE vehicle_positions SET recorded_at = DATE_SUB(recorded_at, INTERVAL 30 SECOND) WHERE agreement_id = {$a}");
$tracking->report($token, ['latitude' => 6.116405, 'longitude' => 125.171603]);
$still = $position($a);
$check($still['stopped_since'] !== null && $still['speed_kph'] === '0.0', 'a phone that has barely moved is recorded as stopped');
$live = $entry($a);
$check($live['state'] === 'live' && $live['tone'] === 'success' && str_starts_with($live['status'], 'Live') && $live['alerts'] === [] && abs($live['position']['latitude'] - 6.116405) < 0.000001, 'the map is told the vehicle is live, where it is, and nothing is wrong');
$refused(fn () => $tracking->report($token, ['latitude' => 91, 'longitude' => 125]), 'not a position on the map', 'a latitude that does not exist is refused');
$refused(fn () => $tracking->report($token, ['latitude' => 'north', 'longitude' => 125]), 'not a position on the map', 'a position that is not a number is refused');
$refused(fn () => $tracking->report($token, []), 'not a position on the map', 'an empty report is refused');

echo "\n== What the map is told when something is wrong\n";
$db->exec("UPDATE vehicle_positions SET recorded_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE), stopped_since = NULL WHERE agreement_id = {$a}");
$quiet = $entry($a);
$check($quiet['state'] === 'quiet' && $quiet['tone'] === 'neutral' && $quiet['status'] === 'Last seen 5 minutes ago', 'no report for 5 minutes: the vehicle is shown as last seen, not as still moving', $quiet['status']);
$tracking->report($token, ['latitude' => 14.5995, 'longitude' => 120.9842]);
$far = $entry($a);
$check($far['tone'] === 'warning' && str_starts_with($far['alerts'][0] ?? '', 'Outside the service area, ') && (int) preg_replace('/\D+/', '', $far['alerts'][0]) > 900, 'a vehicle in Manila is flagged as outside the service area, with the distance', $far['alerts'][0] ?? 'no alert');
$tracking->report($token, $here);
$overdueShown = false;
try {
    $db->exec("UPDATE rental_agreements SET scheduled_pickup_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 3 DAY), scheduled_return_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 130 MINUTE) WHERE agreement_id = {$a}");
    $late = $entry($a);
    $overdueShown = $late['tone'] === 'danger' && $late['alerts'] === ['Overdue by 2 h 10 min'];
    $check($overdueShown, 'a vehicle past its return time is flagged as overdue, by how long', implode('; ', $late['alerts']));
    $check($tracking->feed()[0]['agreement_id'] === $a || $count("SELECT COUNT(*) FROM rental_agreements WHERE status = 'active' AND scheduled_return_at < (SELECT scheduled_return_at FROM rental_agreements WHERE agreement_id = {$a})") > 0, 'the longest overdue vehicle is listed first');
} catch (PDOException $error) {
    $check(false, 'the return time could be moved into the past for this check', $error->getMessage());
}

echo "\n== Links: one phone per rental, and only its own rental\n";
$rejectedBefore = $count("SELECT COUNT(*) FROM security_logs WHERE event_type = 'tracker.link_rejected'");
$check($tracking->session('not-a-real-token') === null && $tracking->report(str_repeat('A', 43), $here, '203.0.113.9', 'stranger') === null, 'a made-up token opens nothing and saves nothing');
$check($count("SELECT COUNT(*) FROM security_logs WHERE event_type = 'tracker.link_rejected'") === $rejectedBefore + 2, 'and each attempt is written to the security log');
$newLink = $tracking->createLink($a);
$check($tracking->report($token, $there) === null && $tracking->report($tokenOf($newLink), $there) === 'tracking', 'making a new link switches the old phone off and the new one on');
$b = $book();
$rentals->recordDownpayment($b, '', $actor, 'cash');
$rentals->transition($b, 'confirm', $actor);
$rentals->transition($b, 'pickup', $actor, null, 100);
$tokenB = $tokenOf($tracking->createLink($b));
$tracking->report($tokenB, ['latitude' => 6.2, 'longitude' => 125.2]);
$check($position($a)['latitude'] === '6.117800' && $position($b)['latitude'] === '6.200000', 'each phone moves only its own vehicle');
$check($tracking->disconnect($b) === true && $tracking->isConnected($b) === false && $tracking->report($tokenB, $here) === null && $entry($b)['connected'] === false, 'staff can disconnect a phone; its reports are then refused');

echo "\n== When the rental ends\n";
$rentals->transition($a, 'return', $actor, null, 400);
$check($entry($a) === null, 'a returned vehicle leaves the live map at once');
$check($tracking->report($tokenOf($newLink), $here) === 'ended' && $position($a)['latitude'] === '6.117800' && $tracking->session($tokenOf($newLink))['state'] === 'ended', 'its phone is told the rental has ended, and no further position is saved');
$refused(fn () => $tracking->createLink($a), 'until the vehicle is returned', 'a phone cannot be connected to a rental that is over');

echo "\n== The database's own rules\n";
$vehicleA = (int) $position($a)['vehicle_id'];
$refused(fn () => $db->exec("UPDATE vehicle_positions SET latitude = 95 WHERE vehicle_id = {$vehicleA}"), 'chk_vehicle_positions_place', 'a latitude outside the map is refused');
$refused(fn () => $db->exec("UPDATE vehicle_positions SET heading_degrees = 360 WHERE vehicle_id = {$vehicleA}"), 'chk_vehicle_positions_heading', 'a heading of 360 or more is refused');
$refused(fn () => $db->exec("UPDATE vehicle_positions SET speed_kph = -1 WHERE vehicle_id = {$vehicleA}"), 'chk_vehicle_positions_speed', 'a negative speed is refused');
$refused(fn () => $db->exec("INSERT INTO vehicle_positions (vehicle_id, agreement_id, latitude, longitude, recorded_at) VALUES ({$vehicleA}, {$a}, 6, 125, UTC_TIMESTAMP(6))"), 'duplicate', 'a vehicle has one position row, never two');

echo "\n" . ($failed === 0 ? "ALL {$passed} TRACKING CHECKS PASSED" : "{$failed} FAILED, {$passed} passed") . "\n";
exit($failed === 0 ? 0 : 1);
