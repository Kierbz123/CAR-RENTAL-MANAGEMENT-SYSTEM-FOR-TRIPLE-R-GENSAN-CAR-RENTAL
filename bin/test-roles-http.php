<?php
declare(strict_types=1);

/*
 * End-to-end role and front-end/back-end contract check.
 *
 * Runs against a live server with a MIGRATED, SEEDED, otherwise EMPTY database
 * (it creates its own records and never deletes them). It:
 *   1. drives a full rental, damage and maintenance flow through the real pages,
 *      checking that each form the page renders carries the fields it posts;
 *   2. checks every page against every role (who may open it, who may not);
 *   3. checks every action against every role that must be refused;
 *   4. follows every link each role is shown, so no role is offered a page it
 *      cannot open.
 *
 * Environment: ROLES_HTTP_BASE_URL (e.g. http://127.0.0.1:18080) and
 * ROLES_HTTP_TEST_PASSWORD (14+ characters), plus the usual DB_* settings.
 */
require dirname(__DIR__) . '/app/bootstrap.php';

use TripleR\Config;
use TripleR\Database;
use TripleR\Services\SmsMessageCipher;

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The PHP curl extension is required.\n");
    exit(2);
}

$baseUrl = rtrim(Config::require('ROLES_HTTP_BASE_URL'), '/');
$password = Config::require('ROLES_HTTP_TEST_PASSWORD');
$db = Database::connection();
$tag = bin2hex(random_bytes(4));
$failures = 0;
$passes = 0;

const ALL_ROLES = ['system_admin', 'fleet_manager', 'front_desk', 'driver_coordinator', 'mechanic', 'finance_staff', 'auditor', 'support_staff'];

function check(bool $passed, string $name, string $detail = ''): bool
{
    global $failures, $passes;
    if ($passed) {
        $passes++;
        return true;
    }
    $failures++;
    echo "FAIL: {$name}" . ($detail === '' ? '' : " ({$detail})") . "\n";
    return false;
}

function section(string $title): void
{
    echo "\n== {$title}\n";
}

function client()
{
    $handle = curl_init();
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'TripleR-Role-Review/1.0',
    ]);
    return $handle;
}

/** @param array|string|null $body form array, raw JSON string, or null */
function request($client, string $method, string $path, array|string|null $body = null, array $extraHeaders = [], bool $follow = false, bool $multipart = false): array
{
    $headers = [];
    curl_setopt_array($client, [
        CURLOPT_URL => $GLOBALS['baseUrl'] . $path,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => array_merge(['Accept: text/html,application/json'], $extraHeaders),
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            return strlen($line);
        },
        CURLOPT_FOLLOWLOCATION => $follow,
    ]);
    if ($method === 'POST') {
        $fields = is_array($body) ? ($multipart ? $body : http_build_query($body)) : (string) $body;
        curl_setopt_array($client, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields]);
    } else {
        curl_setopt($client, CURLOPT_HTTPGET, true);
    }
    $response = curl_exec($client);
    if (!is_string($response)) {
        throw new RuntimeException('Request failed: ' . $method . ' ' . $path . ' ' . curl_error($client));
    }
    return ['status' => (int) curl_getinfo($client, CURLINFO_RESPONSE_CODE), 'body' => $response, 'headers' => $headers, 'path' => $path];
}

function csrfFrom(string $html): string
{
    if (!preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $match)) {
        throw new RuntimeException('No CSRF token on the page.');
    }
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Signs in and returns [client, csrf token]. */
function signIn(string $email, string $password, bool $expectWorkspace = true): array
{
    $client = client();
    $form = request($client, 'GET', '/staff/login');
    $response = request($client, 'POST', '/staff/login', ['_csrf' => csrfFrom($form['body']), 'email' => $email, 'password' => $password], [], true);
    if ($expectWorkspace) {
        check($response['status'] === 200 && str_contains($response['body'], 'app-sidebar'), "sign-in works for {$email}", 'HTTP ' . $response['status']);
    }
    return [$client, $response['status'] === 200 && str_contains($response['body'], 'name="_csrf"') ? csrfFrom($response['body']) : '', $response];
}

/** The part of a page inside <main>, i.e. without the sidebar. */
function mainOf(string $html): string
{
    return preg_match('/<main\b.*?<\/main>/s', $html, $match) ? $match[0] : $html;
}

/**
 * Field names of the first form posting to $action. $hidden narrows the match to a
 * form containing a given hidden value (several forms share /rentals/action).
 */
function formFields(string $html, string $action, array $hidden = []): ?array
{
    if (!preg_match_all('/<form\b[^>]*action="' . preg_quote($action, '/') . '"[^>]*>(.*?)<\/form>/s', $html, $forms)) {
        return null;
    }
    foreach ($forms[1] as $inner) {
        foreach ($hidden as $name => $value) {
            if (!preg_match('/name="' . preg_quote($name, '/') . '"\s+value="' . preg_quote((string) $value, '/') . '"/', $inner)) {
                continue 2;
            }
        }
        preg_match_all('/\bname="([^"]+)"/', $inner, $names);
        return array_values(array_unique($names[1]));
    }
    return null;
}

/** Posts through a form the page actually renders, after checking it carries every field we send. */
function submit($client, string $pageHtml, string $action, array $payload, array $hidden = [], ?string $label = null, ?array $files = null): array
{
    $label ??= $action;
    $fields = formFields($pageHtml, $action, $hidden);
    if (!check($fields !== null, "page renders a form for {$label}")) {
        return ['status' => 0, 'body' => '', 'headers' => []];
    }
    // "photos[0]" on the wire is "photos[]" in the markup; compare on the base name.
    $base = static fn (string $name): string => (string) preg_replace('/\[[^\]]*\]$/', '', $name);
    $sent = array_map($base, array_keys($payload + $hidden + ($files ?? [])));
    $missing = array_diff($sent, array_map($base, $fields));
    check($missing === [], "form for {$label} has every field the server reads", 'missing: ' . implode(', ', $missing));
    $body = $payload + $hidden + ['_csrf' => csrfFrom($pageHtml)];
    if ($files !== null) {
        return request($client, 'POST', $action, $body + $files, [], false, true);
    }
    return request($client, 'POST', $action, $body);
}

function idFromLocation(array $response, string $parameter): int
{
    parse_str((string) parse_url($response['headers']['location'] ?? '', PHP_URL_QUERY), $query);
    return (int) ($query[$parameter] ?? 0);
}

$png = tempnam(sys_get_temp_dir(), 'rolepng');
file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j2ioAAAAASUVORK5CYII=', true));

/* ---------------------------------------------------------------------- */
section('Accounts');
$accounts = [];
$insert = $db->prepare('INSERT INTO users (email,password_hash,role,is_active,must_change_password) VALUES (:email,:hash,:role,1,0)');
foreach (ALL_ROLES as $role) {
    $email = "review-{$role}-{$tag}@example.test";
    $insert->execute(['email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $role]);
    $accounts[$role] = ['email' => $email, 'id' => (int) $db->lastInsertId()];
}
$sessions = [];
foreach (ALL_ROLES as $role) {
    [$sessions[$role]['client'], $sessions[$role]['csrf'], $home] = signIn($accounts[$role]['email'], $password);
    $sessions[$role]['home'] = $home['body'];
}
$as = static fn (string $role) => $sessions[$role]['client'];
$get = static fn (string $role, string $path): array => request($sessions[$role]['client'], 'GET', $path);
echo "Signed in as all eight roles.\n";

/* ---------------------------------------------------------------------- */
section('M1 Accounts and sessions (system admin)');
$usersPage = $get('system_admin', '/admin/users');
$newEmail = "review-new-{$tag}@example.test";
$created = submit($as('system_admin'), $usersPage['body'], '/admin/users/create', ['email' => $newEmail, 'role' => 'auditor']);
check(in_array($created['status'], [200, 201], true) && preg_match('/class="temporary-password">([^<]+)</', $created['body'], $temp) === 1, 'admin creates an account and is shown a temporary password once', 'HTTP ' . $created['status']);
$temporary = html_entity_decode($temp[1] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
[$newClient, , $afterLogin] = signIn($newEmail, $temporary, false);
check(str_contains($afterLogin['body'], 'action="/auth/change-password"'), 'a new account must set its own password before anything else');
$blocked = request($newClient, 'GET', '/staff');
check($blocked['status'] === 303 && ($blocked['headers']['location'] ?? '') === '/auth/change-password', 'workspace is closed until the password is changed', 'HTTP ' . $blocked['status']);
$changed = submit($newClient, $afterLogin['body'], '/auth/change-password', ['current_password' => $temporary, 'new_password' => $password . '-new', 'confirm_password' => $password . '-new']);
check($changed['status'] === 303 && ($changed['headers']['location'] ?? '') === '/staff', 'password change opens the workspace', 'HTTP ' . $changed['status']);
check(request($newClient, 'GET', '/staff')['status'] === 200, 'new account reaches the workspace after the change');

$newId = (int) $db->query('SELECT id FROM users WHERE email=' . $db->quote($newEmail))->fetchColumn();
$sessionsPage = $get('system_admin', '/admin/sessions?user_id=' . $newId);
check($sessionsPage['status'] === 200 && formFields($sessionsPage['body'], '/admin/sessions/invalidate') !== null, 'admin sees the new account\'s active session');
if (preg_match('/name="session_id"\s+value="(\d+)"/', $sessionsPage['body'], $sid)) {
    $ended = submit($as('system_admin'), $sessionsPage['body'], '/admin/sessions/invalidate', ['user_id' => (string) $newId, 'session_id' => $sid[1]]);
    check($ended['status'] === 303, 'admin ends that session', 'HTTP ' . $ended['status']);
    $kicked = request($newClient, 'GET', '/staff');
    check($kicked['status'] === 303 && ($kicked['headers']['location'] ?? '') === '/staff/login', 'the ended session is signed out on its next request', 'HTTP ' . $kicked['status']);
}

// Five wrong passwords lock the account; an admin unlocks it.
$lockEmail = "review-lock-{$tag}@example.test";
$insert->execute(['email' => $lockEmail, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'support_staff']);
$lockId = (int) $db->lastInsertId();
for ($attempt = 0; $attempt < 5; $attempt++) {
    signIn($lockEmail, 'definitely-wrong-password', false);
}
$locked = $db->query('SELECT locked_at IS NOT NULL FROM users WHERE id=' . $lockId)->fetchColumn();
check((int) $locked === 1, 'five wrong passwords lock the account');
$usersPage = $get('system_admin', '/admin/users');
$lockedShown = false;
for ($page = 1; $page <= 20 && !$lockedShown; $page++) {
    $listing = $get('system_admin', '/admin/users?page=' . $page)['body'];
    $lockedShown = (bool) preg_match('/' . preg_quote($lockEmail, '/') . '.*?<\/tr>/s', $listing, $row) && str_contains($row[0], 'Locked');
}
check($lockedShown, 'the staff list shows the account as locked');
$unlock = request($as('system_admin'), 'POST', '/admin/users/unlock', ['_csrf' => csrfFrom($usersPage['body']), 'user_id' => (string) $lockId]);
check($unlock['status'] === 303 || $unlock['status'] === 200, 'admin unlocks the account', 'HTTP ' . $unlock['status']);
check((int) $db->query('SELECT locked_at IS NOT NULL FROM users WHERE id=' . $lockId)->fetchColumn() === 0, 'the lock is cleared in the database');

/* ---------------------------------------------------------------------- */
section('M2 Fleet (fleet manager)');
$locationsPage = $get('fleet_manager', '/fleet/locations');
$location = submit($as('fleet_manager'), $locationsPage['body'], '/fleet/locations/create', ['name' => "Review lot {$tag}"]);
check($location['status'] === 303, 'fleet manager adds a location', 'HTTP ' . $location['status']);
$locationsPage = $get('fleet_manager', '/fleet/locations');
$locationId = (int) $db->query('SELECT location_id FROM vehicle_locations WHERE name=' . $db->quote("Review lot {$tag}"))->fetchColumn();
check(str_contains($locationsPage['body'], "Review lot {$tag}") && str_contains(mainOf($locationsPage['body']), 'badge-success">Active'), 'the new location is listed as active');
$retired = submit($as('fleet_manager'), $locationsPage['body'], '/fleet/locations/retire', [], ['location_id' => (string) $locationId], 'retire location');
check($retired['status'] === 303 && (string) $db->query('SELECT location_status FROM vehicle_locations WHERE location_id=' . $locationId)->fetchColumn() === 'retired', 'fleet manager retires the location', 'HTTP ' . $retired['status']);
$locationsPage = $get('fleet_manager', '/fleet/locations');
check(str_contains(mainOf($locationsPage['body']), 'Retired') && formFields($locationsPage['body'], '/fleet/locations/remove') === null, 'the retired location stays listed, and a fleet manager is not offered Remove');
$adminLocations = $get('system_admin', '/fleet/locations');
$removed = submit($as('system_admin'), $adminLocations['body'], '/fleet/locations/remove', [], ['location_id' => (string) $locationId], 'remove location');
check($removed['status'] === 303 && (int) $db->query('SELECT deleted_at IS NOT NULL FROM vehicle_locations WHERE location_id=' . $locationId)->fetchColumn() === 1, 'system admin removes the unused retired location', 'HTTP ' . $removed['status']);

$vehicleIds = [];
foreach ([['RV1', '1500.00', '2500.00'], ['RV2', '1800.00', '']] as [$prefix, $rate, $chauffeurRate]) {
    $form = $get('fleet_manager', '/fleet/vehicles/new');
    $response = submit($as('fleet_manager'), $form['body'], '/fleet/vehicles/create', [
        'plate_number' => $prefix . strtoupper(substr($tag, 0, 5)), 'make' => 'Toyota', 'model' => 'Vios', 'model_year' => '2024', 'color' => 'White',
        'seating_capacity' => '5', 'daily_rate' => $rate, 'chauffeur_daily_rate' => $chauffeurRate, 'current_mileage' => '1000',
        'body_type' => 'sedan', 'transmission' => 'automatic', 'fuel_type' => 'gasoline',
        'engine_number' => '', 'chassis_number' => '', 'insurance_provider' => '', 'registration_expiry' => '', 'insurance_expiry' => '', 'notes' => '',
    ]);
    check($response['status'] === 303, "fleet manager registers vehicle {$prefix}", 'HTTP ' . $response['status'] . ' ' . substr(strip_tags($response['body']), 0, 160));
    $vehicleIds[] = idFromLocation($response, 'vehicle_id') ?: (int) $db->query('SELECT MAX(vehicle_id) FROM vehicles')->fetchColumn();
}
[$vehicleId, $serviceVehicleId] = $vehicleIds;
$vehiclePage = $get('fleet_manager', '/fleet/vehicles/detail?vehicle_id=' . $vehicleId);
check($vehiclePage['status'] === 200 && str_contains($vehiclePage['body'], '₱1,500.00'), 'vehicle page shows the rate in pesos with separators');
$mileage = submit($as('fleet_manager'), $vehiclePage['body'], '/fleet/vehicles/mileage', ['vehicle_id' => (string) $vehicleId, 'mileage' => '1010', 'location_id' => '']);
check($mileage['status'] === 303, 'fleet manager records an odometer reading', 'HTTP ' . $mileage['status']);
$photo = submit($as('fleet_manager'), $vehiclePage['body'], '/fleet/vehicles/photos/upload', ['vehicle_id' => (string) $vehicleId], [], null, ['photo' => new CURLFile($png, 'image/png', 'vehicle.png')]);
check($photo['status'] === 303, 'fleet manager uploads a vehicle photo', 'HTTP ' . $photo['status']);
$vehiclePage = $get('fleet_manager', '/fleet/vehicles/detail?vehicle_id=' . $vehicleId);
check(preg_match('/photos\/show\?photo_id=(\d+)/', $vehiclePage['body'], $photoMatch) === 1, 'the uploaded photo appears on the vehicle page');
if (isset($photoMatch[1])) {
    check($get('fleet_manager', '/fleet/vehicles/photos/show?photo_id=' . $photoMatch[1])['status'] === 200, 'the photo is served to fleet staff');
    check($get('front_desk', '/fleet/vehicles/photos/show?photo_id=' . $photoMatch[1])['status'] === 403, 'the photo is refused to a role without fleet access');
}
$edit = $get('fleet_manager', '/fleet/vehicles/edit?vehicle_id=' . $vehicleId);
check($edit['status'] === 200 && formFields($edit['body'], '/fleet/vehicles/update') !== null, 'vehicle edit form renders');

/* ---------------------------------------------------------------------- */
section('M4 Drivers (fleet manager, driver coordinator)');
$license = 'N01-' . strtoupper(substr($tag, 0, 6)) . '-7788';
$driverForm = $get('fleet_manager', '/fleet/drivers/new');
$driver = submit($as('fleet_manager'), $driverForm['body'], '/fleet/drivers/create', [
    'full_name' => "Review Driver {$tag}", 'license_number' => $license, 'license_expiry' => (new DateTimeImmutable('+2 years'))->format('Y-m-d'),
    'phone' => '+639171234567', 'email' => '', 'address' => '', 'emergency_contact_name' => '', 'emergency_contact_phone' => '', 'notes' => '',
]);
check($driver['status'] === 303, 'fleet manager adds a driver', 'HTTP ' . $driver['status'] . ' ' . substr(strip_tags($driver['body']), 0, 160));
$driverId = idFromLocation($driver, 'driver_id');
$driverPage = $get('fleet_manager', '/fleet/drivers/detail?driver_id=' . $driverId);
check(str_contains($driverPage['body'], '****7788') && !str_contains($driverPage['body'], $license), 'driver page masks the licence number');
$reveal = request($as('fleet_manager'), 'POST', '/fleet/drivers/reveal', ['_csrf' => csrfFrom($driverPage['body']), 'driver_id' => (string) $driverId, 'kind' => 'license', 'record_id' => ''], ['X-CSRF-Token: ' . csrfFrom($driverPage['body'])]);
check($reveal['status'] === 200 && str_contains($reveal['body'], $license), 'fleet manager can reveal the licence number', 'HTTP ' . $reveal['status']);
$coordinatorDriver = $get('driver_coordinator', '/fleet/drivers/detail?driver_id=' . $driverId);
check($coordinatorDriver['status'] === 200 && str_contains($coordinatorDriver['body'], 'Restricted') && !str_contains($coordinatorDriver['body'], '7788') && !str_contains($coordinatorDriver['body'], 'data-reveal-kind'), 'driver coordinator sees the driver with personal details restricted');
check(!str_contains(mainOf($get('driver_coordinator', '/fleet/drivers')['body']), 'Add driver'), 'driver coordinator is not offered "Add driver"');

/* ---------------------------------------------------------------------- */
section('M3 Customers (front desk)');
$customerPhone = '+63918' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
$customerForm = $get('front_desk', '/customers/new');
$customer = submit($as('front_desk'), $customerForm['body'], '/customers/create', [
    'customer_type' => 'walk_in', 'full_name' => "Review Customer {$tag}", 'company_name' => '', 'referral_source' => '',
    'phone' => $customerPhone, 'email' => '', 'document_type' => 'passport', 'document_number' => 'P' . strtoupper($tag) . '55', 'expires_on' => '',
]);
check($customer['status'] === 303, 'front desk adds a customer', 'HTTP ' . $customer['status'] . ' ' . substr(strip_tags($customer['body']), 0, 160));
$customerId = idFromLocation($customer, 'customer_id');
$customerPage = $get('front_desk', '/customers/detail?customer_id=' . $customerId);
check($customerPage['status'] === 200 && !str_contains($customerPage['body'], $customerPhone) && !str_contains($customerPage['body'], 'P' . strtoupper($tag) . '55'), 'customer page masks phone and document numbers');
if (preg_match('/data-reveal-kind="contact"\s+data-record-id="(\d+)"/', $customerPage['body'], $contact)) {
    $revealed = request($as('front_desk'), 'POST', '/customers/reveal', ['_csrf' => csrfFrom($customerPage['body']), 'customer_id' => (string) $customerId, 'kind' => 'contact', 'record_id' => $contact[1]], ['X-CSRF-Token: ' . csrfFrom($customerPage['body'])]);
    check($revealed['status'] === 200 && str_contains($revealed['body'], substr($customerPhone, 3)), 'front desk can reveal the phone number', 'HTTP ' . $revealed['status']);
} else {
    check(false, 'customer page offers a Reveal control for the phone number');
}
$note = submit($as('front_desk'), $customerPage['body'], '/customers/notes/add', ['customer_id' => (string) $customerId, 'note_text' => 'Review note']);
check($note['status'] === 303, 'front desk adds a note', 'HTTP ' . $note['status']);
check(formFields($customerPage['body'], '/customers/blacklist') !== null && formFields($customerPage['body'], '/customers/delete') !== null, 'blacklist and remove controls render');

/* ---------------------------------------------------------------------- */
section('M5/M6 Reservation and chauffeur assignment');
$today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$end = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+2 days')->format('Y-m-d');
$bookingPage = $get('front_desk', '/rentals/new');
$bookingFields = formFields($bookingPage['body'], '/rentals/reserve') ?? [];
$bookingPayload = [
    'rental_type' => 'chauffeur', 'driver_id' => '', 'customer_id' => (string) $customerId, 'vehicle_id' => (string) $vehicleId,
    'start_date' => $today, 'end_date' => $end, 'scheduled_pickup_at' => $today . 'T23:30', 'scheduled_return_at' => $end . 'T10:00', 'deposit_amount' => '1000.00',
];
check(array_diff(array_keys($bookingPayload), $bookingFields) === [], 'reservation form has every field the booking API reads', 'missing: ' . implode(', ', array_diff(array_keys($bookingPayload), $bookingFields)));
check(str_contains($bookingPage['body'], 'value="' . $customerId . '"') && str_contains($bookingPage['body'], 'value="' . $vehicleId . '" data-rate="1500.00"'), 'the new customer and vehicle are offered in the reservation form');
$bookingCsrf = csrfFrom($bookingPage['body']);
$booked = request($as('front_desk'), 'POST', '/api/rentals', json_encode($bookingPayload + ['_csrf' => $bookingCsrf]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $bookingCsrf]);
$bookedJson = json_decode($booked['body'], true);
check(in_array($booked['status'], [200, 201], true) && !empty($bookedJson['agreement_id']), 'front desk creates a chauffeur reservation', 'HTTP ' . $booked['status'] . ' ' . substr($booked['body'], 0, 200));
$agreementId = (int) ($bookedJson['agreement_id'] ?? 0);
$detailPath = '/rentals/detail?agreement_id=' . $agreementId;

$frontDetail = $get('front_desk', $detailPath);
$baseAmount = '₱' . number_format((float) $db->query('SELECT base_amount FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn(), 2);
check($frontDetail['status'] === 200 && str_contains($frontDetail['body'], 'Needs driver') && str_contains($frontDetail['body'], $baseAmount), 'agreement page shows the reservation, base amount and that it needs a driver', 'expected ' . $baseAmount);
$early = submit($as('front_desk'), $frontDetail['body'], '/rentals/action', [], ['action' => 'confirm', 'agreement_id' => (string) $agreementId], 'confirm');
check((string) $db->query('SELECT status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === 'reserved', 'a chauffeur reservation cannot be confirmed without a driver');

$coordinatorList = $get('driver_coordinator', '/rentals');
check($coordinatorList['status'] === 200 && str_contains($coordinatorList['body'], 'Needs driver') && !str_contains(mainOf($coordinatorList['body']), 'Base amount'), 'driver coordinator sees the agreement list without amounts');
$coordinatorDetail = $get('driver_coordinator', $detailPath);
$coordinatorMain = mainOf($coordinatorDetail['body']);
check($coordinatorDetail['status'] === 200 && !str_contains($coordinatorMain, 'Cost summary') && !str_contains($coordinatorMain, 'id="charges"') && !str_contains($coordinatorMain, 'id="deposit"') && !str_contains($coordinatorMain, 'id="damage"'), 'driver coordinator opens the agreement with no financial or damage sections');
$assigned = submit($as('driver_coordinator'), $coordinatorDetail['body'], '/rentals/driver/assign', ['agreement_id' => (string) $agreementId, 'driver_id' => (string) $driverId]);
check($assigned['status'] === 303 && ($assigned['headers']['location'] ?? '') === $detailPath, 'driver coordinator assigns a driver', 'HTTP ' . $assigned['status']);
$coordinatorDetail = $get('driver_coordinator', $detailPath);
check($coordinatorDetail['status'] === 200 && str_contains($coordinatorDetail['body'], "Review Driver {$tag}"), 'after assigning, the coordinator lands back on the agreement and sees the driver');
check((int) $db->query('SELECT COUNT(*) FROM rental_charges WHERE agreement_id=' . $agreementId . " AND charge_type='chauffeur_fee'")->fetchColumn() === 1, 'assigning a driver adds the chauffeur fee');

$frontDetail = $get('front_desk', $detailPath);
$link = submit($as('front_desk'), $frontDetail['body'], '/rentals/link', ['agreement_id' => (string) $agreementId]);
check($link['status'] === 303, 'front desk sends the customer booking link', 'HTTP ' . $link['status']);

// The customer's side: the link in the queued SMS opens the booking, once.
$sms = $db->query("SELECT recipient_phone, template_key, rendered_message FROM notifications WHERE idempotency_key LIKE 'magic-link:%' ORDER BY id DESC LIMIT 1")->fetch();
$smsText = $sms ? (new SmsMessageCipher())->decrypt((string) $sms['rendered_message'], SmsMessageCipher::context((string) $sms['recipient_phone'], (string) $sms['template_key'])) : '';
if (check(preg_match('/#token=([A-Za-z0-9_-]{43})&purpose=([a-z_]+)/', $smsText, $linkParts) === 1, 'the queued SMS carries a secure booking link')) {
    // The server only accepts redemption from its own configured origin (APP_BASE_URL).
    $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', Config::require('APP_BASE_URL'));
    $redeemHeaders = ['Content-Type: application/json', 'Origin: ' . $origin];
    $redeemBody = json_encode(['token' => $linkParts[1], 'purpose' => $linkParts[2]]);
    $customerBrowser = client();
    $foreign = request(client(), 'POST', '/api/magic-links/redeem', $redeemBody, ['Content-Type: application/json', 'Origin: https://elsewhere.example']);
    check($foreign['status'] === 403, 'a secure link cannot be redeemed from another website', 'HTTP ' . $foreign['status']);
    $redeemed = request($customerBrowser, 'POST', '/api/magic-links/redeem', $redeemBody, $redeemHeaders);
    check($redeemed['status'] === 200 && (json_decode($redeemed['body'], true)['verified'] ?? false) === true, 'the customer\'s secure link verifies', 'HTTP ' . $redeemed['status'] . ' ' . substr($redeemed['body'], 0, 160));
    $context = request($customerBrowser, 'GET', '/api/rentals/booking-context');
    $booking = json_decode($context['body'], true)['booking'] ?? [];
    check($context['status'] === 200 && (int) ($booking['agreement_id'] ?? 0) === $agreementId && isset($booking['vehicle'], $booking['status'], $booking['base_amount']), 'the customer then sees their own booking details', 'HTTP ' . $context['status'] . ' ' . substr($context['body'], 0, 160));
    check(!str_contains($context['body'], $customerPhone) && !str_contains($context['body'], 'customer_id'), 'the booking details carry no contact data or internal ids');
    $replay = request(client(), 'POST', '/api/magic-links/redeem', $redeemBody, $redeemHeaders);
    check($replay['status'] === 400, 'the same link cannot be used a second time', 'HTTP ' . $replay['status']);
    check(request($customerBrowser, 'GET', '/rentals')['status'] === 303, 'a customer with a secure link has no access to staff pages');
}
$confirmed = submit($as('front_desk'), $frontDetail['body'], '/rentals/action', [], ['action' => 'confirm', 'agreement_id' => (string) $agreementId], 'confirm');
check((string) $db->query('SELECT status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === 'confirmed', 'front desk confirms the reservation once a driver is assigned');
check((string) $db->query('SELECT current_status FROM vehicles WHERE vehicle_id=' . $vehicleId)->fetchColumn() === 'reserved', 'the vehicle shows as reserved');

/* ---------------------------------------------------------------------- */
section('M7 Inspections, M5 pickup and return (fleet manager)');
$managerDetail = $get('fleet_manager', $detailPath);
$inspectionBase = ['agreement_id' => (string) $agreementId, 'location' => '', 'damage_type' => '', 'severity' => '', 'repair_cost_suggestion' => '', 'notes' => 'Review'];
$pre = submit($as('fleet_manager'), $managerDetail['body'], '/rentals/damage/report', $inspectionBase + ['phase' => 'pre', 'has_damage' => '0'], [], 'pre-rental inspection', ['photos[0]' => new CURLFile($png, 'image/png', 'pre.png')]);
check($pre['status'] === 303, 'fleet manager records the pre-rental inspection', 'HTTP ' . $pre['status'] . ' ' . substr(strip_tags($pre['body']), 0, 160));
$pickup = submit($as('fleet_manager'), $managerDetail['body'], '/rentals/action', ['mileage' => '1020'], ['action' => 'pickup', 'agreement_id' => (string) $agreementId], 'pickup');
check((string) $db->query('SELECT status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === 'active', 'fleet manager records the pickup with the odometer reading', 'HTTP ' . $pickup['status']);
check((string) $db->query('SELECT current_status FROM vehicles WHERE vehicle_id=' . $vehicleId)->fetchColumn() === 'rented', 'the vehicle shows as rented');
$managerDetail = $get('fleet_manager', $detailPath);
$return = submit($as('fleet_manager'), $managerDetail['body'], '/rentals/action', ['mileage' => '1180'], ['action' => 'return', 'agreement_id' => (string) $agreementId], 'return');
check((string) $db->query('SELECT status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === 'returned', 'fleet manager records the return', 'HTTP ' . $return['status']);
check((int) $db->query('SELECT current_mileage FROM vehicles WHERE vehicle_id=' . $vehicleId)->fetchColumn() === 1180, 'the return updates the vehicle mileage');
$managerDetail = $get('fleet_manager', $detailPath);
$post = submit($as('fleet_manager'), $managerDetail['body'], '/rentals/damage/report', ['agreement_id' => (string) $agreementId, 'phase' => 'post', 'has_damage' => '1', 'location' => 'Left rear door', 'damage_type' => 'dent', 'severity' => 'minor', 'repair_cost_suggestion' => '800.00', 'notes' => 'Review'], [], 'post-rental inspection', ['photos[0]' => new CURLFile($png, 'image/png', 'post.png')]);
check($post['status'] === 303, 'fleet manager records damage found at return, with a photo', 'HTTP ' . $post['status'] . ' ' . substr(strip_tags($post['body']), 0, 160));
$reportId = (int) $db->query('SELECT report_id FROM damage_reports WHERE agreement_id=' . $agreementId . " AND phase='post'")->fetchColumn();
$managerDetail = $get('fleet_manager', $detailPath);
$decision = submit($as('fleet_manager'), $managerDetail['body'], '/rentals/damage/liability', ['agreement_id' => (string) $agreementId, 'report_id' => (string) $reportId, 'supersedes_decision_id' => '', 'customer_liable' => '1', 'liable_amount' => '800.00', 'reason' => 'Not present before pickup']);
check($decision['status'] === 303 && (int) $db->query('SELECT COUNT(*) FROM damage_liability_decisions WHERE report_id=' . $reportId)->fetchColumn() === 1, 'fleet manager records the liability decision', 'HTTP ' . $decision['status']);
$adminDetail = $get('system_admin', $detailPath);
check(formFields($adminDetail['body'], '/rentals/damage/report') === null, 'system admin is not offered the inspection form (front desk and fleet manager record inspections)');
$reportPage = $get('auditor', '/rentals/damage/detail?report_id=' . $reportId);
check($reportPage['status'] === 200 && str_contains($reportPage['body'], 'Liability decision history') && preg_match('/damage\/photo\?photo_id=(\d+)/', $reportPage['body'], $damagePhoto) === 1, 'auditor opens the damage report and sees its photo');
if (isset($damagePhoto[1])) {
    check($get('auditor', '/rentals/damage/photo?photo_id=' . $damagePhoto[1])['status'] === 200, 'the damage photo is served to an auditor');
    check($get('mechanic', '/rentals/damage/photo?photo_id=' . $damagePhoto[1])['status'] === 403, 'the damage photo is refused to a mechanic');
}

/* ---------------------------------------------------------------------- */
section('M5/M7 Money and completion (finance)');
$financeDetail = $get('finance_staff', $detailPath);
$financeMain = mainOf($financeDetail['body']);
check(formFields($financeMain, '/rentals/action', ['action' => 'confirm']) === null && formFields($financeMain, '/rentals/damage/report') === null, 'finance is not offered lifecycle or inspection actions');
$decisionId = (int) $db->query('SELECT decision_id FROM damage_liability_decisions WHERE report_id=' . $reportId . ' ORDER BY decision_id DESC LIMIT 1')->fetchColumn();
$damageCharge = submit($as('finance_staff'), $financeDetail['body'], '/rentals/damage/charge', ['agreement_id' => (string) $agreementId, 'decision_id' => (string) $decisionId, 'amount' => '800.00', 'adjustment_reason' => '']);
check($damageCharge['status'] === 303 && (int) $db->query('SELECT COUNT(*) FROM rental_charges WHERE agreement_id=' . $agreementId . " AND charge_type='damage'")->fetchColumn() === 1, 'finance posts the damage charge', 'HTTP ' . $damageCharge['status']);
$fee = submit($as('finance_staff'), $financeDetail['body'], '/rentals/charge', ['agreement_id' => (string) $agreementId, 'charge_type' => 'fee', 'amount' => '150.00', 'description' => 'Cleaning']);
check($fee['status'] === 303, 'finance adds a fee', 'HTTP ' . $fee['status']);
$blockedComplete = submit($as('finance_staff'), $financeDetail['body'], '/rentals/action', [], ['action' => 'complete', 'agreement_id' => (string) $agreementId], 'complete');
check((string) $db->query('SELECT status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === 'returned', 'completion is refused while the deposit is still due');
foreach (['held', 'released'] as $depositStatus) {
    $financeDetail = $get('finance_staff', $detailPath);
    $deposit = submit($as('finance_staff'), $financeDetail['body'], '/rentals/deposit', ['agreement_id' => (string) $agreementId, 'deposit_status' => $depositStatus, 'amount' => '1000.00', 'reason' => 'Review ' . $depositStatus]);
    check((string) $db->query('SELECT deposit_status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === $depositStatus, "finance records the deposit as {$depositStatus}", 'HTTP ' . $deposit['status']);
}
$financeDetail = $get('finance_staff', $detailPath);
$total = (string) $db->query('SELECT base_amount FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn();
check(str_contains($financeDetail['body'], 'Total to bill') && str_contains($financeDetail['body'], '₱' . number_format((float) $total, 2)), 'the cost summary shows the base amount and total');
$complete = submit($as('finance_staff'), $financeDetail['body'], '/rentals/action', [], ['action' => 'complete', 'agreement_id' => (string) $agreementId], 'complete');
check((string) $db->query('SELECT status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === 'completed', 'finance completes the agreement', 'HTTP ' . $complete['status']);
check((string) $db->query('SELECT current_status FROM vehicles WHERE vehicle_id=' . $vehicleId)->fetchColumn() === 'available', 'the vehicle is available again');
$auditorDetail = $get('auditor', $detailPath);
check($auditorDetail['status'] === 200 && substr_count(mainOf($auditorDetail['body']), '<form') === 0, 'auditor reads the completed agreement with no action controls');
check(substr_count(mainOf($get('front_desk', '/customers/detail?customer_id=' . $customerId)['body']), $detailPath) >= 1, 'the rental appears in the customer\'s history');

/* ---------------------------------------------------------------------- */
section('M5 Cancellation (front desk)');
$cancelCsrf = csrfFrom($get('front_desk', '/rentals/new')['body']);
$second = request($as('front_desk'), 'POST', '/api/rentals', json_encode(['rental_type' => 'self_drive', 'driver_id' => '', 'customer_id' => (string) $customerId, 'vehicle_id' => (string) $vehicleId, 'start_date' => $today, 'end_date' => $today, 'scheduled_pickup_at' => $today . 'T23:40', 'scheduled_return_at' => $today . 'T23:50', 'deposit_amount' => '0', '_csrf' => $cancelCsrf]), ['Content-Type: application/json', 'X-CSRF-Token: ' . $cancelCsrf]);
$secondId = (int) (json_decode($second['body'], true)['agreement_id'] ?? 0);
check($secondId > 0, 'front desk creates a self-drive reservation', 'HTTP ' . $second['status'] . ' ' . substr($second['body'], 0, 200));
$secondPage = $get('front_desk', '/rentals/detail?agreement_id=' . $secondId);
$cancel = submit($as('front_desk'), $secondPage['body'], '/rentals/action', ['reason' => 'Customer changed plans'], ['action' => 'cancel', 'agreement_id' => (string) $secondId], 'cancel');
check((string) $db->query('SELECT status FROM rental_agreements WHERE agreement_id=' . $secondId)->fetchColumn() === 'cancelled', 'front desk cancels it with a reason', 'HTTP ' . $cancel['status']);

/* ---------------------------------------------------------------------- */
section('M8 Maintenance (fleet manager, mechanic, auditor)');
$maintenancePage = $get('fleet_manager', '/maintenance');
$schedule = submit($as('fleet_manager'), $maintenancePage['body'], '/maintenance/schedules/create', [
    'vehicle_id' => (string) $serviceVehicleId, 'schedule_name' => "Oil {$tag}", 'interval_time_days' => '30', 'interval_mileage' => '1000',
    'next_due_date' => '', 'next_due_mileage' => '', 'due_soon_days_override' => '', 'due_soon_mileage_override' => '', 'reason' => 'Review',
]);
check($schedule['status'] === 303, 'fleet manager creates a maintenance schedule', 'HTTP ' . $schedule['status'] . ' ' . substr(strip_tags($schedule['body']), 0, 160));
$scheduleId = (int) $db->query('SELECT schedule_id FROM maintenance_schedules WHERE vehicle_id=' . $serviceVehicleId)->fetchColumn();
check(formFields($get('mechanic', '/maintenance')['body'], '/maintenance/schedules/create') === null, 'mechanic is not offered schedule setup');
$serviceForm = $get('mechanic', '/maintenance/service/new?vehicle_id=' . $serviceVehicleId);
$started = submit($as('mechanic'), $serviceForm['body'], '/maintenance/service/start', ['vehicle_id' => (string) $serviceVehicleId, 'schedule_id' => (string) $scheduleId, 'title' => 'Oil and filter', 'notes' => '']);
check($started['status'] === 303, 'mechanic starts a service', 'HTTP ' . $started['status'] . ' ' . substr(strip_tags($started['body']), 0, 160));
$serviceId = idFromLocation($started, 'service_id');
$servicePath = '/maintenance/service?service_id=' . $serviceId;
check((string) $db->query('SELECT current_status FROM vehicles WHERE vehicle_id=' . $serviceVehicleId)->fetchColumn() === 'maintenance', 'the vehicle is marked as in maintenance');
check(!str_contains($get('front_desk', '/rentals/new')['body'], 'value="' . $serviceVehicleId . '" data-rate'), 'a vehicle in maintenance is not offered for booking');
$servicePage = $get('mechanic', $servicePath);
$costs = submit($as('mechanic'), $servicePage['body'], '/maintenance/service/costs', ['service_id' => (string) $serviceId, 'labor_cost' => '300.00', 'parts_cost' => '450.00', 'other_cost' => '0.00', 'reason' => 'Parts used']);
check($costs['status'] === 303, 'mechanic records costs', 'HTTP ' . $costs['status']);
$servicePhoto = submit($as('mechanic'), $servicePage['body'], '/maintenance/service/photo', ['service_id' => (string) $serviceId], ['phase' => 'before'], 'service photo', ['photo' => new CURLFile($png, 'image/png', 'before.png')]);
check($servicePhoto['status'] === 303, 'mechanic uploads a before photo', 'HTTP ' . $servicePhoto['status']);
$auditorService = $get('auditor', $servicePath);
check($auditorService['status'] === 200 && substr_count(mainOf($auditorService['body']), '<form') === 0 && str_contains($auditorService['body'], '₱750.00'), 'auditor reads the service and its total with no action controls');
$servicePage = $get('mechanic', $servicePath);
$completed = submit($as('mechanic'), $servicePage['body'], '/maintenance/service/complete', ['service_id' => (string) $serviceId, 'mileage' => '1100']);
check((string) $db->query('SELECT status FROM maintenance_services WHERE service_id=' . $serviceId)->fetchColumn() === 'completed', 'mechanic completes the service', 'HTTP ' . $completed['status']);
check((string) $db->query('SELECT current_status FROM vehicles WHERE vehicle_id=' . $serviceVehicleId)->fetchColumn() === 'available', 'the vehicle returns to available');
check($get('auditor', '/maintenance/history?vehicle_id=' . $serviceVehicleId)['status'] === 200 && $get('auditor', '/maintenance/due')['status'] === 200, 'auditor opens maintenance history and the due report');
$csv = $get('fleet_manager', '/maintenance/due?format=csv');
check($csv['status'] === 200 && str_contains($csv['headers']['content-type'] ?? '', 'csv'), 'the due report downloads as CSV');

/* ---------------------------------------------------------------------- */
section('Notifications and customer pages');
$api = $get('support_staff', '/api/staff/notifications');
$apiJson = json_decode($api['body'], true);
check($api['status'] === 200 && is_array($apiJson['notifications'] ?? null), 'support staff loads the SMS history');
check(count($apiJson['notifications'] ?? []) >= 1, 'the booking messages queued during this run appear in the history');
check($get('support_staff', '/staff/notifications')['status'] === 200, 'support staff opens the notifications page');
$guest = client();
check(request($guest, 'GET', '/')['status'] === 200, 'landing page is public');
check(request($guest, 'GET', '/magic-link')['status'] === 200 && request($guest, 'GET', '/customer/booking')['status'] === 200, 'secure-link and customer booking pages are public');
check(request($guest, 'GET', '/api/rentals/booking-context')['status'] === 401, 'booking details are refused without a verified secure link');
$missing = request($guest, 'GET', '/no-such-page');
check($missing['status'] === 404 && str_contains($missing['body'], 'Page not found'), 'an unknown address shows the styled 404 page');

/* ---------------------------------------------------------------------- */
section('Role matrix: who may open each page');
$editUser = '/admin/users/edit?user_id=' . $accounts['auditor']['id'];
$pages = [
    '/staff' => ALL_ROLES,
    '/api/staff/navigation' => ALL_ROLES,
    '/admin/users' => ['system_admin'],
    $editUser => ['system_admin'],
    '/admin/sessions?user_id=' . $accounts['auditor']['id'] => ['system_admin'],
    '/staff/notifications' => ['system_admin', 'fleet_manager', 'support_staff'],
    '/api/staff/notifications' => ['system_admin', 'fleet_manager', 'support_staff'],
    '/fleet/vehicles' => ['system_admin', 'fleet_manager'],
    '/fleet/vehicles/new' => ['system_admin', 'fleet_manager'],
    '/fleet/vehicles/detail?vehicle_id=' . $vehicleId => ['system_admin', 'fleet_manager'],
    '/fleet/vehicles/edit?vehicle_id=' . $vehicleId => ['system_admin', 'fleet_manager'],
    '/fleet/locations' => ['system_admin', 'fleet_manager'],
    '/fleet/drivers' => ['system_admin', 'fleet_manager', 'driver_coordinator'],
    '/fleet/drivers/detail?driver_id=' . $driverId => ['system_admin', 'fleet_manager', 'driver_coordinator'],
    '/fleet/drivers/new' => ['system_admin', 'fleet_manager'],
    '/fleet/drivers/edit?driver_id=' . $driverId => ['system_admin', 'fleet_manager'],
    '/customers' => ['system_admin', 'front_desk'],
    '/customers/new' => ['system_admin', 'front_desk'],
    '/customers/detail?customer_id=' . $customerId => ['system_admin', 'front_desk'],
    '/customers/edit?customer_id=' . $customerId => ['system_admin', 'front_desk'],
    '/rentals' => ['system_admin', 'fleet_manager', 'front_desk', 'finance_staff', 'auditor', 'driver_coordinator'],
    $detailPath => ['system_admin', 'fleet_manager', 'front_desk', 'finance_staff', 'auditor', 'driver_coordinator'],
    '/rentals/new' => ['system_admin', 'front_desk'],
    '/rentals/damage/detail?report_id=' . $reportId => ['system_admin', 'front_desk', 'fleet_manager', 'finance_staff', 'auditor'],
    '/maintenance' => ['system_admin', 'fleet_manager', 'mechanic', 'auditor'],
    '/maintenance/history?vehicle_id=' . $serviceVehicleId => ['system_admin', 'fleet_manager', 'mechanic', 'auditor'],
    '/maintenance/due' => ['system_admin', 'fleet_manager', 'mechanic', 'auditor'],
    $servicePath => ['system_admin', 'fleet_manager', 'mechanic', 'auditor'],
    '/maintenance/service/new' => ['system_admin', 'fleet_manager', 'mechanic'],
];
$pageChecks = 0;
foreach ($pages as $path => $allowed) {
    foreach (ALL_ROLES as $role) {
        $status = $get($role, $path)['status'];
        $expected = in_array($role, $allowed, true) ? 200 : 403;
        $pageChecks++;
        check($status === $expected, "{$role} " . ($expected === 200 ? 'may open' : 'is refused') . " {$path}", "expected {$expected}, got {$status}");
    }
    $anonymous = request($guest, 'GET', $path);
    $expectedAnonymous = str_starts_with($path, '/api/') ? 401 : 303;
    check($anonymous['status'] === $expectedAnonymous, "a signed-out visitor is turned away from {$path}", "expected {$expectedAnonymous}, got " . $anonymous['status']);
}
echo "Checked " . count($pages) . " pages against 8 roles and a signed-out visitor ({$pageChecks} role checks).\n";

/* ---------------------------------------------------------------------- */
section('Role matrix: who is refused each action');
$fleet = ['system_admin', 'fleet_manager'];
$desk = ['system_admin', 'front_desk'];
$finance = ['system_admin', 'finance_staff'];
$operate = ['system_admin', 'fleet_manager', 'mechanic'];
$actions = [
    '/admin/users/create' => ['system_admin'], '/admin/users/update' => ['system_admin'], '/admin/users/role' => ['system_admin'],
    '/admin/users/deactivate' => ['system_admin'], '/admin/users/reactivate' => ['system_admin'], '/admin/users/unlock' => ['system_admin'],
    '/admin/users/reset-password' => ['system_admin'], '/admin/sessions/invalidate' => ['system_admin'],
    '/fleet/vehicles/create' => $fleet, '/fleet/vehicles/update' => $fleet, '/fleet/vehicles/status' => $fleet, '/fleet/vehicles/mileage' => $fleet,
    '/fleet/vehicles/photos/upload' => $fleet, '/fleet/locations/create' => $fleet, '/fleet/locations/retire' => $fleet, '/fleet/locations/remove' => ['system_admin'],
    '/fleet/drivers/create' => $fleet, '/fleet/drivers/update' => $fleet, '/fleet/drivers/status' => $fleet, '/fleet/drivers/delete' => $fleet,
    '/fleet/drivers/contacts/add' => $fleet, '/fleet/drivers/contacts/update' => $fleet, '/fleet/drivers/contacts/remove' => $fleet, '/fleet/drivers/reveal' => $fleet,
    '/customers/create' => $desk, '/customers/update' => $desk, '/customers/contacts/add' => $desk, '/customers/contacts/update' => $desk,
    '/customers/contacts/remove' => $desk, '/customers/documents/add' => $desk, '/customers/documents/update' => $desk, '/customers/notes/add' => $desk,
    '/customers/blacklist' => $desk, '/customers/unblacklist' => $desk, '/customers/delete' => $desk, '/customers/reveal' => $desk,
    '/rentals/reserve' => $desk, '/api/rentals' => $desk, '/rentals/link' => $desk,
    '/rentals/action#confirm' => $desk, '/rentals/action#cancel' => $desk, '/rentals/action#no_show' => $desk,
    '/rentals/action#pickup' => ['system_admin', 'front_desk', 'fleet_manager'], '/rentals/action#return' => ['system_admin', 'front_desk', 'fleet_manager'],
    '/rentals/action#complete' => $finance,
    '/rentals/driver/assign' => ['system_admin', 'front_desk', 'driver_coordinator'], '/rentals/driver/remove' => ['system_admin', 'front_desk', 'driver_coordinator'],
    '/rentals/charge' => $finance, '/rentals/charge/reverse' => $finance, '/rentals/deposit' => $finance,
    '/rentals/damage/report' => ['front_desk', 'fleet_manager'], '/rentals/damage/liability' => ['fleet_manager', 'system_admin'], '/rentals/damage/charge' => ['finance_staff'],
    '/maintenance/schedules/create' => $fleet, '/maintenance/schedules/update' => $fleet, '/maintenance/service/review' => $fleet,
    '/maintenance/service/start' => $operate, '/maintenance/service/complete' => $operate, '/maintenance/service/cancel' => $operate,
    '/maintenance/service/costs' => $operate, '/maintenance/service/photo' => $operate,
];
$actionChecks = 0;
$guestPost = client();
foreach ($actions as $key => $allowed) {
    [$path, $actionValue] = array_pad(explode('#', $key, 2), 2, null);
    foreach (ALL_ROLES as $role) {
        if (in_array($role, $allowed, true)) {
            continue; // Allowed roles are exercised by the flows above; an empty post here could change data.
        }
        $token = $sessions[$role]['csrf'];
        $body = ['_csrf' => $token] + ($actionValue !== null ? ['action' => $actionValue, 'agreement_id' => (string) $agreementId] : []);
        $response = $path === '/api/rentals'
            ? request($sessions[$role]['client'], 'POST', $path, json_encode($body), ['Content-Type: application/json', 'X-CSRF-Token: ' . $token])
            : request($sessions[$role]['client'], 'POST', $path, $body, ['X-CSRF-Token: ' . $token]);
        $actionChecks++;
        check($response['status'] === 403, "{$role} is refused {$key}", 'got ' . $response['status']);
    }
    $anonymous = request($guestPost, 'POST', $path, $actionValue !== null ? ['action' => $actionValue] : []);
    check(in_array($anonymous['status'], [303, 401, 403], true), "a signed-out visitor is refused {$key}", 'got ' . $anonymous['status']);
}
echo "Checked " . count($actions) . " actions against every role that must be refused ({$actionChecks} checks).\n";

/* ---------------------------------------------------------------------- */
section('Menus: each role sees exactly its own');
$menus = [
    'system_admin' => ['Workspace', 'Agreements', 'Customers', 'Vehicles', 'Locations', 'Drivers', 'Maintenance', 'Notifications', 'Staff accounts'],
    'fleet_manager' => ['Workspace', 'Agreements', 'Vehicles', 'Locations', 'Drivers', 'Maintenance', 'Notifications'],
    'front_desk' => ['Workspace', 'Agreements', 'Customers'],
    'driver_coordinator' => ['Workspace', 'Agreements', 'Drivers'],
    'mechanic' => ['Workspace', 'Maintenance'],
    'finance_staff' => ['Workspace', 'Agreements'],
    'auditor' => ['Workspace', 'Agreements', 'Maintenance'],
    'support_staff' => ['Workspace', 'Notifications'],
];
foreach ($menus as $role => $expected) {
    $home = $get($role, '/staff')['body'];
    preg_match('/<nav class="app-nav".*?<\/nav>/s', $home, $nav);
    preg_match_all('/<span>([^<]+)<\/span><\/a>/', $nav[0] ?? '', $labels);
    check($labels[1] === $expected, "{$role} menu", 'got: ' . implode(', ', $labels[1]));
}

/* ---------------------------------------------------------------------- */
section('Links: no role is shown a page it cannot open');
$linkChecks = 0;
foreach (ALL_ROLES as $role) {
    $queue = ['/staff'];
    $seen = [];
    while ($queue !== [] && count($seen) < 120) {
        $path = array_shift($queue);
        if (isset($seen[$path])) {
            continue;
        }
        $response = $get($role, $path);
        $seen[$path] = $response['status'];
        $linkChecks++;
        check($response['status'] === 200, "{$role} can open a link it is shown: {$path}", 'HTTP ' . $response['status']);
        if ($response['status'] !== 200 || !str_contains($response['headers']['content-type'] ?? '', 'text/html')) {
            continue;
        }
        preg_match_all('/href="(\/[^"#]*)(?:#[^"]*)?"/', $response['body'], $links);
        foreach (array_unique($links[1]) as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($href === '/' || str_starts_with($href, '/assets/') || $href === '/favicon.svg' || str_contains($href, 'page=') || isset($seen[$href])) {
                continue;
            }
            $queue[] = $href;
        }
    }
}
echo "Followed {$linkChecks} links across the eight roles.\n";

@unlink($png);
echo "\n" . ($failures === 0 ? "ALL {$passes} CHECKS PASSED" : "{$failures} FAILED, {$passes} passed") . "\n";
exit($failures === 0 ? 0 : 1);
