<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class DriverRepository
{
    public function __construct(private readonly PDO $db) {}

    public function list(?string $search = null, bool $includeDeleted = false): array
    {
        $sql = 'SELECT * FROM drivers WHERE 1=1';
        if (!$includeDeleted) $sql .= ' AND deleted_at IS NULL';
        $params = [];
        if ($search !== null && trim($search) !== '') {
            $sql .= ' AND full_name LIKE :search';
            $params['search'] = '%' . trim($search) . '%';
        }
        $sql .= ' ORDER BY full_name, driver_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function selectableForAssignment(string $manilaDate): array
    {
        $stmt = $this->db->prepare("SELECT driver_id, full_name, license_expiry FROM drivers WHERE status='active' AND deleted_at IS NULL AND license_expiry >= :today ORDER BY full_name, driver_id");
        $stmt->execute(['today' => $manilaDate]);
        return $stmt->fetchAll();
    }

    public function find(int $id, bool $lock = false, bool $includeDeleted = false): ?array
    {
        $sql = 'SELECT * FROM drivers WHERE driver_id=:id';
        if (!$includeDeleted) $sql .= ' AND deleted_at IS NULL';
        if ($lock) $sql .= ' FOR UPDATE';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): int
    {
        $stmt = $this->db->prepare('INSERT INTO drivers (full_name,license_number_ciphertext,license_number_fingerprint,license_expiry,address_ciphertext,emergency_contact_name_ciphertext,emergency_contact_phone_ciphertext,notes) VALUES (:name,:license,:fingerprint,:expiry,:address,:emergency_name,:emergency_phone,:notes)');
        $stmt->execute(['name'=>$data['full_name'],'license'=>$data['license_ciphertext'],'fingerprint'=>$data['license_fingerprint'],'expiry'=>$data['license_expiry'],'address'=>$data['address_ciphertext'],'emergency_name'=>$data['emergency_name_ciphertext'],'emergency_phone'=>$data['emergency_phone_ciphertext'],'notes'=>$data['notes']]);
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->db->prepare('UPDATE drivers SET full_name=:name,license_number_ciphertext=:license,license_number_fingerprint=:fingerprint,license_expiry=:expiry,address_ciphertext=:address,emergency_contact_name_ciphertext=:emergency_name,emergency_contact_phone_ciphertext=:emergency_phone,notes=:notes WHERE driver_id=:id AND deleted_at IS NULL');
        $stmt->execute(['name'=>$data['full_name'],'license'=>$data['license_ciphertext'],'fingerprint'=>$data['license_fingerprint'],'expiry'=>$data['license_expiry'],'address'=>$data['address_ciphertext'],'emergency_name'=>$data['emergency_name_ciphertext'],'emergency_phone'=>$data['emergency_phone_ciphertext'],'notes'=>$data['notes'],'id'=>$id]);
    }

    public function contacts(int $driverId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM driver_contacts WHERE driver_id=:id AND deleted_at IS NULL ORDER BY is_primary DESC,contact_type,contact_id');
        $stmt->execute(['id' => $driverId]);
        return $stmt->fetchAll();
    }

    public function findContact(int $contactId, int $driverId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM driver_contacts WHERE contact_id=:contact AND driver_id=:driver AND deleted_at IS NULL');
        $stmt->execute(['contact'=>$contactId,'driver'=>$driverId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function addContact(int $driverId, string $type, string $ciphertext, bool $primary): int
    {
        if ($primary) $this->db->prepare('UPDATE driver_contacts SET is_primary=0 WHERE driver_id=:driver AND contact_type=:type AND deleted_at IS NULL')->execute(['driver'=>$driverId,'type'=>$type]);
        $stmt = $this->db->prepare('INSERT INTO driver_contacts (driver_id,contact_type,contact_ciphertext,is_primary) VALUES (:driver,:type,:cipher,:primary)');
        $stmt->execute(['driver'=>$driverId,'type'=>$type,'cipher'=>$ciphertext,'primary'=>$primary?1:0]);
        return (int) $this->db->lastInsertId();
    }

    public function updateContact(int $contactId, int $driverId, string $type, string $ciphertext, bool $primary): void
    {
        if ($primary) $this->db->prepare('UPDATE driver_contacts SET is_primary=0 WHERE driver_id=:driver AND contact_type=:type AND contact_id<>:contact AND deleted_at IS NULL')->execute(['driver'=>$driverId,'type'=>$type,'contact'=>$contactId]);
        $stmt = $this->db->prepare('UPDATE driver_contacts SET contact_type=:type,contact_ciphertext=:cipher,is_primary=:primary WHERE contact_id=:contact AND driver_id=:driver AND deleted_at IS NULL');
        $stmt->execute(['type'=>$type,'cipher'=>$ciphertext,'primary'=>$primary?1:0,'contact'=>$contactId,'driver'=>$driverId]);
    }

    public function removeContact(int $contactId, int $driverId): void
    {
        $this->db->prepare('UPDATE driver_contacts SET deleted_at=UTC_TIMESTAMP(6),is_primary=0 WHERE contact_id=:contact AND driver_id=:driver AND deleted_at IS NULL')->execute(['contact'=>$contactId,'driver'=>$driverId]);
    }

    public function appendStatus(int $driverId, ?string $old, string $new, int $actor): void
    {
        $stmt = $this->db->prepare('INSERT INTO driver_status_logs (driver_id,old_status,new_status,actor_user_id) VALUES (:driver,:old,:new,:actor)');
        $stmt->execute(['driver'=>$driverId,'old'=>$old,'new'=>$new,'actor'=>$actor]);
    }

    public function statusHistory(int $driverId): array
    {
        $stmt = $this->db->prepare('SELECT h.*,u.email AS actor_email FROM driver_status_logs h JOIN users u ON u.id=h.actor_user_id WHERE h.driver_id=:id ORDER BY h.created_at,h.status_log_id');
        $stmt->execute(['id'=>$driverId]);
        return $stmt->fetchAll();
    }

    public function assignmentHistory(int $driverId): array
    {
        $column = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='rental_agreements' AND column_name='driver_id' LIMIT 1");
        $column->execute();
        if ($column->fetchColumn() === false) return [];
        $stmt = $this->db->prepare('SELECT agreement_id,status,start_date,end_date FROM rental_agreements WHERE driver_id=:driver ORDER BY start_date DESC,agreement_id DESC');
        $stmt->execute(['driver'=>$driverId]);
        return $stmt->fetchAll();
    }

    public function conflictsWith(int $driverId, string $start, string $end, ?int $excludeAgreementId = null): bool
    {
        // M5 handoff: BookingOverlapService owns the sole half-open overlap query.
        return (new \TripleR\Services\BookingOverlapService($this->db))->driverConflicts($driverId,$start,$end,$excludeAgreementId);
    }

    public function hasOpenAgreement(int $driverId): bool
    {
        $table = $this->db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='rental_agreements' LIMIT 1");
        $table->execute();
        if ($table->fetchColumn() === false) return false;
        $column = $this->db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='rental_agreements' AND column_name='driver_id' LIMIT 1");
        $column->execute();
        if ($column->fetchColumn() === false) return false;
        $stmt = $this->db->prepare("SELECT 1 FROM rental_agreements WHERE driver_id=:driver AND status IN ('reserved','confirmed','active') LIMIT 1");
        $stmt->execute(['driver'=>$driverId]);
        return $stmt->fetchColumn() !== false;
    }
}
