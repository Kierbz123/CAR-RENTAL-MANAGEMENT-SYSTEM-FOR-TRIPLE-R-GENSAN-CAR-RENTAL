<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class VehicleRepository
{
    public function __construct(private readonly PDO $db) {}

    public function list(array $filters = [], ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = self::listFilter($filters);
        $sql = 'SELECT v.*, l.name AS location_name, (SELECT p.photo_id FROM photos p WHERE p.vehicle_id = v.vehicle_id ORDER BY p.sort_order, p.photo_id LIMIT 1) AS cover_photo_id FROM vehicles v LEFT JOIN vehicle_locations l ON l.location_id = v.current_location_id WHERE ' . $where . ' ORDER BY v.plate_number';
        if ($limit !== null) $sql .= ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
        $stmt = $this->db->prepare($sql); $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function count(array $filters = []): int
    {
        [$where, $params] = self::listFilter($filters);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM vehicles v WHERE ' . $where); $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** @return array{string,array} */
    private static function listFilter(array $filters): array
    {
        $where = 'v.deleted_at IS NULL'; $params = [];
        if (!empty($filters['status'])) { $where .= ' AND v.current_status = :status'; $params['status'] = $filters['status']; }
        if (!empty($filters['location'])) { $where .= ' AND v.current_location_id = :location'; $params['location'] = (int) $filters['location']; }
        if (trim((string) ($filters['search'] ?? '')) !== '') { $where .= " AND (v.plate_number LIKE :plate_search OR CONCAT(v.make, ' ', v.model) LIKE :model_search)"; $params['plate_search'] = $params['model_search'] = '%' . trim((string) $filters['search']) . '%'; }
        return [$where, $params];
    }

    public function availableForBooking(): array
    {
        return $this->db->query("SELECT v.*,l.name AS location_name FROM vehicles v LEFT JOIN vehicle_locations l ON l.location_id=v.current_location_id WHERE v.deleted_at IS NULL AND v.current_status IN ('available','reserved','rented') ORDER BY v.plate_number")->fetchAll();
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
        $stmt = $this->db->prepare('SELECT photo_id, original_filename, mime, size_bytes, sort_order FROM photos WHERE vehicle_id = :id ORDER BY sort_order, photo_id');
        $stmt->execute(['id' => $id]); return $stmt->fetchAll();
    }

    public function statusHistory(int $id): array
    {
        $stmt = $this->db->prepare('SELECT h.*, l.name AS location_name, u.email AS actor_email FROM status_logs h LEFT JOIN vehicle_locations l ON l.location_id = h.location_id JOIN users u ON u.id = h.actor_user_id WHERE h.vehicle_id = :id AND h.subject = \'vehicle\' ORDER BY h.created_at, h.status_log_id');
        $stmt->execute(['id' => $id]); return $stmt->fetchAll();
    }

    public function mileageHistory(int $id): array
    {
        $stmt = $this->db->prepare('SELECT m.*, l.name AS location_name, u.email AS actor_email FROM vehicle_mileage_logs m LEFT JOIN vehicle_locations l ON l.location_id = m.location_id JOIN users u ON u.id = m.actor_user_id WHERE m.vehicle_id = :id ORDER BY m.recorded_at, m.mileage_log_id');
        $stmt->execute(['id' => $id]); return $stmt->fetchAll();
    }
}
