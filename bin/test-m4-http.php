<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use TripleR\Config;
use TripleR\Database;
use TripleR\Repositories\DriverRepository;
use TripleR\Services\DriverPiiCipher;
use TripleR\Services\DriverService;

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The PHP curl extension is required for M4 HTTP acceptance.\n");
    exit(2);
}
$baseUrl = rtrim(Config::require('M4_HTTP_BASE_URL'), '/');
$password = Config::require('M4_HTTP_TEST_PASSWORD');
$db = Database::connection();
$tag = bin2hex(random_bytes(5));
$license = 'M4-HTTP-' . strtoupper($tag) . '-4321';
$driverId = (new DriverService($db, new DriverRepository($db), new DriverPiiCipher()))->create([
    'full_name' => 'M4 HTTP Driver ' . $tag,
    'license_number' => $license,
    'license_expiry' => (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d'),
], 1);

$accounts = [
    'coordinator' => ['email' => "m4-coord-$tag@example.test", 'role' => 'driver_coordinator'],
    'finance' => ['email' => "m4-finance-$tag@example.test", 'role' => 'finance_staff'],
    'manager' => ['email' => "m4-manager-$tag@example.test", 'role' => 'fleet_manager'],
    'admin' => ['email' => "m4-admin-$tag@example.test", 'role' => 'system_admin'],
];
$createUser = $db->prepare('INSERT INTO users (email,password_hash,role,is_active,must_change_password) VALUES (:email,:hash,:role,1,0)');
foreach ($accounts as $account) {
    $createUser->execute(['email' => $account['email'], 'hash' => password_hash($password, PASSWORD_DEFAULT), 'role' => $account['role']]);
}

$failures = 0;
function checkHttp(bool $passed, string $name, string $detail = ''): void
{
    global $failures;
    if ($passed) {
        echo "PASS: {$name}\n";
        return;
    }
    $failures++;
    echo "FAIL: {$name}" . ($detail === '' ? '' : " ({$detail})") . "\n";
}

function newHttpClient()
{
    $client = curl_init();
    curl_setopt_array($client, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE => '',
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'TripleR-M4-Acceptance/1.0',
    ]);
    return $client;
}

function httpRequest($client, string $method, string $path, ?array $form = null, bool $follow = false): array
{
    $headers = [];
    curl_setopt_array($client, [
        CURLOPT_URL => $GLOBALS['baseUrl'] . $path,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            $length = strlen($line);
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
            return $length;
        },
        CURLOPT_FOLLOWLOCATION => $follow,
    ]);
    if ($method === 'POST') {
        curl_setopt_array($client, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($form ?? [])]);
    } else {
        // Setting POSTFIELDS to null can leave libcurl in POST mode on a reused handle.
        curl_setopt($client, CURLOPT_HTTPGET, true);
    }
    $body = curl_exec($client);
    if (!is_string($body)) {
        throw new RuntimeException('HTTP acceptance request failed: ' . curl_error($client));
    }
    return ['status' => (int)curl_getinfo($client, CURLINFO_RESPONSE_CODE), 'body' => $body, 'headers' => $headers];
}

function loginClient(array $account)
{
    $client = newHttpClient();
    $form = httpRequest($client, 'GET', '/staff/login');
    if (!preg_match('/name="_csrf"\s+value="([^"]+)"/', $form['body'], $match)) {
        throw new RuntimeException('Could not read the login CSRF token.');
    }
    $response = httpRequest($client, 'POST', '/staff/login', [
        '_csrf' => html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        'email' => $account['email'],
        'password' => $GLOBALS['password'],
    ], true);
    checkHttp($response['status'] === 200, $account['role'] . ' login succeeds', 'HTTP ' . $response['status'] . ' ' . substr(strip_tags($response['body']), 0, 120));
    if (!preg_match('/name="_csrf"\s+value="([^"]+)"/', $response['body'], $authenticatedToken)) {
        throw new RuntimeException('Could not read the authenticated session CSRF token.');
    }
    return [$client, html_entity_decode($authenticatedToken[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')];
}

echo "Running M4 HTTP role and PII-boundary checks...\n";

$guest = newHttpClient();
$guestPage = httpRequest($guest, 'GET', '/fleet/drivers');
checkHttp($guestPage['status'] === 303, 'Unauthenticated driver page redirects to staff login', 'HTTP ' . $guestPage['status'] . ' ' . substr(strip_tags($guestPage['body']), 0, 120));
$guestReveal = httpRequest($guest, 'POST', '/fleet/drivers/reveal', ['driver_id' => $driverId, 'kind' => 'license']);
checkHttp($guestReveal['status'] === 401, 'Unauthenticated PII reveal is rejected', 'HTTP ' . $guestReveal['status']);

[$coordinator, $coordinatorCsrf] = loginClient($accounts['coordinator']);
$driverQuery = '/fleet/drivers?' . http_build_query(['search' => 'M4 HTTP Driver ' . $tag]);
$coordinatorList = httpRequest($coordinator, 'GET', $driverQuery);
checkHttp($coordinatorList['status'] === 200 && !str_contains($coordinatorList['body'], 'Restricted') && !str_contains($coordinatorList['body'], $license), 'driver_coordinator can view driver list with license masked', 'HTTP ' . $coordinatorList['status'] . ' ' . substr(strip_tags($coordinatorList['body']), 0, 120));
$coordinatorDetail = httpRequest($coordinator, 'GET', '/fleet/drivers/detail?' . http_build_query(['driver_id' => $driverId]));
checkHttp($coordinatorDetail['status'] === 200 && str_contains($coordinatorDetail['body'], 'data-reveal-kind') && !str_contains($coordinatorDetail['body'], $license), 'driver_coordinator sees PII masked on driver detail, with Reveal', 'HTTP ' . $coordinatorDetail['status'] . ' ' . substr(strip_tags($coordinatorDetail['body']), 0, 120));
$coordinatorReveal = httpRequest($coordinator, 'POST', '/fleet/drivers/reveal', ['_csrf' => $coordinatorCsrf, 'driver_id' => $driverId, 'kind' => 'license']);
$coordinatorJson = json_decode($coordinatorReveal['body'], true);
checkHttp($coordinatorReveal['status'] === 200 && ($coordinatorJson['value'] ?? null) === $license, 'driver_coordinator can reveal PII for scheduling', 'HTTP ' . $coordinatorReveal['status']);
$coordinatorNew = httpRequest($coordinator, 'GET', '/fleet/drivers/new');
checkHttp($coordinatorNew['status'] === 403, 'driver_coordinator cannot open driver mutation form', 'HTTP ' . $coordinatorNew['status'] . ' ' . substr(strip_tags($coordinatorNew['body']), 0, 120));
$coordinatorMutation = httpRequest($coordinator, 'POST', '/fleet/drivers/status', ['_csrf' => $coordinatorCsrf, 'driver_id' => $driverId, 'status' => 'inactive']);
checkHttp($coordinatorMutation['status'] === 403, 'driver_coordinator cannot mutate driver status', 'HTTP ' . $coordinatorMutation['status']);

[$finance] = loginClient($accounts['finance']);
$financePage = httpRequest($finance, 'GET', '/fleet/drivers');
checkHttp($financePage['status'] === 403, 'finance_staff cannot access driver management', 'HTTP ' . $financePage['status'] . ' ' . substr(strip_tags($financePage['body']), 0, 120));

foreach (['manager', 'admin'] as $roleKey) {
    [$manager, $csrf] = loginClient($accounts[$roleKey]);
    $detail = httpRequest($manager, 'GET', '/fleet/drivers/detail?' . http_build_query(['driver_id' => $driverId]));
    checkHttp($detail['status'] === 200 && str_contains($detail['body'], '****4321') && !str_contains($detail['body'], $license), $accounts[$roleKey]['role'] . ' detail remains masked by default', 'HTTP ' . $detail['status'] . ' ' . substr(strip_tags($detail['body']), 0, 120));
    $newForm = httpRequest($manager, 'GET', '/fleet/drivers/new');
    checkHttp($newForm['status'] === 200, $accounts[$roleKey]['role'] . ' can open driver mutation form', 'HTTP ' . $newForm['status'] . ' ' . substr(strip_tags($newForm['body']), 0, 120));
    $reveal = httpRequest($manager, 'POST', '/fleet/drivers/reveal', ['_csrf' => $csrf, 'driver_id' => $driverId, 'kind' => 'license']);
    $json = json_decode($reveal['body'], true);
    checkHttp($reveal['status'] === 200 && ($json['value'] ?? null) === $license, $accounts[$roleKey]['role'] . ' can reveal license after explicit request', 'HTTP ' . $reveal['status'] . ' ' . substr($reveal['body'], 0, 120));
    checkHttp(($reveal['headers']['cache-control'] ?? '') === 'no-store', $accounts[$roleKey]['role'] . ' reveal response is no-store');
    curl_close($manager);
}

foreach ([$guest, $coordinator, $finance] as $client) {
    curl_close($client);
}

if ($failures > 0) {
    fwrite(STDERR, "M4 HTTP checks failed: {$failures}\n");
    exit(1);
}
echo "All M4 HTTP role and PII-boundary checks passed.\n";
