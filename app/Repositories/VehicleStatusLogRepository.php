<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class VehicleStatusLogRepository
{
    public function __construct(private readonly PDO $db) {}

    public function append(int $vehicleId, ?string $oldStatus, string $newStatus, ?int $locationId, ?int $mileage, int $actorId): void
    {
        $stmt = $this->db->prepare('INSERT INTO vehicle_status_logs (vehicle_id, old_status, new_status, location_id, mileage, actor_user_id) VALUES (:vehicle, :old, :new, :location, :mileage, :actor)');
        $stmt->execute(['vehicle'=>$vehicleId,'old'=>$oldStatus,'new'=>$newStatus,'location'=>$locationId,'mileage'=>$mileage,'actor'=>$actorId]);
    }
}
