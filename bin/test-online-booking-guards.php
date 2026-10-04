<?php
/**
 * Guards on the public booking form:
 *   - with phone verification on, a booking is only made after the visitor types back the code
 *     texted to the number, so nobody can file a booking under another customer's number;
 *   - a wrong code is refused, and five wrong tries end the code;
 *   - unpaid online reservations waiting at once are capped per visitor address and in total.
 *
 * Writes test vehicles, customers and agreements; run it against a test database.
 *   php bin/test-online-booking-guards.php
 */
declare(strict_types=1);

use TripleR\Config;
use TripleR\Database;
use TripleR\Repositories\CustomerRepository;
use TripleR\Repositories\InboundSmsEventRepository;
use TripleR\Repositories\NotificationRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\RulesAcceptanceRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Security\BookingPhoneVerification;
use TripleR\Services\BookingOverlapService;
use TripleR\Services\CustomerPiiCipher;
use TripleR\Services\CustomerService;
use TripleR\Services\NotificationService;
use TripleR\Services\OnlineBookingService;
use TripleR\Services\PhoneVerificationRequired;
use TripleR\Services\RateLimiter;
use TripleR\Services\RentalRuntimeFactory;
use TripleR\Services\SmsMessageCipher;
use TripleR\Services\TelegramLinkService;
use TripleR\Support\PhoneNumber;

require dirname(__DIR__) . '/app/bootstrap.php';

// The code is kept in the PHP session, which cannot start once output has been sent.
ob_start();
session_start();
$db = Database::connection();
$customers = new CustomerService($db, new CustomerRepository($db), new CustomerPiiCipher());
$rules = new RulesAcceptanceRepository($db);
$notifications = new NotificationService(new NotificationRepository($db), new InboundSmsEventRepository($db), new SmsMessageCipher(), $rules, TelegramLinkService::create($db));
$bookings = new OnlineBookingService($db, RentalRuntimeFactory::service($db), new RentalRepository($db, new BookingOverlapService($db)), new VehicleRepository($db), new BookingOverlapService($db), $customers, $rules, new RateLimiter($db), $notifications);
$setting = static function (string $name, string $value): void {
    putenv($name . '=' . $value);
    Config::load(APP_ROOT);
};
$manila = new DateTimeZone('Asia/Manila');
$start = (new DateTimeImmutable('today +45 days', $manila))->format('Y-m-d');
$end = (new DateTimeImmutable('today +47 days', $manila))->format('Y-m-d');
$policy = (int) $bookings->policy()['rules_version_id'];
$tag = strtoupper(bin2hex(random_bytes(3)));
$actor = (int) $db->query("SELECT id FROM users WHERE role = 'system_admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$db->exec('SET @triple_r_actor_user_id = ' . $actor);
$passed = 0;
$failed = 0;
$check = static function (bool $condition, string $label, string $detail = '') use (&$passed, &$failed): void {
    $condition ? $passed++ : $failed++;
    echo ($condition ? 'PASS' : 'FAIL') . ": {$label}" . ($condition || $detail === '' ? '' : " ({$detail})") . "\n";
};
$vehicle = static function () use ($db, $tag): int {
    static $n = 0;
    $db->prepare("INSERT INTO vehicles (plate_number, make, model, model_year, color, body_type, transmission, fuel_type, seating_capacity, daily_rate) VALUES (:plate, 'Test', 'Guard', 2024, 'White', 'sedan', 'automatic', 'gasoline', 5, 1800.00)")
        ->execute(['plate' => 'OG-' . $tag . '-' . ++$n]);
    return (int) $db->lastInsertId();
};
$phone = static fn (): string => '0918' . random_int(1000000, 9999999);
$form = static fn (int $vehicleId, string $number): array => ['start_date' => $start, 'end_date' => $end, 'vehicle_id' => (string) $vehicleId, 'pickup_time' => '10:00', 'full_name' => 'Online Guard ' . $tag, 'phone' => $number, 'email' => '', 'accept_policy' => '1', 'policy_version_id' => (string) $policy];
$book = static function (array $input, string $ip) use ($bookings): string {
    try {
        return 'booked:' . $bookings->book($input, $ip, 'test-online-booking-guards');
    } catch (PhoneVerificationRequired) {
        return 'code needed';
    } catch (RuntimeException $error) {
        return $error->getMessage();
    }
};
$textedCode = static function (string $number) use ($db): string {
    $normalized = PhoneNumber::normalize($number);
    $row = $db->prepare("SELECT recipient_phone, template_key, rendered_message FROM notifications WHERE recipient_phone = :phone AND template_key = 'booking.verify_code' ORDER BY id DESC LIMIT 1");
    $row->execute(['phone' => $normalized]);
    $sms = $row->fetch();
    $text = (new SmsMessageCipher())->decrypt((string) $sms['rendered_message'], SmsMessageCipher::context((string) $sms['recipient_phone'], 'booking.verify_code'));
    preg_match('/\b(\d{6})\b/', $text, $match);
    return $match[1] ?? '';
};
$address = static fn (): string => '203.0.113.' . random_int(1, 254);

echo "== Phone verification on\n";
$setting('ONLINE_BOOKING_VERIFY_PHONE', 'on');
$check($bookings->phoneVerificationRequired(), 'ONLINE_BOOKING_VERIFY_PHONE=on turns verification on');
$ownerPhone = $phone();
$owner = $customers->create(['customer_type' => 'walk_in', 'full_name' => 'Number Owner ' . $tag, 'phone' => $ownerPhone], $actor);
$ownerBookings = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM rental_agreements WHERE customer_id = ' . $owner)->fetchColumn();
$ip = $address();
$check($book($form($vehicle(), $ownerPhone), $ip) === 'code needed', 'a booking with an unconfirmed number asks for a code and is not made');
$check($ownerBookings() === 0, 'nothing is filed under the customer who owns that number');
$bookings->sendPhoneCode($ownerPhone, $ip);
$code = $textedCode($ownerPhone);
$check(strlen($code) === 6, 'a 6-digit code is texted to the number');
$wrong = $code === '000000' ? '111111' : '000000';
$check(!BookingPhoneVerification::confirm($wrong), 'a wrong code is refused');
$check(BookingPhoneVerification::confirm($code), 'the texted code confirms the number');
$result = $book($form($vehicle(), $ownerPhone), $ip);
$check(str_starts_with($result, 'booked:') && $ownerBookings() === 1, 'with the number confirmed, the booking is made for its owner', $result);

$other = $phone();
$bookings->sendPhoneCode($other, $address());
for ($i = 0; $i < 5; $i++) {
    BookingPhoneVerification::confirm('999999' === $textedCode($other) ? '888888' : '999999');
}
$check(!BookingPhoneVerification::confirm($textedCode($other)), 'after five wrong tries even the right code is refused');

echo "== Unpaid online reservations are capped\n";
$setting('ONLINE_BOOKING_VERIFY_PHONE', 'off');
$setting('ONLINE_MAX_UNPAID_PER_ADDRESS', '3');
$check(!$bookings->phoneVerificationRequired(), 'ONLINE_BOOKING_VERIFY_PHONE=off turns verification off');
$ip = $address();
$made = 0;
for ($i = 0; $i < 3; $i++) {
    $made += str_starts_with($book($form($vehicle(), $phone()), $ip), 'booked:') ? 1 : 0;
}
$check($made === 3, 'three unpaid reservations from one address are allowed', (string) $made);
$fourth = $book($form($vehicle(), $phone()), $ip);
$check(str_contains($fourth, 'still waiting for their downpayment'), 'a fourth from the same address is refused', $fourth);
$check(str_starts_with($book($form($vehicle(), $phone()), $address()), 'booked:'), 'another address can still book');
$waiting = (int) $db->query("SELECT COUNT(*) FROM rental_agreements WHERE booking_source = 'online' AND status = 'reserved' AND downpayment_status = 'due' AND hold_expires_at > UTC_TIMESTAMP(6)")->fetchColumn();
$setting('ONLINE_MAX_UNPAID_HOLDS', (string) $waiting);
$full = $book($form($vehicle(), $phone()), $address());
$check(str_contains($full, 'very busy'), 'when the site-wide cap is reached, new online reservations are refused', $full);

echo $failed === 0 ? "ALL {$passed} ONLINE BOOKING GUARD CHECKS PASSED\n" : "{$failed} FAILED, {$passed} passed\n";
exit($failed === 0 ? 0 : 1);
