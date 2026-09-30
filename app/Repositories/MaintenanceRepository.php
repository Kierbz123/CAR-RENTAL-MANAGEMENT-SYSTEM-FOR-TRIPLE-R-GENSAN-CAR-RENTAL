<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class MaintenanceRepository
{
    public function __construct(private readonly PDO $db) {}

    public function vehicles(): array
    {
        return $this->db->query("SELECT vehicle_id,plate_number,make,model,current_status,current_mileage FROM vehicles WHERE deleted_at IS NULL ORDER BY plate_number")->fetchAll();
    }

    public function schedules(?int $vehicleId = null): array
    {
        $sql='SELECT s.*,v.plate_number,v.make,v.model FROM maintenance_schedules s JOIN vehicles v ON v.vehicle_id=s.vehicle_id';
        if ($vehicleId !== null) { $sql .= ' WHERE s.vehicle_id=:vehicle'; }
        $sql .= ' ORDER BY v.plate_number,s.schedule_name';
        $q=$this->db->prepare($sql); $q->execute($vehicleId===null?[]:['vehicle'=>$vehicleId]); return $q->fetchAll();
    }

    public function dueSoon(int $days, int $kilometers): array
    {
        $sql="SELECT s.schedule_id,s.schedule_name,s.vehicle_id,s.interval_time_days,s.interval_mileage,s.next_due_date,s.next_due_mileage,s.due_soon_days_override,s.due_soon_mileage_override,v.plate_number,v.make,v.model,v.current_mileage,
            CASE WHEN (s.next_due_date IS NOT NULL AND s.next_due_date<=DATE(CONVERT_TZ(UTC_TIMESTAMP(6),'+00:00','+08:00'))) OR (s.next_due_mileage IS NOT NULL AND v.current_mileage>=s.next_due_mileage) THEN 'due' ELSE 'due_soon' END AS due_state,
            COALESCE(s.due_soon_days_override,:days) AS effective_due_soon_days,
            COALESCE(s.due_soon_mileage_override,:kilometers) AS effective_due_soon_mileage
            FROM maintenance_schedules s JOIN vehicles v ON v.vehicle_id=s.vehicle_id
            WHERE s.is_active=1 AND v.deleted_at IS NULL AND (
                (s.next_due_date IS NOT NULL AND s.next_due_date<=DATE_ADD(DATE(CONVERT_TZ(UTC_TIMESTAMP(6),'+00:00','+08:00')),INTERVAL COALESCE(s.due_soon_days_override,:days2) DAY))
                OR (s.next_due_mileage IS NOT NULL AND v.current_mileage>=GREATEST(0,CAST(s.next_due_mileage AS SIGNED)-CAST(COALESCE(s.due_soon_mileage_override,:kilometers2) AS SIGNED)))
            ) ORDER BY v.plate_number,s.schedule_name";
        $q=$this->db->prepare($sql);$q->execute(['days'=>$days,'kilometers'=>$kilometers,'days2'=>$days,'kilometers2'=>$kilometers]);return $q->fetchAll();
    }

    public function services(?int $vehicleId = null): array
    {
        $sql="SELECT m.*,v.plate_number,v.make,v.model,s.schedule_name,u.email AS mechanic_name FROM maintenance_services m JOIN vehicles v ON v.vehicle_id=m.vehicle_id LEFT JOIN maintenance_schedules s ON s.schedule_id=m.schedule_id JOIN users u ON u.id=m.mechanic_id";
        if ($vehicleId !== null) $sql.=' WHERE m.vehicle_id=:vehicle';
        $sql.=' ORDER BY m.started_at DESC,m.service_id DESC';$q=$this->db->prepare($sql);$q->execute($vehicleId===null?[]:['vehicle'=>$vehicleId]);return $q->fetchAll();
    }

    public function service(int $serviceId): ?array
    {
        $q=$this->db->prepare("SELECT m.*,v.plate_number,v.make,v.model,s.schedule_name,u.email AS mechanic_name FROM maintenance_services m JOIN vehicles v ON v.vehicle_id=m.vehicle_id LEFT JOIN maintenance_schedules s ON s.schedule_id=m.schedule_id JOIN users u ON u.id=m.mechanic_id WHERE m.service_id=:id");$q->execute(['id'=>$serviceId]);$row=$q->fetch();return $row?:null;
    }

    public function photos(int $serviceId): array
    {
        $q=$this->db->prepare('SELECT * FROM maintenance_photos WHERE service_id=:id ORDER BY phase,photo_id');$q->execute(['id'=>$serviceId]);return $q->fetchAll();
    }

    public function costAudits(int $serviceId): array
    {
        $q=$this->db->prepare('SELECT a.*,u.email AS actor_name FROM maintenance_cost_audit_logs a JOIN users u ON u.id=a.actor_user_id WHERE a.service_id=:id ORDER BY a.created_at,a.cost_audit_id');$q->execute(['id'=>$serviceId]);return $q->fetchAll();
    }

    public function needsReview(): array
    {
        return $this->db->query("SELECT m.service_id,m.vehicle_id,m.vehicle_status_before,m.completed_at,m.cancelled_at,v.plate_number,v.make,v.model FROM maintenance_services m JOIN vehicles v ON v.vehicle_id=m.vehicle_id WHERE m.needs_review=1 ORDER BY COALESCE(m.completed_at,m.cancelled_at) DESC")->fetchAll();
    }
}
