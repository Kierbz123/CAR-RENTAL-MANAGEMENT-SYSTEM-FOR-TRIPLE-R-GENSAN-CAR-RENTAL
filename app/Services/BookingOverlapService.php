<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDO;
use TripleR\Config;

/**
 * Overlap checks shared by vehicles and drivers.
 *
 * Two bookings conflict when their Manila calendar dates overlap (half-open: one may start on the
 * day another ends; a same-day booking counts as that whole day), or, when both have scheduled
 * times, when those times overlap, allowing BOOKING_TURNAROUND_MINUTES between a return and the
 * next pickup. The time rule catches a hand-off day where the next pickup is before the return.
 *
 * The query is a locking read (FOR SHARE). Inside a transaction a plain SELECT reads the snapshot
 * taken at the transaction's first read, which can predate another request's committed booking;
 * a locking read always sees the latest committed rows.
 */
final class BookingOverlapService
{
    public function __construct(private readonly PDO $db) {}

    /** $pickupAt and $returnAt are UTC 'Y-m-d H:i:s' strings, or null to compare dates only. */
    public function vehicleConflicts(int $vehicleId, string $start, string $end, ?int $excludeAgreementId = null, ?string $pickupAt = null, ?string $returnAt = null): bool
    {
        return $this->conflicts('vehicle_id', $vehicleId, $start, $end, $excludeAgreementId, $pickupAt, $returnAt);
    }

    public function driverConflicts(int $driverId, string $start, string $end, ?int $excludeAgreementId = null, ?string $pickupAt = null, ?string $returnAt = null): bool
    {
        return $this->conflicts('driver_id', $driverId, $start, $end, $excludeAgreementId, $pickupAt, $returnAt);
    }

    private function conflicts(string $column, int $id, string $start, string $end, ?int $exclude, ?string $pickupAt, ?string $returnAt): bool
    {
        if (!in_array($column, ['vehicle_id','driver_id'], true)) throw new \InvalidArgumentException('Invalid overlap scope.');
        $requestEnd=$end===$start?(new \DateTimeImmutable($end,new \DateTimeZone('Asia/Manila')))->modify('+1 day')->format('Y-m-d'):$end;
        $sql = "SELECT 1 FROM rental_agreements WHERE {$column}=:subject AND status IN ('reserved','confirmed','active') AND ((start_date < :request_end AND DATE_ADD(end_date,INTERVAL IF(end_date=start_date,1,0) DAY) > :request_start)";
        $params = ['subject'=>$id,'request_start'=>$start,'request_end'=>$requestEnd];
        if ($pickupAt !== null && $returnAt !== null) {
            $turnaround = max(0, min(1440, Config::int('BOOKING_TURNAROUND_MINUTES', 0)));
            $sql .= " OR (scheduled_pickup_at IS NOT NULL AND scheduled_return_at IS NOT NULL AND scheduled_pickup_at < DATE_ADD(:return_at, INTERVAL {$turnaround} MINUTE) AND DATE_ADD(scheduled_return_at, INTERVAL {$turnaround} MINUTE) > :pickup_at)";
            $params['pickup_at'] = $pickupAt;
            $params['return_at'] = $returnAt;
        }
        $sql .= ')';
        if ($exclude !== null) { $sql .= ' AND agreement_id<>:exclude_id'; $params['exclude_id']=$exclude; }
        $sql .= ' LIMIT 1 FOR SHARE';
        $stmt = $this->db->prepare($sql); $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }
}
