<?php
declare(strict_types=1);

use TripleR\Config;
use TripleR\Database;
use TripleR\Repositories\StaffUserRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $email = Config::require('SEED_ADMIN_EMAIL');
    $password = Config::require('SEED_ADMIN_PASSWORD');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 14) {
        throw new RuntimeException('Use a valid SEED_ADMIN_EMAIL and a SEED_ADMIN_PASSWORD of at least 14 characters.');
    }
    $created = (new StaffUserRepository(Database::connection()))->createAdmin($email, $password);
    echo $created ? "Created system administrator: {$email}\n" : "Administrator already exists; password left unchanged.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Seeding failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
