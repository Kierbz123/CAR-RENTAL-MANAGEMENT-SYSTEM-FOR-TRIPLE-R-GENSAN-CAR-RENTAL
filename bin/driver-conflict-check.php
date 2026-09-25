<?php
declare(strict_types=1);

use TripleR\Database;
use TripleR\Repositories\DriverRepository;

require dirname(__DIR__) . '/app/bootstrap.php';

$args = array_slice($argv, 1);
if (count($args) < 3 || count($args) > 4 || filter_var($args[0] ?? null, FILTER_VALIDATE_INT) === false || (int)$args[0] < 1) {
    fwrite(STDERR, 'Usage: php bin/driver-conflict-check.php <driver_id> <start_yyyy-mm-dd> <end_yyyy-mm-dd> [exclude_agreement_id]' . PHP_EOL);
    exit(2);
}

$date = static function(string $value): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $parsed !== false && $parsed->format('Y-m-d') === $value;
};
$start = $args[1];
$end = $args[2];
if (!$date($start) || !$date($end) || $end <= $start) {
    fwrite(STDERR, 'Start/end must be valid dates and end must be after start.' . PHP_EOL);
    exit(2);
}
$exclude = null;
if (isset($args[3])) {
    $parsedExclude = filter_var($args[3], FILTER_VALIDATE_INT);
    if ($parsedExclude === false || $parsedExclude < 1) {
        fwrite(STDERR, 'exclude_agreement_id must be a positive integer.' . PHP_EOL);
        exit(2);
    }
    $exclude = (int)$parsedExclude;
}

try {
    $repository = new DriverRepository(Database::connection());
    $conflict = $repository->conflictsWith((int)$args[0],$start,$end,$exclude);
    echo $conflict ? 'CONFLICT' . PHP_EOL : 'NO_CONFLICT' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Driver conflict check failed: ' . get_class($error) . PHP_EOL);
    exit(1);
}
