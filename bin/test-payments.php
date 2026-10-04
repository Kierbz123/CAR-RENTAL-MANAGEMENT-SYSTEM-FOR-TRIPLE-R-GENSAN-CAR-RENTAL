<?php
/**
 * Payments: the simulated online checkout for every method, each "what if" (approved, declined,
 * not enough balance, cancelled, timed out, result sent twice, forged result, changed amount,
 * paying after the hold ended), the balance, and the database's own rules.
 *
 * Counter payments of the downpayment are covered by bin/test-downpayment.php.
 *
 * Writes test vehicles, a customer, agreements and payments; run it against a test database.
 *   php bin/test-payments.php
 */
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\CustomerRepository;
use TripleR\Repositories\PaymentProofRepository;
use TripleR\Repositories\PaymentRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\RulesAcceptanceRepository;
use TripleR\Repositories\SecurityLogRepository;
use TripleR\Services\BookingOverlapService;
use TripleR\Services\CustomerPiiCipher;
use TripleR\Services\CustomerService;
use TripleR\Services\Payments\SimulatedGateway;
use TripleR\Services\PaymentService;
use TripleR\Services\RateLimiter;
use TripleR\Services\RentalRuntimeFactory;
use TripleR\Services\SmsMessageCipher;

require dirname(__DIR__) . '/app/bootstrap.php';

$db = Database::connection();
$rentals = RentalRuntimeFactory::service($db);
$gateway = new SimulatedGateway('test-secret-' . bin2hex(random_bytes(12)));
$paymentRows = new PaymentRepository($db);
$service = static fn (?SimulatedGateway $with): PaymentService => new PaymentService($db, $paymentRows, new RentalRepository($db, new BookingOverlapService($db)), $rentals, new PaymentProofRepository($db), new RulesAcceptanceRepository($db), new RateLimiter($db), new SecurityLogRepository($db), $with);
$payments = $service($gateway);
// Checks over the payments table look only at the rows this run writes: other suites leave
// payments behind whose random references can look like a card number.
$firstPaymentId = (int) $db->query('SELECT COALESCE(MAX(payment_id), 0) + 1 FROM payments')->fetchColumn();

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
// A fresh customer for each part, so no one phone number runs into the daily message limit.
$newCustomer = static function (string $part) use ($db, $customers, $run): int {
    $id = $customers->create(['customer_type' => 'walk_in', 'full_name' => 'Payments Test ' . $run . ' ' . $part, 'company_name' => null, 'referral_source' => null]);
    (new CustomerService($db, $customers, new CustomerPiiCipher()))->addContact($id, 'phone', '09' . str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT), true);
    return $id;
};
$customerId = $newCustomer('online');

$vehicleNumber = 0;
$book = static function (string $rate = '2000.00', int $days = 5) use ($db, $rentals, $run, &$customerId, $actor, &$vehicleNumber): int {
    $insert = $db->prepare("INSERT INTO vehicles (plate_number, make, model, model_year, color, body_type, transmission, fuel_type, seating_capacity, daily_rate, current_status) VALUES (:plate, 'Toyota', 'Vios', 2024, 'White', 'sedan', 'automatic', 'gasoline', 5, :rate, 'available')");
    $insert->execute(['plate' => 'PY' . $run . (++$vehicleNumber), 'rate' => $rate]);
    $manila = new DateTimeZone('Asia/Manila');
    $start = (new DateTimeImmutable('+10 days', $manila))->format('Y-m-d');
    $end = (new DateTimeImmutable('+' . (10 + $days) . ' days', $manila))->format('Y-m-d');
    return $rentals->create(['customer_id' => $customerId, 'vehicle_id' => (int) $db->lastInsertId(), 'rental_type' => 'self_drive', 'start_date' => $start, 'end_date' => $end, 'scheduled_pickup_at' => $start . 'T09:00', 'scheduled_return_at' => $end . 'T09:00', 'deposit_amount' => '0'], $actor);
};
$agreement = static function (int $id) use ($db): array {
    $statement = $db->prepare('SELECT * FROM rental_agreements WHERE agreement_id = :id');
    $statement->execute(['id' => $id]);
    return $statement->fetch();
};
$receiptOf = static fn (string $url): string => substr($url, strpos($url, 'receipt=') + 8);
$count = static fn (string $sql): int => (int) $db->query($sql)->fetchColumn();
// Starts a checkout and ends it the way the checkout page would: the gateway signs the outcome and reports it.
$start = static fn (int $id, string $method): string => $receiptOf($payments->startOnline($id, $method, true, '127.0.0.1', 'test-payments'));
$finish = static function (string $receipt, string $outcome, ?string $detail = null) use ($payments, $paymentRows, $gateway): array {
    $message = $gateway->result($paymentRows->findByReceipt($receipt), $outcome, $detail);
    return $payments->handleGatewayResult($message['payload'], $message['signature'], '127.0.0.1', 'test-payments') ?? [];
};

echo "Payments acceptance (run {$run})\n\n== What if the customer pays by e-wallet\n";
$a = $book();
$refused(fn () => $payments->startOnline($a, 'gcash', false, '127.0.0.1', 'test-payments'), 'accept the downpayment policy', 'a counter booking cannot be paid online before the policy is accepted');
$refused(fn () => $payments->startOnline($a, 'cash', true, '127.0.0.1', 'test-payments'), 'Choose how you want to pay', 'cash cannot be chosen on the online checkout');
$url = $payments->startOnline($a, 'gcash', true, '127.0.0.1', 'test-payments');
$receipt = $receiptOf($url);
$pending = $paymentRows->findByReceipt($receipt);
$minutes = $count("SELECT TIMESTAMPDIFF(MINUTE, created_at, expires_at) FROM payments WHERE receipt_number = '{$receipt}'");
$check(str_starts_with($url, '/pay/demo?receipt=TR') && $pending['payment_status'] === 'pending' && $pending['channel'] === 'online_demo' && $pending['method'] === 'gcash' && $pending['amount'] === '3000.00' && $pending['recorded_by'] === null && $minutes >= 14 && $minutes <= 15, 'starting a GCash payment opens a 15-minute checkout for the booking\'s own downpayment, ₱3,000', $pending['amount'] . ', ' . $minutes . ' minutes');
$accepted = $db->query("SELECT v.version_number FROM rules_acceptances a JOIN rules_versions v ON v.rules_version_id = a.rules_version_id WHERE a.agreement_id = {$a} AND a.action = 'accepted'")->fetchColumn();
$check((int) $accepted === 2, 'the customer\'s acceptance of the current policy (version 2) is recorded with the payment', (string) $accepted);
$check($payments->startOnline($a, 'maya', true, '127.0.0.1', 'test-payments') === $url, 'starting again returns to the checkout already open instead of making a second payment');
$refused(fn () => $rentals->recordDownpayment($a, 'GC' . $run . '900', $actor), 'paying online right now', 'finance cannot record a counter payment while the checkout is open');
$refused(fn () => $rentals->transition($a, 'confirm', $actor), 'Record the 30% downpayment', 'the reservation still cannot be confirmed');
$paid = $finish($receipt, 'approved');
$check(($paid['payment_status'] ?? '') === 'paid' && str_starts_with((string) $paid['external_reference'], 'DEMO-') && $paid['settled_at'] !== null && $agreement($a)['downpayment_status'] === 'received', 'when the wallet approves, the payment is paid and the downpayment is received, with the gateway\'s reference');
$message = $db->prepare('SELECT recipient_phone, recipient_ciphertext, template_key, rendered_message FROM notifications WHERE idempotency_key = :key');
$message->execute(['key' => 'payment-received-' . $paid['payment_id']]);
$queued = $message->fetch();
$text = $queued ? (new SmsMessageCipher())->decrypt((string) $queued['rendered_message'], SmsMessageCipher::context((new \TripleR\Services\PhoneVault())->numberOf($queued['recipient_ciphertext'] ?? null, (string) $queued['recipient_phone']), (string) $queued['template_key'])) : '';
$check(str_contains($text, 'received your downpayment of ₱3,000') && str_contains($text, $receipt) && str_contains($text, 'demonstration payment'), 'the customer is told it was received, with the receipt number, and that it was a demonstration', $text);
$rentals->transition($a, 'confirm', $actor);
$check($agreement($a)['status'] === 'confirmed', 'front desk can now confirm the reservation');

echo "\n== What if the result arrives twice, is forged, or names another amount\n";
$again = $gateway->result($paid, 'approved');
$repeat = $payments->handleGatewayResult($again['payload'], $again['signature']);
$check(($repeat['external_reference'] ?? '') === $paid['external_reference'] && $count("SELECT COUNT(*) FROM payments WHERE agreement_id = {$a} AND payment_status = 'paid'") === 1, 'a second result for a payment already settled changes nothing');
$b = $book();
$receiptB = $start($b, 'maya');
$rejectedBefore = $count("SELECT COUNT(*) FROM security_logs WHERE event_type = 'payment.result_rejected'");
$genuine = $gateway->result($paymentRows->findByReceipt($receiptB), 'approved');
$check($payments->handleGatewayResult($genuine['payload'], str_repeat('0', 64), '203.0.113.7', 'forger') === null && $paymentRows->findByReceipt($receiptB)['payment_status'] === 'pending' && $agreement($b)['downpayment_status'] === 'due', 'a "paid" result with a wrong signature is refused and nothing is paid');
$check($count("SELECT COUNT(*) FROM security_logs WHERE event_type = 'payment.result_rejected'") === $rejectedBefore + 1, 'and it is written to the security log');
$forgedSecret = (new SimulatedGateway('some-other-secret-entirely'))->result($paymentRows->findByReceipt($receiptB), 'approved');
$check($payments->handleGatewayResult($forgedSecret['payload'], $forgedSecret['signature']) === null && $agreement($b)['downpayment_status'] === 'due', 'a result signed with another secret is refused');
$mismatchBefore = $count("SELECT COUNT(*) FROM security_logs WHERE event_type = 'payment.result_mismatch'");
$cheaper = $gateway->result(['receipt_number' => $receiptB, 'amount' => '1.00'], 'approved');
$check($payments->handleGatewayResult($cheaper['payload'], $cheaper['signature']) === null && $agreement($b)['downpayment_status'] === 'due' && $count("SELECT COUNT(*) FROM security_logs WHERE event_type = 'payment.result_mismatch'") === $mismatchBefore + 1, 'a correctly signed result for a different amount is refused and logged');

echo "\n== What if the payment does not go through\n";
$short = $finish($receiptB, 'insufficient');
$check(($short['payment_status'] ?? '') === 'failed' && $short['failure_reason'] === 'Not enough balance to cover the payment' && $short['external_reference'] === null && $agreement($b)['downpayment_status'] === 'due', 'not enough balance: the payment fails with its reason and the downpayment stays due');
$backedOut = $finish($start($b, 'grabpay'), 'cancelled');
$check(($backedOut['payment_status'] ?? '') === 'cancelled' && $backedOut['failure_reason'] === null && $agreement($b)['status'] === 'reserved', 'the customer cancels: the payment is cancelled and the booking is unchanged');
$abandoned = $finish($start($b, 'online_banking'), 'timed_out');
$check(($abandoned['payment_status'] ?? '') === 'expired', 'the customer walks away: the checkout times out');
$receiptLate = $start($b, 'gcash');
$db->exec("UPDATE payments SET expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE) WHERE receipt_number = '{$receiptLate}'");
$late = $finish($receiptLate, 'approved');
$check(($late['payment_status'] ?? '') === 'expired' && $agreement($b)['downpayment_status'] === 'due', 'an approval that arrives after the checkout closed pays nothing');
$check($payments->pendingCheckout($receiptLate, $b) === null, 'a closed checkout cannot be reopened');
$bank = $finish($start($b, 'online_banking'), 'approved', 'BPI');
$check(($bank['payment_status'] ?? '') === 'paid' && $bank['method_detail'] === 'BPI' && $agreement($b)['downpayment_status'] === 'received' && $count("SELECT COUNT(*) FROM payments WHERE agreement_id = {$b}") === 5, 'after four attempts that did not pay, online banking pays, and all five attempts are kept');

echo "\n== What if the customer pays by card\n";
$customerId = $newCustomer('card');
$c = $book('1500.00', 4);
$check($gateway->testCard('4111 1111 1111 1111') === null && $gateway->testCard('') === null, 'a card number that is not a listed test card is refused');
$declinedCard = $gateway->testCard('4000 0000 0000 0002');
$declined = $finish($start($c, 'card'), $declinedCard['outcome'], $declinedCard['detail']);
$check(($declined['payment_status'] ?? '') === 'failed' && $declined['failure_reason'] === 'Declined by the issuing bank' && $declined['method_detail'] === 'Visa ending 0002', 'a declined card: the payment fails and only the brand and last four digits are kept');
$noFunds = $gateway->testCard('4000000000009995');
$check(($finish($start($c, 'card'), $noFunds['outcome'], $noFunds['detail'])['failure_reason'] ?? '') === 'Not enough balance to cover the payment', 'a card without enough funds is refused with that reason');
$goodCard = $gateway->testCard('4242-4242-4242-4242');
$check(($finish($start($c, 'card'), 'verification_failed', $goodCard['detail'])['failure_reason'] ?? '') === 'The bank’s verification step was not passed', 'a good card whose bank verification fails is not charged');
$charged = $finish($start($c, 'card'), 'approved', $goodCard['detail']);
$check(($charged['payment_status'] ?? '') === 'paid' && $charged['method_detail'] === 'Visa ending 4242' && $charged['amount'] === '1800.00' && $agreement($c)['downpayment_status'] === 'received', 'a good card that passes verification pays the downpayment of ₱1,800');
$check($count("SELECT COUNT(*) FROM payments WHERE payment_id >= {$firstPaymentId} AND CONCAT_WS('|', method_detail, external_reference, failure_reason) REGEXP '[0-9]{12,}'") === 0, 'no full card number is stored anywhere in the payments table');

echo "\n== When paying online is not possible\n";
$d = $book();
$db->prepare('UPDATE rental_agreements SET hold_expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE) WHERE agreement_id = :id')->execute(['id' => $d]);
$refused(fn () => $payments->startOnline($d, 'gcash', true, '127.0.0.1', 'test-payments'), 'time to pay for this booking has run out', 'a payment cannot be started after the hold has ended');
$refused(fn () => $payments->startOnline($a, 'gcash', true, '127.0.0.1', 'test-payments'), 'already been received', 'a downpayment that is already in cannot be paid again');
$e = $book();
$db->prepare("INSERT INTO payment_proofs (agreement_id, reference_number, storage_path, original_filename, mime, size_bytes) VALUES (:id, :reference, :path, 'x.png', 'image/png', 10)")->execute(['id' => $e, 'reference' => 'PF' . $run, 'path' => 'payments/test-' . $run . '.png']);
$refused(fn () => $payments->startOnline($e, 'gcash', true, '127.0.0.1', 'test-payments'), 'proof of payment is already waiting', 'a customer whose proof is being checked is not also sent to the checkout');
$off = $service(null);
$check($off->onlineAvailable() === false && $payments->onlineAvailable() === true, 'with no gateway configured, paying online is switched off');
$refused(fn () => $off->startOnline($book(), 'gcash', true, '127.0.0.1', 'test-payments'), 'not available right now', 'and a payment cannot be started');

echo "\n== The balance\n";
$customerId = $newCustomer('balance');
$refused(fn () => $payments->recordBalance($e, 'cash', '', '', $actor), 'Record the downpayment first', 'the balance cannot be recorded before the downpayment');
$f = $book();
$rentals->recordDownpayment($f, '', $actor, 'cash');
$refused(fn () => $payments->recordBalance($f, 'cash', '', '', $actor), 'after the reservation is confirmed', 'the balance is not taken while the reservation is still to be confirmed');
$rentals->transition($f, 'confirm', $actor);
$check($rentals->outstandingCents($f) === 700000, 'after a ₱3,000 downpayment on a ₱10,000 rental, ₱7,000 is owed');
$refused(fn () => $payments->recordBalance($f, 'cash', '', '7000.01', $actor), 'more than the ₱7,000 still owed', 'more than what is owed cannot be recorded');
$refused(fn () => $payments->recordBalance($f, 'maya', '', '1000', $actor), 'reference number', 'a balance paid by Maya needs its reference');
$refused(fn () => $payments->recordBalance($f, 'cash', '', 'lots', $actor), 'Enter the amount received', 'the amount must be a number');
$partReceipt = $payments->recordBalance($f, 'cash', '', '2000', $actor);
$part = $paymentRows->findByReceipt($partReceipt);
$check($part['purpose'] === 'balance' && $part['method'] === 'cash' && $part['amount'] === '2000.00' && (int) $part['recorded_by'] === $actor && $rentals->outstandingCents($f) === 500000 && $rentals->bookingContext($f)['balance_at_pickup'] === '5000.00', 'part of the balance is recorded in cash, leaving ₱5,000');
$rentals->addCharge($f, 'fee', '500.00', 'Late return', $actor);
$check($rentals->outstandingCents($f) === 550000, 'a later charge raises what is owed to ₱5,500');
$rentals->transition($f, 'pickup', $actor, null, 100);
$rentals->transition($f, 'return', $actor, null, 400);
$refused(fn () => $rentals->transition($f, 'complete', $actor), 'Record the balance of ₱5,500', 'the agreement cannot be completed while money is owed');
$payments->recordBalance($f, 'maya', 'MY' . $run . '01', '', $actor);
$check($rentals->outstandingCents($f) === 0 && $count("SELECT COUNT(*) FROM payments WHERE agreement_id = {$f} AND payment_status = 'paid'") === 3, 'a blank amount records everything still owed; nothing is left');
$refused(fn () => $payments->recordBalance($f, 'cash', '', '', $actor), 'Nothing is owed', 'a payment cannot be recorded when nothing is owed');
$rentals->transition($f, 'complete', $actor);
$check($agreement($f)['status'] === 'completed', 'with the balance in, the agreement is completed');
$customerId = $newCustomer('rules');
$g = $book();
$rentals->recordDownpayment($g, 'GC' . $run . '77', $actor);
$rentals->transition($g, 'confirm', $actor);
$refused(fn () => $payments->recordBalance($g, 'maya', 'MY' . $run . '01', '1000', $actor), 'already recorded on another payment', 'one reference cannot be recorded for two payments');
$free = $book('0.00', 2);
$rentals->transition($free, 'confirm', $actor);
$rentals->transition($free, 'pickup', $actor, null, 10);
$rentals->transition($free, 'return', $actor, null, 20);
$rentals->transition($free, 'complete', $actor);
$check($agreement($free)['status'] === 'completed', 'an agreement that needed no downpayment is completed as before');

echo "\n== The database's own rules\n";
$h = $book();
$insert = static fn (string $columns, string $values): callable => static fn () => $db->exec("INSERT INTO payments (agreement_id, receipt_number, {$columns}) VALUES ({$h}, 'TR" . strtoupper(bin2hex(random_bytes(5))) . "', {$values})");
$refused($insert('purpose, channel, method, amount, payment_status, recorded_by, settled_at', "'downpayment', 'staff', 'cash', 1.00, 'paid', {$actor}, UTC_TIMESTAMP(6)"), 'amount fixed when the booking was made', 'a downpayment for any amount but the booking\'s own is refused');
$refused($insert('purpose, channel, method, amount, payment_status, expires_at', "'downpayment', 'online_demo', 'cash', 3000.00, 'pending', DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 15 MINUTE)"), 'chk_payments_channel', 'cash cannot be an online payment');
$refused($insert('purpose, channel, method, amount, payment_status, settled_at', "'downpayment', 'staff', 'cash', 3000.00, 'paid', UTC_TIMESTAMP(6)"), 'chk_payments_channel', 'a counter payment must name who recorded it');
$refused($insert('purpose, channel, method, amount, payment_status, recorded_by, settled_at', "'downpayment', 'staff', 'gcash', 3000.00, 'paid', {$actor}, UTC_TIMESTAMP(6)"), 'chk_payments_state', 'a payment by anything but cash must carry its reference');
$refused($insert('purpose, channel, method, amount, payment_status, settled_at', "'downpayment', 'online_demo', 'gcash', 3000.00, 'failed', UTC_TIMESTAMP(6)"), 'chk_payments_state', 'a failed payment must say why');
$refused(fn () => $db->exec("INSERT INTO payments (agreement_id, receipt_number, purpose, channel, method, amount, payment_status, recorded_by, settled_at) VALUES ({$a}, 'TR" . strtoupper(bin2hex(random_bytes(5))) . "', 'downpayment', 'staff', 'cash', 3000.00, 'paid', {$actor}, UTC_TIMESTAMP(6))"), 'uq_payments_one_paid_downpayment', 'a booking can have only one paid downpayment');
$receiptH = $start($h, 'gcash');
$refused($insert('purpose, channel, method, amount, payment_status, expires_at', "'downpayment', 'online_demo', 'maya', 3000.00, 'pending', DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 15 MINUTE)"), 'uq_payments_one_pending', 'a booking can have only one payment in progress');
$refused(fn () => $db->exec("UPDATE payments SET amount = 1.00 WHERE receipt_number = '{$receiptH}'"), 'cannot be edited', 'the amount of a payment in progress cannot be changed');
$refused(fn () => $db->exec("UPDATE payments SET method = 'card' WHERE receipt_number = '{$receiptH}'"), 'cannot be edited', 'nor its method');
$finish($receiptH, 'cancelled');
$refused(fn () => $db->exec("UPDATE payments SET payment_status = 'paid', external_reference = 'X-{$run}', settled_at = UTC_TIMESTAMP(6) WHERE receipt_number = '{$receiptH}'"), 'settled cannot be changed', 'a settled payment cannot be turned into a paid one');
$refused(fn () => $db->exec("UPDATE rental_agreements SET downpayment_status = 'received' WHERE agreement_id = {$h}"), 'only when its payment is recorded', 'a downpayment cannot be marked received without a paid payment');
$refused(fn () => $db->exec("DELETE FROM payments WHERE receipt_number = '{$receiptH}'"), '', 'a payment cannot be deleted');

echo "\n" . ($failed === 0 ? "ALL {$passed} PAYMENT CHECKS PASSED" : "{$failed} FAILED, {$passed} passed") . "\n";
exit($failed === 0 ? 0 : 1);
