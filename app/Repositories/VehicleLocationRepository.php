<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class VehicleLocationRepository
{
    public function __construct(private readonly PDO $db) {}

    public function selectable(): array
    {
        return $this->db->query("SELECT location_id, name FROM vehicle_locations WHERE location_status = 'active' AND deleted_at IS NULL ORDER BY name")->fetchAll();
    }

    public function all(): array
    {
        return $this->db->query('SELECT * FROM vehicle_locations WHERE deleted_at IS NULL ORDER BY name')->fetchAll();
    }

    public function create(string $name): int
    {
        $stmt = $this->db->prepare("INSERT INTO vehicle_locations (name, location_status) VALUES (:name, 'active')");
        $stmt->execute(['name'=>$name]); return (int) $this->db->lastInsertId();
    }

    public function remove(int $id): bool
    {
        $this->db->beginTransaction();
        try {
            $lock=$this->db->prepare('SELECT location_id,location_status FROM vehicle_locations WHERE location_id=:id AND deleted_at IS NULL FOR UPDATE'); $lock->execute(['id'=>$id]); $location=$lock->fetch();
            if (!$location) { $this->db->rollBack(); return false; }
            if ($location['location_status']!=='retired') throw new \RuntimeException('Retire a location before removing it.');
            $check=$this->db->prepare('SELECT (SELECT COUNT(*) FROM vehicles WHERE current_location_id=:id1)+(SELECT COUNT(*) FROM vehicle_status_logs WHERE location_id=:id2)+(SELECT COUNT(*) FROM vehicle_mileage_logs WHERE location_id=:id3)');
            $check->execute(['id1'=>$id,'id2'=>$id,'id3'=>$id]);
            if ((int)$check->fetchColumn()>0) throw new \RuntimeException('A location referenced in fleet history cannot be removed. Retire it instead.');
            $stmt = $this->db->prepare('UPDATE vehicle_locations SET deleted_at = UTC_TIMESTAMP(6) WHERE location_id = :id AND deleted_at IS NULL');
            $stmt->execute(['id'=>$id]); $this->db->commit(); return $stmt->rowCount() === 1;
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }
}
