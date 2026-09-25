<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class VehicleRepository
{
    public function __construct(private readonly PDO $db) {}

    public function list(array $filters = []): array
    {
        $sql = 'SELECT v.*, l.name AS location_name FROM vehicles v LEFT JOIN vehicle_locations l ON l.location_id = v.current_location_id WHERE v.deleted_at IS NULL';
        $params = [];
        if (!empty($filters['status'])) { $sql .= ' AND v.current_status = :status'; $params['status'] = $filters['status']; }
        $sql .= ' ORDER BY v.plate_number';
        $stmt = $this->db->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function availableForBooking(): array
    {
        return $this->db->query("SELECT v.*,l.name AS location_name FROM vehicles v LEFT JOIN vehicle_locations l ON l.location_id=v.current_location_id WHERE v.deleted_at IS NULL AND v.current_status IN ('available','reserved') ORDER BY v.plate_number")->fetchAll();
    }

    public function find(int $id, bool $lock = false): ?array
    {
        $stmt = $this->db->prepare('SELECT v.*, l.name AS location_name FROM vehicles v LEFT JOIN vehicle_locations l ON l.location_id=v.current_location_id WHERE v.vehicle_id = :id AND v.deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findIncludingRetired(int $id): ?array
    {
        $stmt=$this->db->prepare('SELECT v.*, l.name AS location_name FROM vehicles v LEFT JOIN vehicle_locations l ON l.location_id=v.current_location_id WHERE v.vehicle_id=:id');
        $stmt->execute(['id'=>$id]); $row=$stmt->fetch(); return $row ?: null;
    }

    public function create(array $data): int
    {
        $columns = ['plate_number','engine_number','chassis_number','make','model','model_year','color','body_type','transmission','fuel_type','seating_capacity','daily_rate','chauffeur_daily_rate','current_status','current_mileage','current_location_id','registration_expiry','insurance_expiry','insurance_provider','notes'];
        $values = array_map(static fn(string $column): string => ':' . $column, $columns);
        $stmt = $this->db->prepare('INSERT INTO vehicles (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')');
        $values=[]; foreach ($columns as $column) { $values[$column]=$data[$column] ?? null; }
        $stmt->execute($values);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $columns = ['plate_number','engine_number','chassis_number','make','model','model_year','color','body_type','transmission','fuel_type','seating_capacity','daily_rate','chauffeur_daily_rate','current_location_id','registration_expiry','insurance_expiry','insurance_provider','notes'];
        $sets = array_map(static fn(string $column): string => $column . ' = :' . $column, $columns);
        $stmt = $this->db->prepare('UPDATE vehicles SET ' . implode(',', $sets) . ' WHERE vehicle_id = :id AND deleted_at IS NULL');
        $values=[]; foreach ($columns as $column) { $values[$column]=$data[$column] ?? null; }
        $values['id']=$id; $stmt->execute($values);
    }

    public function photos(int $id): array
    {
        $stmt = $this->db->prepare('SELECT photo_id, original_filename, mime, size_bytes, sort_order FROM vehicle_photos WHERE vehicle_id = :id ORDER BY sort_order, photo_id');
        $stmt->execute(['id' => $id]); return $stmt->fetchAll();
    }

    public function statusHistory(int $id): array
    {
        $stmt = $this->db->prepare('SELECT h.*, l.name AS location_name, u.email AS actor_email FROM vehicle_status_logs h LEFT JOIN vehicle_locations l ON l.location_id = h.location_id JOIN users u ON u.id = h.actor_user_id WHERE h.vehicle_id = :id ORDER BY h.created_at, h.status_log_id');
        $stmt->execute(['id' => $id]); return $stmt->fetchAll();
    }

    public function mileageHistory(int $id): array
    {
        $stmt = $this->db->prepare('SELECT m.*, l.name AS location_name, u.email AS actor_email FROM vehicle_mileage_logs m LEFT JOIN vehicle_locations l ON l.location_id = m.location_id JOIN users u ON u.id = m.actor_user_id WHERE m.vehicle_id = :id ORDER BY m.recorded_at, m.mileage_log_id');
        $stmt->execute(['id' => $id]); return $stmt->fetchAll();
    }
}
