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
        $sql = 'INSERT INTO rate_limits (limiter_key, window_started_at, attempts) VALUES (:key, UTC_TIMESTAMP(), 1) '
            . 'ON DUPLICATE KEY UPDATE attempts = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max(1, $windowSeconds) . ' SECOND), 1, attempts + 1), '
            . 'window_started_at = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . max(1, $windowSeconds) . ' SECOND), UTC_TIMESTAMP(), window_started_at)';
        $statement = $this->db->prepare($sql);
        $statement->execute(['key' => $key]);
        $check = $this->db->prepare('SELECT attempts FROM rate_limits WHERE limiter_key = :key');
        $check->execute(['key' => $key]);
        return (int) $check->fetchColumn() <= $limit;
    }
}
