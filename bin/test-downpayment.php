<?php
/**
 * The 30% downpayment: worked out and stored at booking, recorded by finance as a payment
 * (GCash or another method with its reference, or cash), required before a reservation is
 * confirmed, and stated with the balance in the confirmation message. A recorded payment stops
 * the hold from expiring. The online checkout and the balance are in bin/test-payments.php.
 *
 * Writes test vehicles, a customer and agreements; run it against a test database.
 *   php bin/test-downpayment.php
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\CustomerRepository;
use TripleR\Services\CustomerPiiCipher;
use TripleR\Services\CustomerService;
use TripleR\Services\RentalRuntimeFactory;
use TripleR\Services\SmsMessageCipher;

require dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connection();
$rentals = RentalRuntimeFactory::service($db);
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
$customerId = $customers->create(['customer_type' => 'walk_in', 'full_name' => 'Downpayment Test ' . $run, 'company_name' => null, 'referral_source' => null]);
(new CustomerService($db, $customers, new CustomerPiiCipher()))->addContact($customerId, 'phone', '09' . str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT), true);

$vehicleNumber = 0;
$vehicle = static function (string $rate) use ($db, $run, &$vehicleNumber): int {
    $insert = $db->prepare("INSERT INTO vehicles (plate_number, make, model, model_year, color, body_type, transmission, fuel_type, seating_capacity, daily_rate, current_status) VALUES (:plate, 'Toyota', 'Vios', 2024, 'White', 'sedan', 'automatic', 'gasoline', 5, :rate, 'available')");
    $insert->execute(['plate' => 'DP' . $run . (++$vehicleNumber), 'rate' => $rate]);
    return (int) $db->lastInsertId();
};
$manila = new DateTimeZone('Asia/Manila');
$book = static function (int $vehicleId, int $days) use ($rentals, $customerId, $actor, $manila): int {
    $start = (new DateTimeImmutable('+10 days', $manila))->format('Y-m-d');
    $end = (new DateTimeImmutable('+' . (10 + $days) . ' days', $manila))->format('Y-m-d');
    return $rentals->create(['customer_id' => $customerId, 'vehicle_id' => $vehicleId, 'rental_type' => 'self_drive', 'start_date' => $start, 'end_date' => $end, 'scheduled_pickup_at' => $start . 'T09:00', 'scheduled_return_at' => $end . 'T09:00', 'deposit_amount' => '0'], $actor);
};
$row = static function (int $id) use ($db): array {
    $statement = $db->prepare('SELECT * FROM rental_agreements WHERE agreement_id = :id');
    $statement->execute(['id' => $id]);
    return $statement->fetch();
};
$reference = static fn (string $suffix): string => 'GC' . $run . $suffix;
$payment = static function (int $id) use ($db): array {
    $statement = $db->prepare("SELECT * FROM payments WHERE agreement_id = :id AND purpose = 'downpayment' AND payment_status = 'paid'");
    $statement->execute(['id' => $id]);
    return $statement->fetch() ?: [];
};

echo "Downpayment acceptance (run {$run})\n\n== The amount is worked out and stored at booking\n";
$carA = $vehicle('2000.00');
$a = $book($carA, 5);
$agreement = $row($a);
$check($agreement['base_amount'] === '10000.00' && $agreement['downpayment_amount'] === '3000.00' && $agreement['downpayment_status'] === 'due', 'a ₱10,000 rental stores a downpayment of ₱3,000, marked due', $agreement['base_amount'] . ' / ' . $agreement['downpayment_amount']);
$b = $book($vehicle('3333.33'), 3);
$check($row($b)['downpayment_amount'] === '3000.00', '30% of ₱9,999.99 is rounded to the centavo: ₱3,000.00', $row($b)['downpayment_amount']);
$db->prepare("UPDATE vehicles SET daily_rate = '5000.00' WHERE vehicle_id = :id")->execute(['id' => $carA]);
$check($row($a)['downpayment_amount'] === '3000.00' && $row($a)['daily_rate'] === '2000.00', 'raising the vehicle\'s rate afterwards changes neither the booked rate nor the downpayment');
$hold = (int) $db->query("SELECT TIMESTAMPDIFF(MINUTE, created_at, hold_expires_at) FROM rental_agreements WHERE agreement_id = {$a}")->fetchColumn();
$check($hold >= 1439 && $hold <= 1440, 'the vehicle is held for 24 hours', $hold . ' minutes');
$free = $book($vehicle('0.00'), 2);
$check($row($free)['downpayment_status'] === 'not_required' && $row($free)['downpayment_amount'] === '0.00', 'a rental that costs nothing needs no downpayment');
$context = $rentals->bookingContext($a);
$check($context['downpayment_amount'] === '3000.00' && $context['downpayment_status'] === 'due' && $context['balance_at_pickup'] === '7000.00' && $context['total_amount'] === '10000.00', 'the customer\'s booking page is given the downpayment, its state and the balance at pickup');

echo "\n== A reservation cannot be confirmed before the downpayment is recorded\n";
$refused(fn () => $rentals->transition($a, 'confirm', $actor), 'Record the 30% downpayment', 'confirming with the downpayment still due is refused');
$check($row($a)['status'] === 'reserved', 'the agreement stays reserved');
$rentals->transition($free, 'confirm', $actor);
$check($row($free)['status'] === 'confirmed', 'an agreement with no downpayment required confirms as before');

echo "\n== Finance records the payment\n";
$refused(fn () => $rentals->recordDownpayment($a, 'abc', $actor), 'reference number', 'a reference that is too short is refused');
$refused(fn () => $rentals->recordDownpayment($a, 'ref with <symbols>!', $actor), 'reference number', 'a reference with symbols is refused');
$refused(fn () => $rentals->recordDownpayment($free, $reference('X'), $actor), 'No downpayment is required', 'a payment cannot be recorded where none is required');
$rentals->recordDownpayment($a, ' ' . strtolower($reference('001')) . ' ', $actor);
$paid = $payment($a);
$check($row($a)['downpayment_status'] === 'received' && ($paid['external_reference'] ?? '') === $reference('001') && ($paid['method'] ?? '') === 'gcash' && ($paid['channel'] ?? '') === 'staff' && $paid['amount'] === '3000.00' && (int) $paid['recorded_by'] === $actor && $paid['settled_at'] !== null && preg_match('/^TR[A-Z2-9]{10}$/', (string) $paid['receipt_number']) === 1, 'the payment is recorded with its reference (tidied to upper case), a receipt number, who recorded it and when');
$refused(fn () => $rentals->recordDownpayment($a, $reference('002'), $actor), 'already been recorded', 'it cannot be recorded twice');
$refused(fn () => $rentals->recordDownpayment($b, $reference('001'), $actor), 'already recorded on another agreement', 'one GCash reference cannot pay for two bookings');
$check($row($b)['downpayment_status'] === 'due', 'the second booking stays unpaid');

echo "\n== Confirming, and what the customer is told\n";
$rentals->transition($a, 'confirm', $actor);
$check($row($a)['status'] === 'confirmed', 'with the downpayment recorded, the reservation is confirmed');
$message = $db->prepare("SELECT recipient_phone, template_key, rendered_message, channel FROM notifications WHERE idempotency_key = :key");
$message->execute(['key' => 'rental-' . $a . '-confirmed']);
$queued = $message->fetch();
$text = $queued ? (new SmsMessageCipher())->decrypt((string) $queued['rendered_message'], SmsMessageCipher::context((string) $queued['recipient_phone'], (string) $queued['template_key'])) : '';
$check($text === 'Your Triple R Gensan rental is confirmed. Agreement #' . $a . '. Downpayment received: ₱3,000. Balance of ₱7,000 is due at pickup.', 'the confirmation message states the downpayment received and the balance due at pickup', $text);

echo "\n== The hold, and what a payment does to it\n";
$db->prepare('UPDATE rental_agreements SET hold_expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE) WHERE agreement_id = :id')->execute(['id' => $b]);
$refused(fn () => $rentals->recordDownpayment($b, $reference('003'), $actor), 'hold has run out', 'a payment cannot be recorded once the hold has run out');
$check($rentals->expireReservation($b, $actor) === true && $row($b)['status'] === 'cancelled', 'an unpaid reservation is cancelled when its hold runs out, freeing the vehicle');
$c = $book($vehicle('1500.00'), 2);
$rentals->recordDownpayment($c, $reference('004'), $actor);
$db->prepare('UPDATE rental_agreements SET hold_expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE) WHERE agreement_id = :id')->execute(['id' => $c]);
$check($rentals->expireReservation($c, $actor) === false && $row($c)['status'] === 'reserved', 'a paid reservation is not cancelled when the hold time passes');
$rentals->transition($c, 'confirm', $actor);
$check($row($c)['status'] === 'confirmed', 'and it can still be confirmed afterwards');

echo "\n== Other ways to pay at the counter\n";
$cashBooking = $book($vehicle('1200.00'), 2);
$refused(fn () => $rentals->recordDownpayment($cashBooking, $reference('005'), $actor, 'cash'), 'no reference number', 'cash is recorded without a reference number');
$refused(fn () => $rentals->recordDownpayment($cashBooking, $reference('005'), $actor, 'cheque'), 'how the payment was made', 'a method the office does not take is refused');
$refused(fn () => $rentals->recordDownpayment($cashBooking, '', $actor, 'maya'), 'reference number', 'every method except cash needs its reference');
$rentals->recordDownpayment($cashBooking, '', $actor, 'cash');
$cash = $payment($cashBooking);
$check($row($cashBooking)['downpayment_status'] === 'received' && ($cash['method'] ?? '') === 'cash' && $cash['external_reference'] === null && $cash['amount'] === '720.00', 'a cash downpayment is recorded with a receipt number and no outside reference');
$cardBooking = $book($vehicle('1300.00'), 2);
$rentals->recordDownpayment($cardBooking, 'APPR-' . $run, $actor, 'card');
$check(($payment($cardBooking)['method'] ?? '') === 'card' && $payment($cardBooking)['external_reference'] === 'APPR-' . $run, 'a card payment at the counter is recorded with the terminal\'s approval code');

echo "\n== The database's own rules\n";
$refused(fn () => $db->exec("UPDATE rental_agreements SET downpayment_amount = 1 WHERE agreement_id = {$a}"), 'fixed when the booking is made', 'the stored amount cannot be edited');
$refused(fn () => $db->exec("UPDATE payments SET external_reference = 'CHANGED-REF' WHERE agreement_id = {$a}"), 'cannot be changed', 'a recorded reference cannot be edited');
$refused(fn () => $db->exec("UPDATE payments SET amount = 1 WHERE agreement_id = {$a}"), 'cannot be edited', 'a recorded amount cannot be edited');
$refused(fn () => $db->exec("UPDATE rental_agreements SET downpayment_status = 'due' WHERE agreement_id = {$a}"), 'cannot be changed', 'a recorded payment cannot be undone');
$d = $book($vehicle('1000.00'), 1);
$refused(fn () => $db->exec("UPDATE rental_agreements SET downpayment_status = 'received' WHERE agreement_id = {$d}"), 'only when its payment is recorded', 'a downpayment cannot be marked received without a payment behind it');

echo "\n" . ($failed === 0 ? "ALL {$passed} DOWNPAYMENT CHECKS PASSED" : "{$failed} FAILED, {$passed} passed") . "\n";
exit($failed === 0 ? 0 : 1);
