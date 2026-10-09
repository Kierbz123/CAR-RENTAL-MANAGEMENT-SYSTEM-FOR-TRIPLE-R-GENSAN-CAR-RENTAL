<?php
declare(strict_types=1);

/*
 * Fills the local demonstration database with believable records for a presentation:
 * names for the test customers and drivers, a varied fleet, pickup locations, and a few
 * bookings dated around today so every staff page has something to show.
 *
 * Run it again on the day of a presentation: names and vehicles are only set once, stale demo
 * bookings are closed, and each new booking is skipped when its vehicle is already taken.
 * Customers that have contact details are never touched, so nobody is sent a message.
 * Every name and plate number here is invented.
 *
 *   php bin/demo-data.php
 */

use TripleR\Config;
use TripleR\Database;
use TripleR\Repositories\ChargeRepository;
use TripleR\Repositories\PaymentProofRepository;
use TripleR\Repositories\PaymentRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\RulesAcceptanceRepository;
use TripleR\Repositories\SecurityLogRepository;
use TripleR\Repositories\VehicleLocationRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Repositories\VehicleStatusLogRepository;
use TripleR\Services\BookingOverlapService;
use TripleR\Repositories\DriverRepository;
use TripleR\Services\ChauffeurService;
use TripleR\Services\DriverPiiCipher;
use TripleR\Services\DriverService;
use TripleR\Services\PaymentService;
use TripleR\Services\Payments\PaymentGatewayFactory;
use TripleR\Services\RateLimiter;
use TripleR\Services\RentalRuntimeFactory;
use TripleR\Services\VehicleService;
use TripleR\Support\SiteProfile;

require dirname(__DIR__) . '/app/bootstrap.php';

if (SiteProfile::get('is_demo', true) !== true || !in_array(Config::require('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
    fwrite(STDERR, "Demo data is only for the local demonstration database.\n");
    exit(1);
}

$db = Database::connection();
$say = static fn (string $line) => fwrite(STDOUT, $line . PHP_EOL);
$actor = (int) $db->query("SELECT id FROM users WHERE role = 'system_admin' AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
if ($actor === 0) {
    fwrite(STDERR, "An active system admin is needed to record the changes under.\n");
    exit(1);
}

$vehicleRepository = new VehicleRepository($db);
$vehicleService = new VehicleService($db, $vehicleRepository, new VehicleStatusLogRepository($db));
$rentalRepository = new RentalRepository($db, new BookingOverlapService($db));
$rentals = RentalRuntimeFactory::service($db);
$chauffeurs = new ChauffeurService($db, $rentalRepository, new ChargeRepository($db), $vehicleRepository, new BookingOverlapService($db));
$payments = new PaymentService($db, new PaymentRepository($db), $rentalRepository, $rentals, new PaymentProofRepository($db), new RulesAcceptanceRepository($db), new RateLimiter($db), new SecurityLogRepository($db), PaymentGatewayFactory::create());

/* ---- Names -------------------------------------------------------------- */

// [name, type, company, referred by]
$customers = [
    ['Maria Santos', 'walk_in', null, null], ['Jose Ramirez', 'repeat', null, null], ['Analyn Dela Cruz', 'online', null, null],
    ['Roberto Villanueva', 'corporate', 'Sarangani Bay Trading', null], ['Grace Mendoza', 'referral', null, 'Referred by Jose Ramirez'], ['Danilo Fernandez', 'walk_in', null, null],
    ['Cristina Lim', 'corporate', 'Mindanao Agri Supply', null], ['Ramon Bautista', 'repeat', null, null], ['Liza Navarro', 'walk_in', null, null],
];
// Each list is matched by position among all rows, and a row is changed only while it still has
// its test name, so running this twice gives the same record the same name.
$rename = $db->prepare("UPDATE customers SET full_name = :name, customer_type = :type, company_name = :company, referral_source = :referral WHERE customer_id = :id AND full_name REGEXP '^(John Doe|Raw Test) [0-9]+$'");
$renamed = 0;
foreach ($db->query('SELECT c.customer_id FROM customers c WHERE NOT EXISTS (SELECT 1 FROM customer_contacts k WHERE k.customer_id = c.customer_id) ORDER BY c.customer_id')->fetchAll(PDO::FETCH_COLUMN) as $i => $id) {
    if (isset($customers[$i])) {
        $rename->execute(['name' => $customers[$i][0], 'type' => $customers[$i][1], 'company' => $customers[$i][2], 'referral' => $customers[$i][3], 'id' => $id]);
        $renamed += $rename->rowCount();
    }
}
$say('Customers renamed: ' . $renamed);

$drivers = ['Arnel Castillo', 'Benjie Flores', 'Carlo Reyes', 'Dennis Aquino', 'Edgar Morales', 'Felix Garcia', 'Gilbert Torres', 'Henry Salazar', 'Ismael Dizon', 'Joel Pascual', 'Kevin Soriano', 'Leo Marquez', 'Marlon Cabrera', 'Noel Agustin', 'Oscar Valdez', 'Paolo Rivera'];
$rename = $db->prepare("UPDATE drivers SET full_name = :name WHERE driver_id = :id AND full_name LIKE 'Driver %'");
$renamed = 0;
foreach ($db->query('SELECT driver_id FROM drivers ORDER BY driver_id')->fetchAll(PDO::FETCH_COLUMN) as $i => $id) {
    if (isset($drivers[$i])) {
        $rename->execute(['name' => $drivers[$i], 'id' => $id]);
        $renamed += $rename->rowCount();
    }
}
$say('Drivers renamed: ' . $renamed);

// A believable licence, a phone and a spread of expiry dates for each driver still on the test
// defaults (expiry 2030-01-01, no contacts): one licence lapsed, one about to, one driver switched off.
// The phone numbers are invented and are only shown; the system never calls or messages a driver.
$driverService = new DriverService($db, new DriverRepository($db), new DriverPiiCipher());
$manilaToday = new DateTimeImmutable('today', new DateTimeZone('Asia/Manila'));
$detailed = 0;
foreach ($db->query("SELECT d.driver_id, d.full_name, d.license_expiry, (SELECT COUNT(*) FROM driver_contacts k WHERE k.driver_id = d.driver_id) AS contacts, EXISTS(SELECT 1 FROM rental_agreements r WHERE r.driver_id = d.driver_id AND r.status IN ('reserved','confirmed','active')) AS busy FROM drivers d WHERE d.deleted_at IS NULL ORDER BY d.driver_id")->fetchAll() as $i => $driver) {
    if ($driver['license_expiry'] !== '2030-01-01' || (int) $driver['contacts'] > 0) {
        continue;
    }
    $id = (int) $driver['driver_id'];
    $busy = (int) $driver['busy'] === 1;
    $expiry = match (true) {
        !$busy && $i === 4 => $manilaToday->modify('-20 days')->format('Y-m-d'),
        !$busy && $i === 7 => $manilaToday->modify('+25 days')->format('Y-m-d'),
        default => sprintf('%d-%02d-%02d', 2027 + $i % 4, 1 + ($i * 5) % 12, 3 + ($i * 7) % 25),
    };
    try {
        $driverService->update($id, ['full_name' => $driver['full_name'], 'license_number' => sprintf('N%02d-%02d-%06d', 1 + $i, 10 + ($i * 3) % 80, (104729 * ($i + 3)) % 1000000), 'license_expiry' => $expiry]);
        $driverService->addContact($id, 'phone', sprintf('0900 555 %04d', 100 + $i), true);
        if (!$busy && $i === 10) {
            $driverService->changeStatus($id, 'inactive', $actor);
        }
        $detailed++;
    } catch (Throwable $error) {
        $say('Driver ' . $id . ' left as it is: ' . $error->getMessage());
    }
}
$say('Drivers given licence and contact details: ' . $detailed);

/* ---- Fleet -------------------------------------------------------------- */

// [plate, year, make, model, colour, body, transmission, fuel, seats, daily rate, odometer]
$fleet = [
    ['MAA 2041', 2022, 'Toyota', 'Vios', 'White', 'sedan', 'automatic', 'gasoline', 5, '2000.00', 38420],
    ['MAB 3187', 2023, 'Ford', 'Everest', 'Black', 'SUV', 'automatic', 'diesel', 7, '3500.00', 27150],
    ['MAC 4410', 2023, 'Toyota', 'Wigo', 'Red', 'hatchback', 'automatic', 'gasoline', 5, '1500.00', 19875],
    ['MAD 5526', 2022, 'Mitsubishi', 'Montero Sport', 'Gray', 'SUV', 'automatic', 'diesel', 7, '3500.00', 41230],
    ['MAE 6093', 2021, 'Mitsubishi', 'Mirage G4', 'Silver', 'sedan', 'manual', 'gasoline', 5, '1800.00', 52610],
    ['MAF 7135', 2023, 'Toyota', 'Fortuner', 'White', 'SUV', 'automatic', 'diesel', 7, '3800.00', 22340],
    ['MAG 1278', 2022, 'Honda', 'City', 'Blue', 'sedan', 'automatic', 'gasoline', 5, '2200.00', 33905],
    ['MAH 2389', 2023, 'Toyota', 'Hiace Commuter', 'White', 'van', 'manual', 'diesel', 15, '4000.00', 46780],
    ['MAJ 3491', 2023, 'Toyota', 'Vios', 'Silver', 'sedan', 'automatic', 'gasoline', 5, '2000.00', 18260],
    ['MAK 4502', 2022, 'Nissan', 'Navara', 'Orange', 'pickup', 'manual', 'diesel', 5, '3000.00', 35490],
    ['MAL 5613', 2023, 'Toyota', 'Vios', 'Red', 'sedan', 'automatic', 'gasoline', 5, '2000.00', 14720],
    ['MAM 6724', 2023, 'Ford', 'Everest', 'Black', 'SUV', 'automatic', 'diesel', 7, '3000.00', 21085],
    ['MAN 7835', 2022, 'Toyota', 'Innova', 'Gray', 'van', 'automatic', 'diesel', 7, '2800.00', 29640],
    ['MAP 8946', 2023, 'Ford', 'Everest', 'White', 'SUV', 'automatic', 'diesel', 7, '3000.00', 16330],
    ['MAR 9057', 2023, 'Toyota', 'Vios', 'White', 'sedan', 'automatic', 'gasoline', 5, '2000.00', 24510],
    ['MAS 1168', 2023, 'Ford', 'Everest', 'Gray', 'SUV', 'automatic', 'diesel', 7, '3000.00', 12890],
    ['MAT 2279', 2023, 'Toyota', 'Vios', 'Black', 'sedan', 'automatic', 'gasoline', 5, '2000.00', 26075],
    ['MAU 3380', 2023, 'Ford', 'Everest', 'Blue', 'SUV', 'automatic', 'diesel', 7, '3000.00', 17945],
    ['MAV 4491', 2024, 'Toyota', 'Raize', 'Yellow', 'SUV', 'automatic', 'gasoline', 5, '2500.00', 9310],
];
$chauffeurRate = ['sedan' => '1000.00', 'hatchback' => '1000.00'];
$respec = $db->prepare("UPDATE vehicles SET plate_number = :plate, model_year = :year, make = :make, model = :model, color = :color, body_type = :body, transmission = :transmission, fuel_type = :fuel, seating_capacity = :seats, daily_rate = :rate, chauffeur_daily_rate = :chauffeur WHERE vehicle_id = :id AND plate_number REGEXP '^(TEST|T1|T2|RAW)-'");
$renamed = 0;
foreach ($db->query('SELECT vehicle_id FROM vehicles ORDER BY vehicle_id')->fetchAll(PDO::FETCH_COLUMN) as $i => $id) {
    if (isset($fleet[$i])) {
        [$plate, $year, $make, $model, $color, $body, $transmission, $fuel, $seats, $rate] = $fleet[$i];
        $respec->execute(['plate' => $plate, 'year' => $year, 'make' => $make, 'model' => $model, 'color' => $color, 'body' => $body, 'transmission' => $transmission, 'fuel' => $fuel, 'seats' => $seats, 'rate' => $rate, 'chauffeur' => $chauffeurRate[$body] ?? '1500.00', 'id' => $id]);
        $renamed += $respec->rowCount();
    }
}
$say('Vehicles given real-looking details: ' . $renamed);

$locationRepository = new VehicleLocationRepository($db);
$locations = [];
foreach (['Main office lot', 'General Santos Airport', 'Downtown pickup point'] as $name) {
    $find = $db->prepare('SELECT location_id FROM vehicle_locations WHERE name = :name');
    $find->execute(['name' => $name]);
    $locations[] = (int) ($find->fetchColumn() ?: $locationRepository->create($name));
}
$office = $locations[0];

// A first odometer reading parks each vehicle somewhere; most sit at the office.
$odometer = array_column($fleet, 10, 0);
foreach ($db->query('SELECT vehicle_id, plate_number, current_mileage FROM vehicles WHERE deleted_at IS NULL AND current_location_id IS NULL ORDER BY vehicle_id')->fetchAll() as $n => $vehicle) {
    $reading = max((int) $vehicle['current_mileage'], $odometer[$vehicle['plate_number']] ?? (int) $vehicle['current_mileage']);
    $vehicleService->recordMileage((int) $vehicle['vehicle_id'], $reading, $n % 5 === 4 ? $locations[1] : ($n % 7 === 6 ? $locations[2] : $office), $actor);
}

// Registration and insurance dates, so the list has something to flag: one lapsed, one due soon.
$stagger = static fn (int $n): string => (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->modify(($n === 3 ? '-5' : ($n === 1 ? '+12' : '+' . (45 + 17 * $n))) . ' days')->format('Y-m-d');
$papers = $db->prepare("UPDATE vehicles SET registration_expiry = :registration, insurance_expiry = :insurance WHERE vehicle_id = :id AND registration_expiry IS NULL AND insurance_expiry IS NULL AND plate_number REGEXP '^MA[A-Z] [0-9]{4}$'");
// One photo each, borrowed from the public site's own fleet pictures, for vehicles that have none.
$pictures = ['sedan' => 'fleet-sedan.jpg', 'hatchback' => 'fleet-sedan.jpg', 'SUV' => 'fleet-suv.webp', 'pickup' => 'fleet-suv.webp', 'van' => 'fleet-van.jpg'];
$mimes = ['jpg' => 'image/jpeg', 'webp' => 'image/webp'];
$storage = Config::get('STORAGE_PATH', 'storage') ?? 'storage';
$storage = str_starts_with($storage, DIRECTORY_SEPARATOR) ? $storage : APP_ROOT . DIRECTORY_SEPARATOR . $storage;
$addPhoto = $db->prepare('INSERT INTO photos (vehicle_id, storage_path, original_filename, mime, size_bytes, sort_order, uploaded_by) VALUES (:vehicle, :path, :original, :mime, :size, 1, :actor)');
$photographed = 0;
foreach ($db->query("SELECT v.vehicle_id, v.body_type, (SELECT COUNT(*) FROM photos p WHERE p.vehicle_id = v.vehicle_id) AS photos FROM vehicles v WHERE v.deleted_at IS NULL AND v.plate_number REGEXP '^MA[A-Z] [0-9]{4}$' ORDER BY v.vehicle_id")->fetchAll() as $n => $vehicle) {
    $papers->execute(['registration' => $stagger($n), 'insurance' => $stagger($n + 6), 'id' => $vehicle['vehicle_id']]);
    $source = APP_ROOT . '/public/assets/img/landing/' . ($pictures[$vehicle['body_type']] ?? '');
    if ((int) $vehicle['photos'] > 0 || !is_file($source)) {
        continue;
    }
    $extension = pathinfo($source, PATHINFO_EXTENSION);
    $path = 'vehicles/' . $vehicle['vehicle_id'] . '/' . bin2hex(random_bytes(24)) . '.' . $extension;
    $target = $storage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if ((is_dir(dirname($target)) || mkdir(dirname($target), 0770, true)) && copy($source, $target)) {
        $addPhoto->execute(['vehicle' => $vehicle['vehicle_id'], 'path' => $path, 'original' => basename($source), 'mime' => $mimes[$extension], 'size' => filesize($target), 'actor' => $actor]);
        $photographed++;
    }
}
$say('Vehicles given a photo: ' . $photographed);

// A vehicle left "rented" by a test run, with no rental out on it, goes back on the lot.
foreach ($db->query("SELECT vehicle_id FROM vehicles v WHERE current_status = 'rented' AND NOT EXISTS (SELECT 1 FROM rental_agreements r WHERE r.vehicle_id = v.vehicle_id AND r.status = 'active')")->fetchAll(PDO::FETCH_COLUMN) as $id) {
    $vehicleService->transitionStatus((int) $id, 'available', $actor);
    $say('Vehicle ' . $id . ' put back on the lot.');
}

/* ---- Bookings ----------------------------------------------------------- */

$manila = new DateTimeZone('Asia/Manila');
$day = static fn (int $offset): string => (new DateTimeImmutable('today', $manila))->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');
$today = $day(0);
// Only bookings of customers without contact details are demo bookings.
$demoOnly = 'NOT EXISTS (SELECT 1 FROM customer_contacts k WHERE k.customer_id = r.customer_id)';
$mileageOf = static fn (int $vehicleId): int => (int) $vehicleRepository->find($vehicleId)['current_mileage'];
$settle = static function (int $id) use ($rentals, $payments, $actor): void {
    if ($rentals->outstandingCents($id) > 0) {
        $payments->recordBalance($id, 'cash', '', '', $actor);
    }
    $rentals->transition($id, 'complete', $actor);
};

// Demo bookings from an earlier run that have gone stale are closed the way staff would close them.
foreach ($db->query("SELECT r.agreement_id, r.vehicle_id, r.status, r.downpayment_status FROM rental_agreements r WHERE {$demoOnly} AND ((r.status IN ('reserved','confirmed') AND r.start_date < '{$today}') OR (r.status = 'active' AND r.end_date < '{$today}')) ORDER BY r.agreement_id")->fetchAll() as $stale) {
    $id = (int) $stale['agreement_id'];
    try {
        if ($stale['status'] === 'active') {
            $rentals->transition($id, 'return', $actor, null, $mileageOf((int) $stale['vehicle_id']) + 180, $office);
            if ($stale['downpayment_status'] === 'received') {
                $settle($id);
            }
            $say("#{$id} returned and closed.");
        } else {
            $rentals->transition($id, 'cancel', $actor, 'Not picked up on the booked date.');
            $say("#{$id} cancelled: not picked up.");
        }
    } catch (Throwable $error) {
        $say("#{$id} left as it is: " . $error->getMessage());
    }
}

$customerId = static function (string $name) use ($db): int {
    $find = $db->prepare('SELECT customer_id FROM customers WHERE full_name = :name AND deleted_at IS NULL ORDER BY customer_id LIMIT 1');
    $find->execute(['name' => $name]);
    return (int) $find->fetchColumn();
};
$vehicleId = static function (string $plate) use ($db): int {
    $find = $db->prepare('SELECT vehicle_id FROM vehicles WHERE plate_number = :plate AND deleted_at IS NULL');
    $find->execute(['plate' => $plate]);
    return (int) $find->fetchColumn();
};

/*
 * Each booking: who, which vehicle, days from today, pickup and return hour, how the downpayment
 * was paid (null leaves it due), and how far through its life it goes.
 * Stages: reserved < confirmed < active < completed.
 */
$bookings = [
    ['Maria Santos', 'MAC 4410', 'self_drive', -3, 0, '09:00', '17:00', ['gcash', 'GC-48201773'], 'completed'],
    ['Jose Ramirez', 'MAE 6093', 'self_drive', -2, 0, '10:00', '18:00', ['maya', 'MY-77310295'], 'completed'],
    ['Grace Mendoza', 'MAG 1278', 'self_drive', -1, 0, '08:00', '18:00', ['cash', ''], 'active'],
    ['Cristina Lim', 'MAF 7135', 'chauffeur', 0, 2, '08:00', '18:00', ['online_banking', 'BDO-20261006-5512'], 'active'],
    ['Analyn Dela Cruz', 'MAN 7835', 'self_drive', 0, 3, '16:00', '16:00', ['gcash', 'GC-48219004'], 'confirmed'],
    ['Roberto Villanueva', 'MAH 2389', 'self_drive', 2, 4, '07:00', '19:00', ['online_banking', 'BPI-20261006-0931'], 'reserved'],
    ['Liza Navarro', 'MAJ 3491', 'self_drive', 3, 5, '09:00', '17:00', null, 'reserved'],
];
$rank = ['reserved' => 0, 'confirmed' => 1, 'active' => 2, 'completed' => 3];
foreach ($bookings as [$customer, $plate, $type, $from, $to, $pickupAt, $returnAt, $paid, $stage]) {
    $label = "{$customer}, {$plate}";
    $id = null;
    try {
        $vehicle = $vehicleId($plate);
        $exists = $db->prepare("SELECT 1 FROM rental_agreements WHERE customer_id = :customer AND vehicle_id = :vehicle AND start_date = :start AND status NOT IN ('cancelled','no_show')");
        $exists->execute(['customer' => $customerId($customer), 'vehicle' => $vehicle, 'start' => $day($from)]);
        if ($exists->fetchColumn() !== false) {
            $say("Already there: {$label}");
            continue;
        }
        $id = $rentals->create(['customer_id' => $customerId($customer), 'vehicle_id' => $vehicle, 'rental_type' => $type, 'start_date' => $day($from), 'end_date' => $day($to), 'scheduled_pickup_at' => $day($from) . 'T' . $pickupAt, 'scheduled_return_at' => $day($to) . 'T' . $returnAt, 'deposit_amount' => '0'], $actor);
        if ($type === 'chauffeur') {
            $free = $db->query("SELECT d.driver_id FROM drivers d WHERE d.status = 'active' AND d.deleted_at IS NULL AND d.license_expiry >= '" . $day($to) . "' AND NOT EXISTS (SELECT 1 FROM rental_agreements r WHERE r.driver_id = d.driver_id AND r.status IN ('reserved','confirmed','active')) ORDER BY d.driver_id LIMIT 1")->fetchColumn();
            $chauffeurs->assignDriver($id, (int) $free, $actor);
        }
        if ($paid !== null) {
            // A reference can be used once, so each run's is made its own by the agreement number.
            $rentals->recordDownpayment($id, $paid[1] === '' ? '' : $paid[1] . '-' . $id, $actor, $paid[0]);
        }
        if ($rank[$stage] >= 1) {
            $rentals->transition($id, 'confirm', $actor);
        }
        if ($rank[$stage] >= 2) {
            $rentals->transition($id, 'pickup', $actor, null, $mileageOf($vehicle), $office);
        }
        if ($rank[$stage] >= 3) {
            $rentals->transition($id, 'return', $actor, null, $mileageOf($vehicle) + 120 * max(1, $to - $from), $office);
            $settle($id);
        }
        $say("#{$id} {$stage}: {$label}");
    } catch (Throwable $error) {
        $say("Skipped ({$label}): " . $error->getMessage());
        // A booking that got only part of the way is not left behind half-made.
        if ($id !== null) {
            try { $rentals->transition($id, 'cancel', $actor, 'Demo booking could not be set up.'); } catch (Throwable) {}
        }
    }
}
$say('Done.');
