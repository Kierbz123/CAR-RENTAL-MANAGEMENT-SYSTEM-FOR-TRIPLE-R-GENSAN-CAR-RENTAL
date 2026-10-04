<?php
declare(strict_types=1);

/*
 * End-to-end role and front-end/back-end contract check.
 *
 * Runs against a live server with a MIGRATED, SEEDED, otherwise EMPTY database
 * (it creates its own records and never deletes them). It:
 *   1. drives a full rental and damage flow through the real pages,
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
use TripleR\Services\OnlineBookingService;
use TripleR\Services\SmsMessageCipher;
use TripleR\Services\TelegramLinkService;

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

const ALL_ROLES = ['system_admin', 'fleet_manager', 'front_desk', 'driver_coordinator', 'finance_staff'];

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
echo "Signed in as all five roles.\n";

/* ---------------------------------------------------------------------- */
section('M1 Accounts and sessions (system admin)');
$usersPage = $get('system_admin', '/admin/users');
$newEmail = "review-new-{$tag}@example.test";
$created = submit($as('system_admin'), $usersPage['body'], '/admin/users/create', ['email' => $newEmail, 'role' => 'driver_coordinator']);
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
$insert->execute(['email' => $lockEmail, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => 'driver_coordinator']);
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
check($coordinatorDriver['status'] === 200 && str_contains($coordinatorDriver['body'], '****7788') && !str_contains($coordinatorDriver['body'], $license) && str_contains($coordinatorDriver['body'], 'data-reveal-kind'), 'driver coordinator sees the licence masked, with Reveal');
$coordinatorReveal = request($as('driver_coordinator'), 'POST', '/fleet/drivers/reveal', ['_csrf' => csrfFrom($coordinatorDriver['body']), 'driver_id' => (string) $driverId, 'kind' => 'license', 'record_id' => ''], ['X-CSRF-Token: ' . csrfFrom($coordinatorDriver['body'])]);
check($coordinatorReveal['status'] === 200 && str_contains($coordinatorReveal['body'], $license), 'driver coordinator can reveal the licence number for scheduling', 'HTTP ' . $coordinatorReveal['status']);
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
$searched = $get('front_desk', '/customers?search=' . rawurlencode("Review Customer {$tag}"));
check($searched['status'] === 200 && str_contains(mainOf($searched['body']), "Review Customer {$tag}") && str_contains(mainOf($searched['body']), '1 customer'), 'searching the customer list by name finds the customer', 'HTTP ' . $searched['status']);
check(str_contains(mainOf($get('front_desk', '/customers?search=' . rawurlencode('no-such-customer-' . $tag))['body']), 'No customers found'), 'a search with no match says so');

/* ---------------------------------------------------------------------- */
section('Telegram connection (front desk)');
$customerPath = '/customers/detail?customer_id=' . $customerId;
$statusPath = '/api/customers/telegram/status?customer_id=' . $customerId;
check(str_contains($customerPage['body'], 'id="telegram"') && !str_contains($customerPage['body'], 'qrcode-generator.js'), 'the customer page has a Telegram panel and does not load the QR library until a code is shown');
if (formFields($customerPage['body'], '/customers/telegram/code') === null) {
    // The server under test has no bot configured.
    check(str_contains(mainOf($customerPage['body']), 'Telegram is not set up'), 'without a bot the panel says Telegram is not set up and offers no Connect button');
    echo "Telegram is not configured on this server; the connection flow is covered by bin/test-telegram.php.\n";
} else {
    $listPage = $get('front_desk', '/customers?search=' . rawurlencode("Review Customer {$tag}"));
    $fromList = submit($as('front_desk'), $listPage['body'], '/customers/telegram/code', [], ['customer_id' => (string) $customerId], 'Show QR code on the customer list');
    check($fromList['status'] === 303 && str_ends_with((string) ($fromList['headers']['location'] ?? ''), $customerPath . '#telegram'), 'the customer list has a Show QR code button that goes straight to the Telegram panel', 'HTTP ' . $fromList['status']);
    check(preg_match('/data-qr="https:\/\/t\.me\//', $get('front_desk', $customerPath)['body']) === 1, 'and the QR code is on screen when the page opens');
    $created = submit($as('front_desk'), $customerPage['body'], '/customers/telegram/code', ['customer_id' => (string) $customerId]);
    check($created['status'] === 303 && str_ends_with((string) ($created['headers']['location'] ?? ''), '#telegram'), 'front desk creates a connection code', 'HTTP ' . $created['status']);
    $codePage = $get('front_desk', $customerPath);
    $hasQr = preg_match('/data-qr="https:\/\/t\.me\/[A-Za-z0-9_]+\?start=([A-Z2-9]{8})"/', $codePage['body'], $qr) === 1;
    check($hasQr, 'the page carries the bot link with an 8-character code for the QR code');
    $telegramCode = $qr[1] ?? '';
    check(str_contains($codePage['body'], 'vendor/qrcode-generator.js') && str_contains($codePage['body'], 'telegram-connect.js'), 'the QR scripts are loaded while the code is shown');
    check($hasQr && str_contains($codePage['body'], substr($telegramCode, 0, 4) . '-' . substr($telegramCode, 4)), 'the code is also shown for typing by hand');
    check(str_contains($codePage['body'], 'data-status-url="' . htmlspecialchars($statusPath) . '"'), 'the panel knows where to watch for the customer pressing Start');
    check(!str_contains($get('system_admin', $customerPath)['body'], $telegramCode), 'the code is shown only to the staff member who created it');
    $waiting = $get('front_desk', $statusPath);
    check($waiting['status'] === 200 && (json_decode($waiting['body'], true)['connected'] ?? null) === false, 'the status check says not connected yet');
    // The customer's side (pressing Start in Telegram) is played here; bin/test-telegram.php covers the bot itself.
    $telegramChat = (string) random_int(1_000_000_000, 9_000_000_000);
    check(TelegramLinkService::create($db)->redeem($telegramCode, $telegramChat) === 'connected', 'the customer connects with that code');
    check((json_decode($get('front_desk', $statusPath)['body'], true)['connected'] ?? null) === true, 'the status check now says connected');
    $connectedPage = $get('front_desk', $customerPath);
    check(str_contains(mainOf($connectedPage['body']), 'Connected since') && !str_contains($connectedPage['body'], $telegramCode) && !str_contains($connectedPage['body'], $telegramChat), 'the page shows Connected, and neither the used code nor the chat id');
    check(str_contains(mainOf($get('front_desk', '/customers?search=' . rawurlencode("Review Customer {$tag}"))['body']), '>Connected<'), 'the customer list shows the customer as Connected');
    $disconnected = submit($as('front_desk'), $connectedPage['body'], '/customers/telegram/disconnect', ['customer_id' => (string) $customerId]);
    $afterDisconnect = $get('front_desk', $customerPath);
    check($disconnected['status'] === 303 && str_contains(mainOf($afterDisconnect['body']), 'disconnected by staff') && formFields($afterDisconnect['body'], '/customers/telegram/code') !== null, 'front desk disconnects the customer and can connect them again');
}

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

// The 30% downpayment: stored at booking, recorded by finance (here as GCash at the counter), required before confirming.
// For a chauffeur rental the rental cost includes the chauffeur rate for every day billed.
$booking = $db->query('SELECT base_amount + rental_days * COALESCE(chauffeur_daily_rate, 0) AS rental_cost, downpayment_amount, downpayment_status, TIMESTAMPDIFF(MINUTE, created_at, hold_expires_at) AS hold_minutes FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetch();
$downpaymentText = '₱' . number_format((float) $booking['downpayment_amount'], 2);
check(abs((float) $booking['downpayment_amount'] - round((float) $booking['rental_cost'] * 0.30, 2)) < 0.005 && $booking['downpayment_status'] === 'due', 'the booking stores a downpayment of 30% of the rental, marked due', $booking['downpayment_amount'] . ' of ' . $booking['base_amount']);
check((int) $booking['hold_minutes'] >= 1439, 'the vehicle is held for 24 hours while the customer pays', $booking['hold_minutes'] . ' minutes');
$frontMain = mainOf($frontDetail['body']);
check(str_contains($frontMain, 'Waiting for the downpayment of ' . $downpaymentText) && formFields($frontMain, '/rentals/action', ['action' => 'confirm']) === null, 'front desk is told the downpayment is awaited and is not offered Confirm');
check(formFields($frontMain, '/rentals/downpayment') === null, 'front desk is not offered the form to record a payment');
$forced = request($as('front_desk'), 'POST', '/rentals/action', ['_csrf' => $sessions['front_desk']['csrf'], 'action' => 'confirm', 'agreement_id' => (string) $agreementId]);
check($forced['status'] === 303 && (string) $db->query('SELECT status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === 'reserved', 'posting Confirm anyway is refused by the server and the agreement stays reserved');
check(str_contains(mainOf($get('front_desk', '/rentals?status=reserved')['body']), 'Downpayment due'), 'the agreements list flags the reservation as Downpayment due');
$financeBooking = $get('finance_staff', $detailPath);
$badReference = submit($as('finance_staff'), $financeBooking['body'], '/rentals/downpayment', ['agreement_id' => (string) $agreementId, 'reference' => 'x']);
check((string) $db->query('SELECT downpayment_status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === 'due', 'a reference number that is too short is refused');
$gcashReference = 'GC' . strtoupper($tag) . '001';
$paid = submit($as('finance_staff'), $financeBooking['body'], '/rentals/downpayment', ['agreement_id' => (string) $agreementId, 'reference' => $gcashReference]);
$paidRow = $db->query("SELECT r.downpayment_status, p.external_reference, p.method, p.channel, p.recorded_by, p.receipt_number FROM rental_agreements r LEFT JOIN payments p ON p.paid_downpayment_agreement_id = r.agreement_id WHERE r.agreement_id=" . $agreementId)->fetch();
check($paid['status'] === 303 && $paidRow['downpayment_status'] === 'received' && $paidRow['external_reference'] === $gcashReference && $paidRow['method'] === 'gcash' && $paidRow['channel'] === 'staff' && (int) $paidRow['recorded_by'] === $accounts['finance_staff']['id'], 'finance records the downpayment as a GCash payment with its reference', 'HTTP ' . $paid['status']);
$downpaymentReceipt = (string) $paidRow['receipt_number'];
$financeBooking = $get('finance_staff', $detailPath);
check(str_contains(mainOf($financeBooking['body']), $gcashReference) && formFields($financeBooking['body'], '/rentals/downpayment') === null, 'the agreement shows the reference and no longer offers the form');
check(str_contains(mainOf($financeBooking['body']), '/payments/receipt?receipt=' . $downpaymentReceipt) && formFields($financeBooking['body'], '/rentals/payment') === null, 'the payment is listed with its receipt, and the balance is not taken before the reservation is confirmed');
$receiptPage = $get('finance_staff', '/payments/receipt?receipt=' . $downpaymentReceipt);
check($receiptPage['status'] === 200 && str_contains($receiptPage['body'], $downpaymentReceipt) && str_contains($receiptPage['body'], $gcashReference) && str_contains($receiptPage['body'], 'Recorded at the counter'), 'finance opens the receipt for that payment');
$frontDetail = $get('front_desk', $detailPath);
check(formFields($frontDetail['body'], '/rentals/action', ['action' => 'confirm']) !== null && !str_contains(mainOf($frontDetail['body']), 'Waiting for the downpayment'), 'front desk is now offered Confirm');
$early = submit($as('front_desk'), $frontDetail['body'], '/rentals/action', [], ['action' => 'confirm', 'agreement_id' => (string) $agreementId], 'confirm');
check((string) $db->query('SELECT status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === 'reserved', 'a chauffeur reservation cannot be confirmed without a driver');

$coordinatorList = $get('driver_coordinator', '/rentals');
check($coordinatorList['status'] === 200 && str_contains($coordinatorList['body'], 'Needs driver') && !str_contains(mainOf($coordinatorList['body']), 'Base amount'), 'driver coordinator sees the agreement list without amounts');
$coordinatorDetail = $get('driver_coordinator', $detailPath);
$coordinatorMain = mainOf($coordinatorDetail['body']);
check(!str_contains($coordinatorMain, 'id="downpayment"') && !str_contains($coordinatorMain, 'ownpayment'), 'driver coordinator sees nothing about the downpayment');
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
    foreach (['submit_payment', 'accept_rules'] as $unbuilt) {
        $refusedPurpose = request(client(), 'POST', '/api/magic-links/redeem', json_encode(['token' => $linkParts[1], 'purpose' => $unbuilt]), $redeemHeaders);
        check($refusedPurpose['status'] === 400, "a link cannot be redeemed for the unbuilt purpose {$unbuilt}", 'HTTP ' . $refusedPurpose['status']);
    }
    $redeemed = request($customerBrowser, 'POST', '/api/magic-links/redeem', $redeemBody, $redeemHeaders);
    check($redeemed['status'] === 200 && (json_decode($redeemed['body'], true)['verified'] ?? false) === true, 'the customer\'s secure link verifies', 'HTTP ' . $redeemed['status'] . ' ' . substr($redeemed['body'], 0, 160));
    $context = request($customerBrowser, 'GET', '/api/rentals/booking-context');
    $booking = json_decode($context['body'], true)['booking'] ?? [];
    check($context['status'] === 200 && (int) ($booking['agreement_id'] ?? 0) === $agreementId && isset($booking['vehicle'], $booking['status'], $booking['base_amount']), 'the customer then sees their own booking details', 'HTTP ' . $context['status'] . ' ' . substr($context['body'], 0, 160));
    check(!str_contains($context['body'], $customerPhone) && !str_contains($context['body'], 'customer_id'), 'the booking details carry no contact data or internal ids');
    check(($booking['downpayment_status'] ?? '') === 'received' && isset($booking['downpayment_amount'], $booking['balance_at_pickup']) && !str_contains($context['body'], $gcashReference), 'the customer sees the downpayment, that it was received, and the balance at pickup, but not the reference number');
    $replay = request(client(), 'POST', '/api/magic-links/redeem', $redeemBody, $redeemHeaders);
    check($replay['status'] === 400, 'the same link cannot be used a second time', 'HTTP ' . $replay['status']);
    $linkEvents = $db->prepare("SELECT s.event_type FROM security_logs s JOIN booking_access_tokens t ON t.id = s.token_id WHERE t.token_hash = :hash ORDER BY s.id");
    $linkEvents->execute(['hash' => hash('sha256', $linkParts[1])]);
    check($linkEvents->fetchAll(PDO::FETCH_COLUMN) === ['magic_link_redeem', 'magic_link_redeem_rejected'], 'both the accepted use and the refused replay are recorded as security events tied to that link');
    check(request($customerBrowser, 'GET', '/rentals')['status'] === 303, 'a customer with a secure link has no access to staff pages');
}
$confirmed = submit($as('front_desk'), $frontDetail['body'], '/rentals/action', [], ['action' => 'confirm', 'agreement_id' => (string) $agreementId], 'confirm');
check((string) $db->query('SELECT status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === 'confirmed', 'front desk confirms the reservation once a driver is assigned');
$confirmation = $db->query("SELECT recipient_phone, template_key, rendered_message FROM notifications WHERE idempotency_key = 'rental-{$agreementId}-confirmed'")->fetch();
$confirmationText = $confirmation ? (new SmsMessageCipher())->decrypt((string) $confirmation['rendered_message'], SmsMessageCipher::context((string) $confirmation['recipient_phone'], (string) $confirmation['template_key'])) : '';
check(preg_match('/Downpayment received: ₱[\d,.]+\. Balance of ₱[\d,.]+ is due at pickup\./u', $confirmationText) === 1, 'the confirmation message states the downpayment received and the balance due at pickup', $confirmationText);
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
$reportPage = $get('finance_staff', '/rentals/damage/detail?report_id=' . $reportId);
check($reportPage['status'] === 200 && str_contains($reportPage['body'], 'Liability decision history') && preg_match('/damage\/photo\?photo_id=(\d+)/', $reportPage['body'], $damagePhoto) === 1, 'finance opens the damage report and sees its photo');
if (isset($damagePhoto[1])) {
    check($get('finance_staff', '/rentals/damage/photo?photo_id=' . $damagePhoto[1])['status'] === 200, 'the damage photo is served to finance');
    check($get('driver_coordinator', '/rentals/damage/photo?photo_id=' . $damagePhoto[1])['status'] === 403, 'the damage photo is refused to a driver coordinator');
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
// The balance: what is still owed after the downpayment must be recorded before the agreement is completed.
$owedComplete = submit($as('finance_staff'), $financeDetail['body'], '/rentals/action', [], ['action' => 'complete', 'agreement_id' => (string) $agreementId], 'complete');
check((string) $db->query('SELECT status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === 'returned' && str_contains($get('finance_staff', $detailPath)['body'], 'Record the balance of'), 'completion is refused while the balance is still owed');
check(preg_match('/name="amount" type="number" min="0\.01" max="([\d.]+)"/', (string) (preg_match('/<form\b[^>]*action="\/rentals\/payment"[^>]*>.*?<\/form>/s', $financeDetail['body'], $balanceForm) ? $balanceForm[0] : ''), $owed) === 1 && (float) $owed[1] > 1000, 'finance is offered the form to record the balance, filled in with what is owed', $owed[1] ?? 'no form');
check(formFields($get('front_desk', $detailPath)['body'], '/rentals/payment') === null && formFields($get('fleet_manager', $detailPath)['body'], '/rentals/payment') === null, 'front desk and the fleet manager are not offered it');
$tooMuch = submit($as('finance_staff'), $financeDetail['body'], '/rentals/payment', ['agreement_id' => (string) $agreementId, 'method' => 'cash', 'amount' => number_format((float) $owed[1] + 1, 2, '.', ''), 'reference' => '']);
check((int) $db->query("SELECT COUNT(*) FROM payments WHERE agreement_id={$agreementId} AND purpose='balance'")->fetchColumn() === 0, 'more than what is owed cannot be recorded');
$cashPart = submit($as('finance_staff'), $financeDetail['body'], '/rentals/payment', ['agreement_id' => (string) $agreementId, 'method' => 'cash', 'amount' => '1000.00', 'reference' => '']);
$cashRow = $db->query("SELECT method, amount, external_reference, recorded_by FROM payments WHERE agreement_id={$agreementId} AND purpose='balance'")->fetch() ?: [];
check($cashPart['status'] === 303 && ($cashRow['method'] ?? '') === 'cash' && $cashRow['amount'] === '1000.00' && $cashRow['external_reference'] === null && (int) $cashRow['recorded_by'] === $accounts['finance_staff']['id'], 'finance records part of the balance in cash, with no reference number', 'HTTP ' . $cashPart['status']);
$financeDetail = $get('finance_staff', $detailPath);
$cardApproval = 'APPR' . strtoupper($tag);
$cardRest = submit($as('finance_staff'), $financeDetail['body'], '/rentals/payment', ['agreement_id' => (string) $agreementId, 'method' => 'card', 'amount' => number_format((float) $owed[1] - 1000, 2, '.', ''), 'reference' => $cardApproval]);
$financeDetail = $get('finance_staff', $detailPath);
check($cardRest['status'] === 303 && (string) $db->query("SELECT method FROM payments WHERE external_reference='{$cardApproval}'")->fetchColumn() === 'card' && str_contains(mainOf($financeDetail['body']), 'Paid in full') && formFields($financeDetail['body'], '/rentals/payment') === null, 'the rest is recorded by card with its approval code, and the agreement shows as paid in full', 'HTTP ' . $cardRest['status']);
$complete = submit($as('finance_staff'), $financeDetail['body'], '/rentals/action', [], ['action' => 'complete', 'agreement_id' => (string) $agreementId], 'complete');
check((string) $db->query('SELECT status FROM rental_agreements WHERE agreement_id=' . $agreementId)->fetchColumn() === 'completed', 'finance completes the agreement', 'HTTP ' . $complete['status']);
check((string) $db->query('SELECT current_status FROM vehicles WHERE vehicle_id=' . $vehicleId)->fetchColumn() === 'available', 'the vehicle is available again');
$closedDetail = $get('finance_staff', $detailPath);
check($closedDetail['status'] === 200 && formFields(mainOf($closedDetail['body']), '/rentals/action') === null, 'the completed agreement offers no further lifecycle action');
// Rental and deposit history share one table with vehicle and driver history.
// Each page must show its own kind only, and all of it.
$sectionOf = static fn (string $html, string $id): string => preg_match('/<section\b[^>]*id="' . preg_quote($id, '/') . '".*?<\/section>/s', $html, $section) === 1 ? $section[0] : '';
$logCount = static function (string $subject, string $column, int $id) use ($db): int {
    $count = $db->prepare("SELECT COUNT(*) FROM status_logs WHERE subject = :subject AND {$column} = :id");
    $count->execute(['subject' => $subject, 'id' => $id]);
    return (int) $count->fetchColumn();
};
$rentalSteps = $logCount('rental', 'agreement_id', $agreementId);
$depositSteps = $logCount('deposit', 'agreement_id', $agreementId);
check($rentalSteps >= 5 && substr_count($sectionOf($closedDetail['body'], 'history'), 'timeline-title') === $rentalSteps, 'the agreement page lists every rental status change and nothing else', "{$rentalSteps} recorded");
check($depositSteps >= 2 && substr_count($sectionOf($closedDetail['body'], 'deposit'), '<td class="nowrap">') === $depositSteps, 'the deposit panel lists every deposit change and nothing else', "{$depositSteps} recorded");
$vehicleSteps = $logCount('vehicle', 'vehicle_id', $vehicleId);
$vehicleHistory = $get('fleet_manager', '/fleet/vehicles/detail?vehicle_id=' . $vehicleId)['body'];
check($vehicleSteps >= 3 && preg_match('/<h2 id="status-title">Status history<\/h2>.*?<tbody>(.*?)<\/tbody>/s', $vehicleHistory, $vehicleRows) === 1 && substr_count($vehicleRows[1], '<tr>') === $vehicleSteps, 'the vehicle page lists every status change of that vehicle and nothing else', "{$vehicleSteps} recorded");
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
section('Vehicle status: in maintenance (fleet manager)');
// The Maintenance module is gone; a vehicle is taken out for repairs by changing its status by hand.
$statusOf = static fn (int $id): string => (string) $db->query('SELECT current_status FROM vehicles WHERE vehicle_id=' . $id)->fetchColumn();
$offeredForBooking = static fn (int $id): bool => str_contains($get('front_desk', '/rentals/new')['body'], 'value="' . $id . '" data-rate');
$statusPath = '/fleet/vehicles/detail?vehicle_id=' . $serviceVehicleId;
check($offeredForBooking($serviceVehicleId), 'an available vehicle is offered for booking');
$statusStepsBefore = $logCount('vehicle', 'vehicle_id', $serviceVehicleId);
$toMaintenance = submit($as('fleet_manager'), $get('fleet_manager', $statusPath)['body'], '/fleet/vehicles/status', ['vehicle_id' => (string) $serviceVehicleId, 'status' => 'maintenance']);
check($toMaintenance['status'] === 303 && $statusOf($serviceVehicleId) === 'maintenance', 'fleet manager marks a vehicle as in maintenance from its page', 'HTTP ' . $toMaintenance['status']);
check(!$offeredForBooking($serviceVehicleId), 'a vehicle in maintenance is not offered for booking');
$backAvailable = submit($as('fleet_manager'), $get('fleet_manager', $statusPath)['body'], '/fleet/vehicles/status', ['vehicle_id' => (string) $serviceVehicleId, 'status' => 'available']);
check($backAvailable['status'] === 303 && $statusOf($serviceVehicleId) === 'available' && $offeredForBooking($serviceVehicleId), 'fleet manager makes it available again, and it is offered for booking', 'HTTP ' . $backAvailable['status']);
check($logCount('vehicle', 'vehicle_id', $serviceVehicleId) === $statusStepsBefore + 2, 'both status changes are kept in the vehicle history');
check($get('system_admin', '/maintenance')['status'] === 404 && $get('system_admin', '/maintenance/due')['status'] === 404, 'the Maintenance pages no longer exist');
// Vehicle and damage photos share one table. Each address must serve its own kind only,
// because each kind is open to different roles.
$photoRoutes = ['vehicle' => '/fleet/vehicles/photos/show?photo_id=', 'damage' => '/rentals/damage/photo?photo_id='];
$photoIds = ['vehicle' => (int) ($photoMatch[1] ?? 0), 'damage' => (int) ($damagePhoto[1] ?? 0)];
check(!in_array(0, $photoIds, true) && count(array_unique($photoIds)) === 2, 'a vehicle photo and a damage photo each have their own number');
foreach ($photoRoutes as $routeKind => $route) {
    foreach ($photoIds as $photoKind => $photoId) {
        $status = $get('system_admin', $route . $photoId)['status'];
        $expected = $routeKind === $photoKind ? 200 : 404;
        check($status === $expected, "the {$routeKind} photo address " . ($expected === 200 ? 'serves' : 'does not serve') . " a {$photoKind} photo", "expected {$expected}, got {$status}");
    }
}

/* ---------------------------------------------------------------------- */
section('Online booking (customer, no account)');
$db->prepare("INSERT INTO vehicles (plate_number, make, model, model_year, color, body_type, transmission, fuel_type, seating_capacity, daily_rate, current_status) VALUES (:plate, 'Honda', :model, 2024, 'Silver', 'sedan', 'automatic', 'gasoline', 5, '2500.00', 'available')")->execute(['plate' => 'ON' . strtoupper($tag), 'model' => "Online {$tag}"]);
$onlineVehicleId = (int) $db->lastInsertId();
$manilaZone = new DateTimeZone('Asia/Manila');
$onlineStart = (new DateTimeImmutable('+20 days', $manilaZone))->format('Y-m-d');
$onlineEnd = (new DateTimeImmutable('+23 days', $manilaZone))->format('Y-m-d');
$onlineDates = "/book?start_date={$onlineStart}&end_date={$onlineEnd}";
$refusedSql = static function (string $sql, string $needle, string $label) use ($db): void {
    try {
        $db->exec($sql);
        check(false, $label, 'the statement was accepted');
    } catch (PDOException $error) {
        check(str_contains(strtolower($error->getMessage()), strtolower($needle)), $label, $error->getMessage());
    }
};
$queuedText = static function (string $idempotencyKey) use ($db): string {
    $q = $db->prepare('SELECT recipient_phone, template_key, rendered_message FROM notifications WHERE idempotency_key = :key');
    $q->execute(['key' => $idempotencyKey]);
    $row = $q->fetch();
    return $row ? (new SmsMessageCipher())->decrypt((string) $row['rendered_message'], SmsMessageCipher::context((string) $row['recipient_phone'], (string) $row['template_key'])) : '';
};

$shopper = client();
$bookPage = request($shopper, 'GET', '/book');
check($bookPage['status'] === 200 && str_contains($bookPage['body'], 'name="start_date"') && !str_contains($bookPage['body'], 'name="vehicle_id"'), 'the booking page opens without signing in and asks for the dates first');
check(str_contains(request($shopper, 'GET', '/')['body'], 'href="/book"'), 'the landing page links to it');
check(str_contains(request($shopper, 'GET', '/book?start_date=2020-01-01&end_date=2020-01-03')['body'], 'cannot be in the past'), 'a past date is refused with a plain message');
check(str_contains(request($shopper, 'GET', "/book?start_date={$onlineStart}&end_date={$onlineStart}")['body'], 'must be after the pickup date'), 'the return date must be after the pickup date');
$choices = request($shopper, 'GET', $onlineDates);
check($choices['status'] === 200 && str_contains($choices['body'], "Online {$tag}") && str_contains($choices['body'], '₱7,500.00') && str_contains($choices['body'], '₱2,250.00') && str_contains($choices['body'], '₱5,250.00'), 'a free vehicle is offered with its total for 3 days, the 30% downpayment and the balance');
check(str_contains($choices['body'], 'Downpayment policy') && str_contains($choices['body'], 'non-refundable') && preg_match('/name="policy_version_id" value="(\d+)"/', $choices['body'], $policyVersion) === 1, 'the downpayment policy and its version are shown with the booking form');
$shopperPhone = '0919' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
$bookingFields = ['start_date' => $onlineStart, 'end_date' => $onlineEnd, 'policy_version_id' => $policyVersion[1] ?? '0', 'vehicle_id' => (string) $onlineVehicleId, 'full_name' => "Online Shopper {$tag}", 'phone' => $shopperPhone, 'email' => '', 'pickup_time' => '10:00'];
// The dates form and the booking form share the address /book; the hidden policy field picks the booking form.
$bookingForm = ['policy_version_id' => $bookingFields['policy_version_id']];
$noConsent = submit($shopper, $choices['body'], '/book', array_diff_key($bookingFields, $bookingForm), $bookingForm);
check($noConsent['status'] === 422 && str_contains($noConsent['body'], 'accept the downpayment policy') && (int) $db->query("SELECT COUNT(*) FROM rental_agreements WHERE vehicle_id={$onlineVehicleId}")->fetchColumn() === 0, 'without accepting the policy nothing is booked');
// Pickup times on the day itself: a time that has passed, or is less than two hours away, is never offered and is refused if sent anyway.
$clock = static fn (string $time): DateTimeImmutable => new DateTimeImmutable('2026-10-02 ' . $time, $manilaZone);
check(OnlineBookingService::timesFor('2026-10-02', $clock('21:55')) === [], 'at 9:55 PM no pickup time is left for the same day');
check((OnlineBookingService::timesFor('2026-10-02', $clock('08:10'))[0] ?? '') === '10:30', 'at 8:10 AM the first pickup offered for the same day is 10:30 AM');
check(count(OnlineBookingService::timesFor('2026-10-03', $clock('21:55'))) === 31 && count(OnlineBookingService::timesFor(null, $clock('21:55'))) === 31, 'every pickup time is offered for a later date');
$todayManila = (new DateTimeImmutable('today', $manilaZone))->format('Y-m-d');
$tomorrowManila = (new DateTimeImmutable('tomorrow', $manilaZone))->format('Y-m-d');
$timesLeft = OnlineBookingService::timesFor($todayManila, new DateTimeImmutable('now', $manilaZone));
$sameDay = request($shopper, 'GET', "/book?start_date={$todayManila}&end_date={$tomorrowManila}")['body'];
if ($timesLeft === []) {
    check(str_contains($sameDay, 'too late to book a pickup for today') && !str_contains($sameDay, 'name="vehicle_id"') && str_contains($sameDay, 'min="' . $tomorrowManila . '"'), 'late in the evening a pickup today is refused and the calendar starts tomorrow');
} else {
    preg_match_all('/<option value="(\d\d:\d\d)"/', $sameDay, $offered);
    check($offered[1] === $timesLeft, 'for a pickup today only the times still ahead are offered', 'offered: ' . implode(' ', $offered[1]));
}
if (count($timesLeft) < 31) {
    $tooSoon = submit($shopper, $choices['body'], '/book', ['start_date' => $todayManila, 'end_date' => $tomorrowManila, 'pickup_time' => '06:00', 'accept_policy' => '1'] + array_diff_key($bookingFields, $bookingForm), $bookingForm, 'booking a pickup time that has passed');
    check($tooSoon['status'] === 422 && (str_contains($tooSoon['body'], 'too soon') || str_contains($tooSoon['body'], 'too late')) && (int) $db->query("SELECT COUNT(*) FROM rental_agreements WHERE vehicle_id={$onlineVehicleId}")->fetchColumn() === 0, 'a pickup time that has already passed today is refused and nothing is booked', 'HTTP ' . $tooSoon['status']);
}
$booked = submit($shopper, $choices['body'], '/book', array_diff_key($bookingFields, $bookingForm) + ['accept_policy' => '1'], $bookingForm);
check($booked['status'] === 303 && ($booked['headers']['location'] ?? '') === '/customer/booking', 'accepting the policy and sending the form makes the booking', 'HTTP ' . $booked['status'] . ' ' . substr(strip_tags($booked['body']), 0, 200));
$online = $db->query("SELECT r.*, c.customer_type FROM rental_agreements r JOIN customers c ON c.customer_id=r.customer_id WHERE r.vehicle_id={$onlineVehicleId}")->fetch() ?: [];
$onlineId = (int) ($online['agreement_id'] ?? 0);
$onlineRef = (string) ($online['booking_reference'] ?? '');
$onlinePath = '/rentals/detail?agreement_id=' . $onlineId;
check(($online['booking_source'] ?? '') === 'online' && $online['status'] === 'reserved' && $online['rental_type'] === 'self_drive' && $online['downpayment_status'] === 'due' && $online['downpayment_amount'] === '2250.00' && $online['customer_type'] === 'online' && preg_match('/^[A-Z2-9]{8}$/', $onlineRef) === 1, 'it is stored as an online self-drive reservation with its reference and a ₱2,250 downpayment due');
$acceptance = $db->query("SELECT a.action, a.ip_address, v.rules_key, v.version_number FROM rules_acceptances a JOIN rules_versions v ON v.rules_version_id=a.rules_version_id WHERE a.agreement_id={$onlineId}")->fetch() ?: [];
check(($acceptance['action'] ?? '') === 'accepted' && $acceptance['rules_key'] === 'downpayment_policy' && (int) $acceptance['version_number'] === 2 && $acceptance['ip_address'] !== null, 'the acceptance of the current policy (version 2) is recorded against the booking, with the address it came from');
check(str_contains($queuedText('magic-link:' . (int) $db->query("SELECT MAX(id) FROM booking_access_tokens WHERE booking_id={$onlineId}")->fetchColumn()), 'Booking reference: ' . $onlineRef), 'the booking link message quotes the booking reference');
$mine = request($shopper, 'GET', '/customer/booking');
check($mine['status'] === 200 && str_contains($mine['body'], $onlineRef) && str_contains($mine['body'], '₱2,250.00') && str_contains($mine['body'], '₱5,250.00') && formFields($mine['body'], '/customer/booking/proof') !== null, 'the customer lands on their booking page: reference, what to pay, and the form for the proof');
check(formFields($mine['body'], '/customer/booking/pay') !== null && str_contains($mine['body'], 'Or pay in cash at the office') && !str_contains($mine['body'], 'name="accept_policy"'), 'the page also offers paying online and paying cash at the office, without asking again for the policy they accepted when booking');
check(str_contains($mine['body'], 'You accepted the Downpayment policy (version 2)'), 'the page shows which policy version they accepted');
check(!str_contains($mine['body'], 'app-sidebar') && request($shopper, 'GET', '/rentals')['status'] === 303, 'a customer who booked online has no access to staff pages');
$stranger = client();
check(str_contains(request($stranger, 'GET', '/customer/booking')['body'], 'No booking is open'), 'another browser sees no booking');
$findPage = request($stranger, 'GET', '/book/find');
$wrongPhone = submit($stranger, $findPage['body'], '/book/find', ['reference' => $onlineRef, 'phone' => '09170000000']);
check($wrongPhone['status'] === 422 && str_contains($wrongPhone['body'], 'find a booking with that reference') && str_contains(request($stranger, 'GET', '/customer/booking')['body'], 'No booking is open'), 'the reference with someone else’s mobile number opens nothing');
$found = submit($stranger, $findPage['body'], '/book/find', ['reference' => strtolower($onlineRef), 'phone' => $shopperPhone]);
check($found['status'] === 303 && str_contains(request($stranger, 'GET', '/customer/booking')['body'], $onlineRef), 'the reference with the right mobile number opens the booking from any browser');
check(!str_contains(request(client(), 'GET', $onlineDates)['body'], "Online {$tag}"), 'the reserved vehicle is no longer offered for those dates');
$rival = client();
$rivalToken = csrfFrom(request($rival, 'GET', '/book/find')['body']);
$taken = request($rival, 'POST', '/book', ['_csrf' => $rivalToken, 'accept_policy' => '1', 'full_name' => 'Second Shopper', 'phone' => '0918' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT)] + $bookingFields);
check($taken['status'] === 422 && str_contains($taken['body'], 'just booked for those dates') && (int) $db->query("SELECT COUNT(*) FROM customers WHERE full_name='Second Shopper'")->fetchColumn() === 0, 'a second customer cannot book the same vehicle for those dates, and no customer record is left behind');
$laterStart = (new DateTimeImmutable('+40 days', $manilaZone))->format('Y-m-d');
$laterEnd = (new DateTimeImmutable('+41 days', $manilaZone))->format('Y-m-d');
$greedy = request($shopper, 'POST', '/book', ['_csrf' => csrfFrom($mine['body']), 'accept_policy' => '1', 'start_date' => $laterStart, 'end_date' => $laterEnd] + $bookingFields);
check($greedy['status'] === 422 && str_contains($greedy['body'], 'already has a reservation waiting'), 'one mobile number cannot hold a second unpaid reservation');

$notImagePath = tempnam(sys_get_temp_dir(), 'notimage');
file_put_contents($notImagePath, 'this is not a picture');
submit($shopper, $mine['body'], '/customer/booking/proof', ['reference' => 'GCX' . strtoupper($tag)], [], 'proof upload', ['screenshot' => new CURLFile($notImagePath, 'image/png', 'receipt.png')]);
$afterBadFile = request($shopper, 'GET', '/customer/booking');
check(str_contains($afterBadFile['body'], 'Only JPEG, PNG, and WebP') && (int) $db->query("SELECT COUNT(*) FROM payment_proofs WHERE agreement_id={$onlineId}")->fetchColumn() === 0, 'a file that is not a picture is refused and nothing is stored');
@unlink($notImagePath);
$firstReference = 'GCP' . strtoupper($tag) . '01';
$sent = submit($shopper, $afterBadFile['body'], '/customer/booking/proof', ['reference' => $firstReference], [], 'proof upload', ['screenshot' => new CURLFile($png, 'image/png', 'receipt.png')]);
$firstProof = $db->query("SELECT * FROM payment_proofs WHERE agreement_id={$onlineId} ORDER BY proof_id DESC LIMIT 1")->fetch() ?: [];
$firstProofId = (int) ($firstProof['proof_id'] ?? 0);
check($sent['status'] === 303 && ($firstProof['proof_status'] ?? '') === 'submitted' && $firstProof['reference_number'] === $firstReference && str_starts_with((string) $firstProof['storage_path'], 'payments/'), 'the customer sends the GCash reference and a screenshot, stored privately');
$waitingPage = request($shopper, 'GET', '/customer/booking');
check(str_contains($waitingPage['body'], 'is being checked') && formFields($waitingPage['body'], '/customer/booking/proof') === null, 'their page says the proof is being checked and offers no second upload');
check(str_contains(request($guestOnly = client(), 'GET', '/payments/proof?proof_id=' . $firstProofId)['headers']['location'] ?? '', '/staff/login'), 'the screenshot is not open to visitors');
$db->exec("UPDATE rental_agreements SET hold_expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE) WHERE agreement_id={$onlineId}");
shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/rentals-expire.php') . ' 2>&1');
check((string) $db->query("SELECT status FROM rental_agreements WHERE agreement_id={$onlineId}")->fetchColumn() === 'reserved', 'the reservation is not cancelled at the end of its hold while a proof is waiting');

$queue = $get('finance_staff', '/payments');
check($queue['status'] === 200 && str_contains(mainOf($queue['body']), "Online Shopper {$tag}") && str_contains($queue['body'], $firstReference) && formFields($queue['body'], '/payments/verify', ['proof_id' => $firstProofId]) !== null, 'finance sees the proof under Payments to check, with Verify and Reject');
check(str_contains(mainOf($get('finance_staff', '/staff')['body']), 'Payments to check'), 'the finance workspace flags it under Needs attention');
$screenshot = $get('finance_staff', '/payments/proof?proof_id=' . $firstProofId);
check($screenshot['status'] === 200 && ($screenshot['headers']['content-type'] ?? '') === 'image/png', 'finance can open the screenshot', 'HTTP ' . $screenshot['status']);
check($get('front_desk', '/payments/proof?proof_id=' . $firstProofId)['status'] === 403, 'front desk cannot open the screenshot');
$frontOnline = mainOf($get('front_desk', $onlinePath)['body']);
check(str_contains($frontOnline, 'Booked online') && str_contains($frontOnline, $onlineRef) && str_contains($frontOnline, 'sent proof of the downpayment') && str_contains($frontOnline, 'Waiting for finance') && formFields($frontOnline, '/payments/verify') === null, 'front desk sees an online booking whose proof is waiting for finance, with no decision controls');
$rejectReason = "Receipt shows 225 pesos, not 2,250 ({$tag}).";
$rejected = submit($as('finance_staff'), $queue['body'], '/payments/reject', ['reason' => $rejectReason], ['proof_id' => $firstProofId], 'reject proof');
$firstProof = $db->query("SELECT proof_status, review_note, reviewed_by FROM payment_proofs WHERE proof_id={$firstProofId}")->fetch();
check($rejected['status'] === 303 && $firstProof['proof_status'] === 'rejected' && $firstProof['review_note'] === $rejectReason && (int) $firstProof['reviewed_by'] === $accounts['finance_staff']['id'], 'finance rejects the proof with a reason');
check(str_contains($queuedText('proof-rejected-' . $firstProofId), $rejectReason), 'the customer is sent the reason');
check((string) $db->query("SELECT downpayment_status FROM rental_agreements WHERE agreement_id={$onlineId}")->fetchColumn() === 'due', 'a rejected proof leaves the downpayment due');
$db->exec("UPDATE rental_agreements SET hold_expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 2 HOUR) WHERE agreement_id={$onlineId}");
$retryPage = request($shopper, 'GET', '/customer/booking');
check(str_contains($retryPage['body'], 'Your last proof was not accepted') && str_contains($retryPage['body'], $rejectReason) && formFields($retryPage['body'], '/customer/booking/proof') !== null, 'the customer sees why and can send another proof');
$secondReference = 'GCP' . strtoupper($tag) . '02';
submit($shopper, $retryPage['body'], '/customer/booking/proof', ['reference' => $secondReference], [], 'second proof upload', ['screenshot' => new CURLFile($png, 'image/png', 'receipt.png')]);
$secondProofId = (int) $db->query("SELECT proof_id FROM payment_proofs WHERE agreement_id={$onlineId} AND proof_status='submitted'")->fetchColumn();
check($secondProofId > $firstProofId, 'the second proof is waiting for a decision');
$refusedSql("INSERT INTO payment_proofs (agreement_id, reference_number, storage_path, original_filename, mime, size_bytes) VALUES ({$onlineId}, 'THIRD-{$tag}', 'payments/x-{$tag}.png', 'x.png', 'image/png', 10)", 'duplicate', 'the database allows one waiting proof per booking');
$db->exec("UPDATE rental_agreements SET hold_expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 MINUTE) WHERE agreement_id={$onlineId}");
$financeOnline = $get('finance_staff', $onlinePath);
$verified = submit($as('finance_staff'), $financeOnline['body'], '/payments/verify', [], ['proof_id' => $secondProofId, 'return' => 'agreement'], 'verify proof from the agreement page');
$online = $db->query("SELECT r.status, r.downpayment_status, p.external_reference, p.recorded_by, p.proof_id, p.method FROM rental_agreements r LEFT JOIN payments p ON p.paid_downpayment_agreement_id = r.agreement_id WHERE r.agreement_id={$onlineId}")->fetch();
check($verified['status'] === 303 && str_ends_with((string) ($verified['headers']['location'] ?? ''), '#downpayment') && $online['downpayment_status'] === 'received' && $online['external_reference'] === $secondReference && $online['method'] === 'gcash' && (int) $online['proof_id'] === $secondProofId && (int) $online['recorded_by'] === $accounts['finance_staff']['id'] && $online['status'] === 'reserved', 'finance verifies the second proof, even though the hold time has passed: the downpayment is recorded as a GCash payment tied to that proof');
check((string) $db->query("SELECT proof_status FROM payment_proofs WHERE proof_id={$secondProofId}")->fetchColumn() === 'verified' && !str_contains(mainOf($get('finance_staff', '/payments')['body']), $secondReference . '</td><td><a href="/payments/proof'), 'the proof is marked verified and leaves the waiting list');
$refusedSql("UPDATE payment_proofs SET review_note='changed' WHERE proof_id={$firstProofId}", 'cannot be changed', 'a decided proof cannot be edited');
$refusedSql("UPDATE rules_versions SET body='changed' WHERE rules_key='downpayment_policy'", 'append-only', 'published policy text cannot be edited');
$refusedSql("INSERT INTO rules_acceptances (phone, action, agreement_id, recorded_at) VALUES ('+639170000000', 'accepted', {$onlineId}, UTC_TIMESTAMP(6))", 'chk_rules_acceptance_kind', 'an acceptance must name the policy version accepted');
$frontOnlinePage = $get('front_desk', $onlinePath);
$onlineConfirmed = submit($as('front_desk'), $frontOnlinePage['body'], '/rentals/action', [], ['action' => 'confirm', 'agreement_id' => (string) $onlineId], 'confirm the online booking');
check((string) $db->query("SELECT status FROM rental_agreements WHERE agreement_id={$onlineId}")->fetchColumn() === 'confirmed', 'front desk confirms the paid online booking');
check(str_contains($queuedText("rental-{$onlineId}-confirmed"), 'Downpayment received: ₱2,250. Balance of ₱5,250 is due at pickup.'), 'the customer is told the downpayment received and the balance due at pickup');
$donePage = request($shopper, 'GET', '/customer/booking');
check(str_contains($donePage['body'], 'Confirmed') && str_contains($donePage['body'], 'was received') && formFields($donePage['body'], '/customer/booking/proof') === null, 'the customer’s page shows the booking confirmed and the downpayment received');
$qrPage = $get('front_desk', '/staff/booking-qr');
check($qrPage['status'] === 200 && preg_match('/data-qr="[^"]+\/book"/', $qrPage['body']) === 1 && str_contains($qrPage['body'], 'qr-render.js'), 'staff can open a QR code that leads to the same booking page');

/* ---------------------------------------------------------------------- */
section('Paying online: the simulated checkout (customer, no account)');
// Needs PAYMENT_GATEWAY=simulated on the server. No real money moves: the checkout shows each outcome.
$db->prepare("INSERT INTO vehicles (plate_number, make, model, model_year, color, body_type, transmission, fuel_type, seating_capacity, daily_rate, current_status) VALUES (:plate, 'Toyota', :model, 2024, 'Black', 'sedan', 'automatic', 'gasoline', 5, '3000.00', 'available')")->execute(['plate' => 'PY' . strtoupper($tag), 'model' => "Checkout {$tag}"]);
$payVehicleId = (int) $db->lastInsertId();
$payStart = (new DateTimeImmutable('+30 days', $manilaZone))->format('Y-m-d');
$payEnd = (new DateTimeImmutable('+32 days', $manilaZone))->format('Y-m-d');
$payer = client();
$payChoices = request($payer, 'GET', "/book?start_date={$payStart}&end_date={$payEnd}");
preg_match('/name="policy_version_id" value="(\d+)"/', $payChoices['body'], $payPolicy);
$payForm = ['policy_version_id' => $payPolicy[1] ?? '0'];
$payBooked = submit($payer, $payChoices['body'], '/book', ['start_date' => $payStart, 'end_date' => $payEnd, 'vehicle_id' => (string) $payVehicleId, 'full_name' => "Online Payer {$tag}", 'phone' => '0920' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT), 'email' => '', 'pickup_time' => '09:00', 'accept_policy' => '1'], $payForm, 'booking to pay online');
$payId = (int) $db->query("SELECT agreement_id FROM rental_agreements WHERE vehicle_id={$payVehicleId}")->fetchColumn();
$payPath = '/rentals/detail?agreement_id=' . $payId;
$payState = static fn (): array => $db->query("SELECT status, downpayment_status FROM rental_agreements WHERE agreement_id={$payId}")->fetch();
$lastPayment = static fn (): array => $db->query("SELECT * FROM payments WHERE agreement_id={$payId} ORDER BY payment_id DESC LIMIT 1")->fetch() ?: [];
$payPage = request($payer, 'GET', '/customer/booking');
preg_match_all('/name="method" value="([a-z_]+)"/', $payPage['body'], $offered);
check($payBooked['status'] === 303 && $payId > 0 && $offered[1] === ['gcash', 'maya', 'grabpay', 'card', 'online_banking'] && str_contains($payPage['body'], 'no real money is taken'), 'the booking page offers GCash, Maya, GrabPay, card and online banking, never cash, and says the checkout is a demonstration');

// What if the wallet has too little money.
$started = submit($payer, $payPage['body'], '/customer/booking/pay', ['method' => 'maya']);
$checkoutPath = (string) ($started['headers']['location'] ?? '');
$walletReceipt = substr($checkoutPath, -12);
$checkout = request($payer, 'GET', $checkoutPath);
check($started['status'] === 303 && preg_match('#^/pay/demo\?receipt=TR[A-Z2-9]{10}$#', $checkoutPath) === 1 && $checkout['status'] === 200 && str_contains($checkout['body'], 'Demonstration checkout') && str_contains($checkout['body'], 'No real money is moved') && str_contains($checkout['body'], '₱1,800.00') && str_contains($checkout['body'], 'Maya'), 'choosing Maya opens the demonstration checkout for the booking\'s own downpayment, ₱1,800', 'HTTP ' . $started['status'] . ' ' . $checkoutPath);
check(!str_contains($checkout['body'], 'type="password"') && preg_match('/name="[^"]*(pin|otp|password)[^"]*"/i', $checkout['body']) === 0, 'the checkout asks for no PIN, one-time code or password');
check((request(client(), 'GET', $checkoutPath)['headers']['location'] ?? '') === '/book/find', 'the checkout is not open to another browser');
check(submit($payer, $payPage['body'], '/customer/booking/pay', ['method' => 'gcash'])['headers']['location'] === $checkoutPath && (int) $db->query("SELECT COUNT(*) FROM payments WHERE agreement_id={$payId}")->fetchColumn() === 1, 'choosing again returns to the checkout already open instead of starting a second payment');
$inProgressPage = request($payer, 'GET', '/customer/booking');
check(str_contains($inProgressPage['body'], 'You have a payment in progress') && formFields($inProgressPage['body'], '/customer/booking/proof') === null, 'the booking page says a payment is in progress and offers no other way to pay meanwhile');
$financePaying = $get('finance_staff', $payPath);
check(str_contains(mainOf($financePaying['body']), 'paying the downpayment of ₱1,800.00 online right now') && formFields($financePaying['body'], '/rentals/downpayment') === null, 'staff see that the customer is on the checkout, and finance is not offered the counter form meanwhile');
$forgedBody = json_encode(['receipt' => $walletReceipt, 'result' => 'paid', 'amount' => '1800.00', 'reference' => 'FORGED-' . $tag, 'detail' => null, 'reason' => null]);
$forged = request(client(), 'POST', '/webhooks/payments', $forgedBody, ['Content-Type: application/json', 'X-Payment-Signature: ' . str_repeat('a', 64)]);
check($forged['status'] === 200 && ($lastPayment()['payment_status'] ?? '') === 'pending' && $payState()['downpayment_status'] === 'due' && (int) $db->query("SELECT COUNT(*) FROM security_logs WHERE event_type='payment.result_rejected'")->fetchColumn() >= 1, 'a forged "paid" result sent to the webhook pays nothing and is written to the security log');
$short = submit($payer, $checkout['body'], '/pay/demo', ['outcome' => 'insufficient'], ['receipt' => $walletReceipt], 'checkout: not enough balance');
$shortPage = request($payer, 'GET', (string) ($short['headers']['location'] ?? '/'));
check($short['status'] === 303 && str_contains($shortPage['body'], 'Payment not completed') && str_contains($shortPage['body'], 'Not enough balance') && str_contains($shortPage['body'], 'Nothing was charged') && ($lastPayment()['payment_status'] ?? '') === 'failed' && $payState()['downpayment_status'] === 'due', 'not enough balance: the customer is told why, nothing is charged, and the downpayment stays due');
$payPage = request($payer, 'GET', '/customer/booking');
check(str_contains($payPage['body'], 'Your last payment did not go through') && formFields($payPage['body'], '/customer/booking/pay') !== null, 'the booking page says the last payment did not go through and lets them try again');

// What if the card is not a test card, is declined, or is good.
$cardStart = submit($payer, $payPage['body'], '/customer/booking/pay', ['method' => 'card']);
$cardPath = (string) ($cardStart['headers']['location'] ?? '');
$cardReceipt = substr($cardPath, -12);
$cardCheckout = request($payer, 'GET', $cardPath);
$cardFields = ['card_expiry' => '12/30', 'card_cvv' => '123', 'outcome' => 'card'];
check(str_contains($cardCheckout['body'], 'Test cards') && str_contains($cardCheckout['body'], '4242 4242 4242 4242'), 'the card checkout lists the test cards it accepts');
$realLooking = submit($payer, $cardCheckout['body'], '/pay/demo', ['card_number' => '4111 1111 1111 1111'] + $cardFields, ['receipt' => $cardReceipt], 'checkout: a card that is not a test card');
check(($realLooking['headers']['location'] ?? '') === $cardPath && str_contains(request($payer, 'GET', $cardPath)['body'], 'Use one of the test card numbers') && ($lastPayment()['payment_status'] ?? '') === 'pending', 'a card number that is not a listed test card is refused, and the payment is still open');
$cardCheckout = request($payer, 'GET', $cardPath);
$declinedCard = submit($payer, $cardCheckout['body'], '/pay/demo', ['card_number' => '4000 0000 0000 0002'] + $cardFields, ['receipt' => $cardReceipt], 'checkout: declined card');
$declinedRow = $lastPayment();
check(str_contains(request($payer, 'GET', (string) ($declinedCard['headers']['location'] ?? '/'))['body'], 'Declined by the issuing bank') && $declinedRow['payment_status'] === 'failed' && $declinedRow['method_detail'] === 'Visa ending 0002', 'a declined card: the customer is told, and only "Visa ending 0002" is kept');
$payPage = request($payer, 'GET', '/customer/booking');
$goodStart = submit($payer, $payPage['body'], '/customer/booking/pay', ['method' => 'card']);
$goodPath = (string) ($goodStart['headers']['location'] ?? '');
$goodReceipt = substr($goodPath, -12);
$toVerify = submit($payer, request($payer, 'GET', $goodPath)['body'], '/pay/demo', ['card_number' => '4242 4242 4242 4242'] + $cardFields, ['receipt' => $goodReceipt], 'checkout: good card');
$verifyPage = request($payer, 'GET', $goodPath);
check(($toVerify['headers']['location'] ?? '') === $goodPath && str_contains($verifyPage['body'], 'Bank verification') && str_contains($verifyPage['body'], 'Visa ending 4242') && !str_contains($verifyPage['body'], '4242 4242') && ($lastPayment()['payment_status'] ?? '') === 'pending', 'a good card goes to the bank verification step, which shows only its last four digits');
$approved = submit($payer, $verifyPage['body'], '/pay/demo', ['outcome' => 'approved'], ['receipt' => $goodReceipt], 'checkout: verification passed');
$receiptPath = (string) ($approved['headers']['location'] ?? '/');
$paidPage = request($payer, 'GET', $receiptPath);
$paidRow = $lastPayment();
check($approved['status'] === 303 && $paidRow['payment_status'] === 'paid' && $paidRow['channel'] === 'online_demo' && $paidRow['method_detail'] === 'Visa ending 4242' && str_starts_with((string) $paidRow['external_reference'], 'DEMO-') && $paidRow['recorded_by'] === null && $payState()['downpayment_status'] === 'received', 'the payment is paid and the downpayment is received, with no staff member involved');
check(str_contains($paidPage['body'], 'Payment received') && str_contains($paidPage['body'], $goodReceipt) && str_contains($paidPage['body'], 'Demonstration payment') && str_contains($paidPage['body'], '₱4,200.00'), 'the customer gets a receipt: its number, that it was a demonstration, and the balance due at pickup');
// Only what the checkout wrote: references typed by staff in other suites are random and can be all digits.
check((int) $db->query("SELECT COUNT(*) FROM payments WHERE channel = 'online_demo' AND CONCAT_WS('|', method_detail, external_reference, failure_reason) REGEXP '[0-9]{13,}'")->fetchColumn() === 0, 'no full card number is stored');
request($payer, 'GET', $receiptPath);
$replay = request($payer, 'POST', '/pay/demo', ['_csrf' => csrfFrom($verifyPage['body']), 'receipt' => $goodReceipt, 'outcome' => 'verification_failed']);
check(($replay['headers']['location'] ?? '') === $receiptPath && (int) $db->query("SELECT COUNT(*) FROM payments WHERE agreement_id={$payId} AND payment_status='paid'")->fetchColumn() === 1 && $lastPayment()['payment_status'] === 'paid', 'reloading the receipt or re-sending the checkout changes nothing: one paid payment');
$donePaying = request($payer, 'GET', '/customer/booking');
check(str_contains($donePaying['body'], 'was received') && str_contains($donePaying['body'], '/customer/booking/payment?receipt=' . $goodReceipt) && formFields($donePaying['body'], '/customer/booking/pay') === null && formFields($donePaying['body'], '/customer/booking/proof') === null, 'the booking page shows the downpayment received with a link to the receipt, and no way to pay twice');
check(str_contains($queuedText('payment-received-' . $paidRow['payment_id']), $goodReceipt) && str_contains($queuedText('payment-received-' . $paidRow['payment_id']), 'demonstration payment'), 'the customer is sent a message with the receipt number, saying it was a demonstration payment');

// The staff side of the same payment.
check(str_contains(mainOf($get('front_desk', '/rentals?status=reserved')['body']), 'Paid, to confirm'), 'the agreements list flags the reservation as paid and waiting to be confirmed');
$frontPaid = $get('front_desk', $payPath);
$frontPaidMain = mainOf($frontPaid['body']);
check(str_contains($frontPaidMain, 'The downpayment is in. Confirm the reservation.') && str_contains($frontPaidMain, 'Demonstration checkout') && str_contains($frontPaidMain, 'Visa ending 4242') && str_contains($frontPaidMain, 'Declined by the issuing bank') && !str_contains($frontPaidMain, '/payments/receipt'), 'front desk sees the payment and the attempts before it, with no link to the receipt page');
$financeList = $get('finance_staff', '/payments');
check(str_contains(mainOf($financeList['body']), '/payments/receipt?receipt=' . $goodReceipt) && str_contains(mainOf($financeList['body']), 'Demonstration') && str_contains(mainOf($financeList['body']), 'Not enough balance'), 'finance sees the payment, marked as a demonstration, and the failed attempts on the Payments page');
$demoReceipt = $get('finance_staff', '/payments/receipt?receipt=' . $goodReceipt);
check($demoReceipt['status'] === 200 && str_contains($demoReceipt['body'], 'Demonstration payment') && str_contains($demoReceipt['body'], 'The customer, online'), 'finance opens its receipt, which says no real money was received');
$payConfirmed = submit($as('front_desk'), $frontPaid['body'], '/rentals/action', [], ['action' => 'confirm', 'agreement_id' => (string) $payId], 'confirm the booking paid online');
check($payState()['status'] === 'confirmed' && str_contains($queuedText("rental-{$payId}-confirmed"), 'Downpayment received: ₱1,800. Balance of ₱4,200 is due at pickup.'), 'front desk confirms it, and the customer is told the downpayment received and the balance due at pickup');

/* ---------------------------------------------------------------------- */
section('Notifications and customer pages');
$api = $get('fleet_manager', '/api/staff/notifications');
$apiJson = json_decode($api['body'], true);
check($api['status'] === 200 && is_array($apiJson['notifications'] ?? null), 'fleet manager loads the notification history');
check(array_key_exists('channel', $apiJson['notifications'][0] ?? []), 'each history row says which channel it used');
check(count($apiJson['notifications'] ?? []) >= 1, 'the booking messages queued during this run appear in the history');
check($get('fleet_manager', '/staff/notifications')['status'] === 200, 'fleet manager opens the notifications page');
$guest = client();
check(request($guest, 'GET', '/')['status'] === 200, 'landing page is public');
check(request($guest, 'GET', '/magic-link')['status'] === 200 && request($guest, 'GET', '/customer/booking')['status'] === 200 && request($guest, 'GET', '/book')['status'] === 200 && request($guest, 'GET', '/book/find')['status'] === 200, 'the secure-link, customer booking, online booking and find-my-booking pages are public');
check(request($guest, 'POST', '/customer/booking/proof', ['reference' => 'GC123456'])['headers']['location'] === '/book/find', 'a proof cannot be sent without an open booking');

// Live tracking: staff make a tracker code for a confirmed rental; the phone that scans it is told which vehicle it is for.
$connectPath = '/fleet/tracking/connect?agreement_id=' . $payId;
$connectPage = $get('fleet_manager', $connectPath);
$linked = submit($as('fleet_manager'), $connectPage['body'], '/fleet/tracking/connect', ['agreement_id' => (string) $payId], [], 'make the tracker code');
check($linked['status'] === 200 && preg_match('/data-qr="[^"]+\/track#t=([A-Za-z0-9_-]{43})"/', $linked['body'], $trackerLink) === 1 && str_contains($linked['body'], 'qr-render.js'), 'fleet manager makes a tracker code for a confirmed rental, shown as a QR code', 'HTTP ' . $linked['status']);
$trackerToken = $trackerLink[1] ?? '';
$phone = client();
$trackerPage = request($phone, 'GET', '/track');
check($trackerPage['status'] === 200 && str_contains($trackerPage['body'], 'tracker-app.js') && str_contains($trackerPage['body'], 'Start sharing location') && !str_contains($trackerPage['body'], 'app-sidebar'), 'the tracker page opens on a phone without signing in');
$trackerSession = json_decode(request($phone, 'GET', '/api/tracking/session', null, ['X-Tracker-Token: ' . $trackerToken])['body'], true) ?: [];
check(($trackerSession['state'] ?? '') === 'waiting' && str_contains((string) ($trackerSession['vehicle'] ?? ''), 'PY' . strtoupper($tag)) && !isset($trackerSession['customer']), 'with the code, the phone is told which vehicle it is for and that sharing waits for the pickup, and nothing about the customer');
$early = request($phone, 'POST', '/api/tracking/report', json_encode(['latitude' => 6.1164, 'longitude' => 125.1716]), ['Content-Type: application/json', 'X-Tracker-Token: ' . $trackerToken]);
check($early['status'] === 200 && (json_decode($early['body'], true)['state'] ?? '') === 'waiting' && (int) $db->query('SELECT COUNT(*) FROM vehicle_positions')->fetchColumn() === 0, 'a position sent before the pickup is not saved');
check(request($phone, 'GET', '/api/tracking/session')['status'] === 401 && request($phone, 'POST', '/api/tracking/report', json_encode(['latitude' => 6.1, 'longitude' => 125.1]), ['Content-Type: application/json', 'X-Tracker-Token: ' . str_repeat('A', 43)])['status'] === 401, 'without a valid code the tracker is told nothing and saves nothing');
$mapPage = $get('fleet_manager', '/fleet/locations');
check($mapPage['status'] === 200 && str_contains($mapPage['body'], 'data-fleet-map=') && str_contains($mapPage['body'], 'fleet-map.js') && str_contains((string) ($mapPage['headers']['content-security-policy'] ?? ''), "img-src 'self' https://tile.openstreetmap.org") && !str_contains((string) ($get('fleet_manager', '/fleet/vehicles')['headers']['content-security-policy'] ?? ''), 'img-src'), 'the Locations page carries the live map, and it is the only page allowed to load map pictures from outside');
$feed = json_decode($get('fleet_manager', '/api/fleet/positions')['body'], true);
check(is_array($feed['vehicles'] ?? null), 'the map\'s feed answers with the list of vehicles out on rental');
check(str_contains(mainOf($get('front_desk', $payPath)['body']), $connectPath) && !str_contains(mainOf($get('finance_staff', $payPath)['body']), $connectPath), 'front desk is offered "Connect a tracker phone" on a confirmed agreement; finance is not');
check(request($guest, 'POST', '/customer/booking/pay', ['method' => 'gcash'])['headers']['location'] === '/book/find' && request($guest, 'GET', '/pay/demo?receipt=' . $goodReceipt)['headers']['location'] === '/book/find' && request($guest, 'GET', '/customer/booking/payment?receipt=' . $goodReceipt)['headers']['location'] === '/book/find', 'a payment cannot be started, a checkout opened or a receipt read without an open booking');
check(request($guest, 'GET', '/api/rentals/booking-context')['status'] === 401, 'booking details are refused without a verified secure link');
$missing = request($guest, 'GET', '/no-such-page');
check($missing['status'] === 404 && str_contains($missing['body'], 'Page not found'), 'an unknown address shows the styled 404 page');

/* ---------------------------------------------------------------------- */
section('Role matrix: who may open each page');
$editUser = '/admin/users/edit?user_id=' . $accounts['finance_staff']['id'];
$pages = [
    '/staff' => ALL_ROLES,
    '/api/staff/navigation' => ALL_ROLES,
    '/admin/users' => ['system_admin'],
    $editUser => ['system_admin'],
    '/admin/sessions?user_id=' . $accounts['finance_staff']['id'] => ['system_admin'],
    '/staff/booking-qr' => ALL_ROLES,
    '/payments' => ['system_admin', 'finance_staff'],
    '/payments/proof?proof_id=' . $firstProofId => ['system_admin', 'finance_staff'],
    '/payments/receipt?receipt=' . $downpaymentReceipt => ['system_admin', 'finance_staff'],
    '/api/fleet/positions' => ['system_admin', 'fleet_manager'],
    $connectPath => ['system_admin', 'fleet_manager', 'front_desk'],
    '/staff/notifications' => ['system_admin', 'fleet_manager'],
    '/api/staff/notifications' => ['system_admin', 'fleet_manager'],
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
    '/api/customers/telegram/status?customer_id=' . $customerId => ['system_admin', 'front_desk'],
    '/rentals' => ALL_ROLES,
    $detailPath => ALL_ROLES,
    '/rentals/new' => ['system_admin', 'front_desk'],
    '/rentals/damage/detail?report_id=' . $reportId => ['system_admin', 'front_desk', 'fleet_manager', 'finance_staff'],
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
echo "Checked " . count($pages) . " pages against 5 roles and a signed-out visitor ({$pageChecks} role checks).\n";

/* ---------------------------------------------------------------------- */
section('Role matrix: who is refused each action');
$fleet = ['system_admin', 'fleet_manager'];
$desk = ['system_admin', 'front_desk'];
$finance = ['system_admin', 'finance_staff'];
$actions = [
    '/admin/users/create' => ['system_admin'], '/admin/users/update' => ['system_admin'], '/admin/users/role' => ['system_admin'],
    '/admin/users/deactivate' => ['system_admin'], '/admin/users/reactivate' => ['system_admin'], '/admin/users/unlock' => ['system_admin'],
    '/admin/users/reset-password' => ['system_admin'], '/admin/sessions/invalidate' => ['system_admin'],
    '/fleet/vehicles/create' => $fleet, '/fleet/vehicles/update' => $fleet, '/fleet/vehicles/status' => $fleet, '/fleet/vehicles/mileage' => $fleet,
    '/fleet/vehicles/photos/upload' => $fleet, '/fleet/locations/create' => $fleet, '/fleet/locations/retire' => $fleet, '/fleet/locations/remove' => ['system_admin'],
    '/fleet/drivers/create' => $fleet, '/fleet/drivers/update' => $fleet, '/fleet/drivers/status' => $fleet, '/fleet/drivers/delete' => $fleet,
    '/fleet/drivers/contacts/add' => $fleet, '/fleet/drivers/contacts/update' => $fleet, '/fleet/drivers/contacts/remove' => $fleet, '/fleet/drivers/reveal' => ['system_admin', 'fleet_manager', 'driver_coordinator'], '/fleet/drivers/restore' => $fleet,
    '/customers/create' => $desk, '/customers/update' => $desk, '/customers/contacts/add' => $desk, '/customers/contacts/update' => $desk,
    '/customers/contacts/remove' => $desk, '/customers/documents/add' => $desk, '/customers/documents/update' => $desk, '/customers/notes/add' => $desk,
    '/customers/blacklist' => $desk, '/customers/unblacklist' => $desk, '/customers/delete' => $desk, '/customers/restore' => $desk, '/customers/reveal' => $desk,
    '/customers/telegram/code' => $desk, '/customers/telegram/disconnect' => $desk,
    '/rentals/reserve' => $desk, '/api/rentals' => $desk, '/rentals/link' => $desk,
    '/rentals/action#confirm' => $desk, '/rentals/action#cancel' => $desk, '/rentals/action#no_show' => $desk,
    '/rentals/action#pickup' => ['system_admin', 'front_desk', 'fleet_manager'], '/rentals/action#return' => ['system_admin', 'front_desk', 'fleet_manager'],
    '/rentals/action#complete' => $finance,
    '/rentals/driver/assign' => ['system_admin', 'front_desk', 'driver_coordinator'], '/rentals/driver/remove' => ['system_admin', 'front_desk', 'driver_coordinator'],
    '/rentals/charge' => $finance, '/rentals/charge/reverse' => $finance, '/rentals/deposit' => $finance, '/rentals/downpayment' => $finance, '/rentals/payment' => $finance, '/payments/verify' => $finance, '/payments/reject' => $finance,
    '/fleet/tracking/connect' => ['system_admin', 'fleet_manager', 'front_desk'], '/fleet/tracking/disconnect' => ['system_admin', 'fleet_manager', 'front_desk'],
    '/rentals/damage/report' => ['front_desk', 'fleet_manager'], '/rentals/damage/liability' => ['fleet_manager', 'system_admin'], '/rentals/damage/charge' => ['finance_staff'],
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
    'system_admin' => ['Workspace', 'Agreements', 'Payments', 'Customers', 'Vehicles', 'Locations', 'Drivers', 'Notifications', 'Staff accounts'],
    'fleet_manager' => ['Workspace', 'Agreements', 'Vehicles', 'Locations', 'Drivers', 'Notifications'],
    'front_desk' => ['Workspace', 'Agreements', 'Customers'],
    'driver_coordinator' => ['Workspace', 'Agreements', 'Drivers'],
    'finance_staff' => ['Workspace', 'Agreements', 'Payments'],
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
echo "Followed {$linkChecks} links across the five roles.\n";

@unlink($png);
echo "\n" . ($failures === 0 ? "ALL {$passes} CHECKS PASSED" : "{$failures} FAILED, {$passes} passed") . "\n";
exit($failures === 0 ? 0 : 1);
