<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDO;

/** Half-open Manila-calendar date overlap checks shared by vehicles and drivers. */
final class BookingOverlapService
{
    public function __construct(private readonly PDO $db) {}

    public function vehicleConflicts(int $vehicleId, string $start, string $end, ?int $excludeAgreementId = null): bool
    {
        return $this->conflicts('vehicle_id', $vehicleId, $start, $end, $excludeAgreementId);
    }

    public function driverConflicts(int $driverId, string $start, string $end, ?int $excludeAgreementId = null): bool
    {
        return $this->conflicts('driver_id', $driverId, $start, $end, $excludeAgreementId);
    }

    private function conflicts(string $column, int $id, string $start, string $end, ?int $exclude): bool
    {
        if (!in_array($column, ['vehicle_id','driver_id'], true)) throw new \InvalidArgumentException('Invalid overlap scope.');
        $requestEnd=$end===$start?(new \DateTimeImmutable($end,new \DateTimeZone('Asia/Manila')))->modify('+1 day')->format('Y-m-d'):$end;
        $sql = "SELECT 1 FROM rental_agreements WHERE {$column}=:subject AND status IN ('reserved','confirmed','active') AND start_date < :request_end AND DATE_ADD(end_date,INTERVAL IF(end_date=start_date,1,0) DAY) > :request_start";
        $params = ['subject'=>$id,'request_start'=>$start,'request_end'=>$requestEnd];
        if ($exclude !== null) { $sql .= ' AND agreement_id<>:exclude_id'; $params['exclude_id']=$exclude; }
        $sql .= ' LIMIT 1';
        $stmt = $this->db->prepare($sql); $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }
}
