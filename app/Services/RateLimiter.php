<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDO;

final class RateLimiter
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function allow(string $scope, string $identity, int $limit, int $windowSeconds): bool
    {
        $key = hash('sha256', $scope . ':' . $identity);
        // One row per limiter in rate_counters; its window restarts in place once it has run out.
        $sql = "INSERT INTO rate_counters (scope, counter_key, window_started_at, hits) VALUES ('throttle', :key, UTC_TIMESTAMP(6), 1) "
            . 'ON DUPLICATE KEY UPDATE hits = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ' . max(1, $windowSeconds) . ' SECOND), 1, hits + 1), '
            . 'window_started_at = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ' . max(1, $windowSeconds) . ' SECOND), UTC_TIMESTAMP(6), window_started_at)';
        $statement = $this->db->prepare($sql);
        $statement->execute(['key' => $key]);
        $check = $this->db->prepare("SELECT hits FROM rate_counters WHERE scope = 'throttle' AND counter_key = :key");
        $check->execute(['key' => $key]);
        return (int) $check->fetchColumn() <= $limit;
    }
}
