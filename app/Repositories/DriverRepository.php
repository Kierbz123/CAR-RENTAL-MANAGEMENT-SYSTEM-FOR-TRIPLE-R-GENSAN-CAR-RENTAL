<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class DriverRepository
{
    public function __construct(private readonly PDO $db) {}

    /** The four places a driver can stand today, in the order the roster lists them. */
    public const AVAILABILITY = ['free', 'booked', 'on_trip', 'unavailable'];

    /**
     * How a driver stands on the date bound to :$today — out with a customer, not assignable
     * (switched off, or the licence has lapsed), holding a booking that has not started, or free.
     */
    private static function availabilitySql(string $today): string
    {
        return "CASE WHEN EXISTS(SELECT 1 FROM rental_agreements r WHERE r.driver_id=drivers.driver_id AND r.status='active') THEN 'on_trip'"
            . " WHEN drivers.status<>'active' OR drivers.license_expiry < :{$today} THEN 'unavailable'"
            . " WHEN EXISTS(SELECT 1 FROM rental_agreements r WHERE r.driver_id=drivers.driver_id AND r.status IN ('reserved','confirmed')) THEN 'booked'"
            . " ELSE 'free' END";
    }

    /** An open booking of the driver ($alias) that overlaps the dates bound to :request_start and :request_end. */
    private static function busySql(string $alias): string
    {
        return "EXISTS(SELECT 1 FROM rental_agreements r WHERE r.driver_id={$alias}.driver_id AND r.status IN ('reserved','confirmed','active') AND r.start_date < :request_end AND DATE_ADD(r.end_date,INTERVAL IF(r.end_date=r.start_date,1,0) DAY) > :request_start";
    }

    /** A one-day request runs to the next morning, the same way a one-day booking does. */
    private static function requestEnd(string $start, string $end): string
    {
        return $end === $start ? (new \DateTimeImmutable($end, new \DateTimeZone('Asia/Manila')))->modify('+1 day')->format('Y-m-d') : $end;
    }

    /**
     * The WHERE clause for the roster. $filters: removed (bool), search (words of a name, in any
     * order), license_fingerprint (an exact licence number, already hashed), state (one of
     * AVAILABILITY), free_from and free_to (dates the driver must be free and licensed for).
     *
     * @return array{string,array}
     */
    private static function rosterFilter(array $filters, string $today): array
    {
        $where = empty($filters['removed']) ? 'drivers.deleted_at IS NULL' : 'drivers.deleted_at IS NOT NULL';
        $params = [];
        $words = preg_split('/\s+/u', trim((string) ($filters['search'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words !== []) {
            $name = [];
            foreach (array_slice($words, 0, 6) as $n => $word) {
                $name[] = "drivers.full_name LIKE :word{$n}";
                $params["word{$n}"] = '%' . addcslashes($word, '\\%_') . '%'; // % and _ are searched for as typed
            }
            $match = implode(' AND ', $name);
            if (!empty($filters['license_fingerprint'])) {
                $match = "({$match}) OR drivers.license_number_fingerprint = :fingerprint";
                $params['fingerprint'] = $filters['license_fingerprint'];
            }
            $where .= " AND ({$match})";
        }
        if (empty($filters['removed']) && in_array($filters['state'] ?? '', self::AVAILABILITY, true)) {
            $where .= ' AND ' . self::availabilitySql('state_today') . ' = :state';
            $params['state_today'] = $today;
            $params['state'] = $filters['state'];
        }
        if (!empty($filters['free_from']) && !empty($filters['free_to'])) {
            $where .= " AND drivers.status='active' AND drivers.license_expiry >= :licensed_until AND NOT " . self::busySql('drivers') . ')';
            $params['licensed_until'] = max($filters['free_to'], $today);
            $params['request_start'] = $filters['free_from'];
            $params['request_end'] = self::requestEnd($filters['free_from'], $filters['free_to']);
        }
        return [$where, $params];
    }

    public function rosterCount(array $filters, string $today): int
    {
        [$where, $params] = self::rosterFilter($filters, $today);
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM drivers WHERE ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** One page of the roster, each driver with today's availability, trips finished and whether they can sign in. */
    public function roster(array $filters, string $today, int $limit, int $offset, string $sort = 'name'): array
    {
        [$where, $params] = self::rosterFilter($filters, $today);
        $order = ['name' => 'drivers.full_name, drivers.driver_id', 'expiry' => 'drivers.license_expiry, drivers.full_name', 'trips' => 'trips_done, drivers.full_name'][$sort] ?? 'drivers.full_name, drivers.driver_id';
        $sql = 'SELECT drivers.*, ' . self::availabilitySql('today') . ' AS availability,'
            . " (SELECT COUNT(*) FROM rental_agreements r WHERE r.driver_id=drivers.driver_id AND r.status IN ('returned','completed')) AS trips_done,"
            . ' EXISTS(SELECT 1 FROM users u WHERE u.driver_id=drivers.driver_id AND u.is_active=1 AND u.deleted_at IS NULL) AS has_account'
            . ' FROM drivers WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params + ['today' => $today]);
        return $stmt->fetchAll();
    }

    /** @return array<string,int> how many current drivers stand in each availability today, plus 'removed' */
    public function rosterTally(string $today): array
    {
        $tally = array_fill_keys(self::AVAILABILITY, 0);
        $stmt = $this->db->prepare('SELECT ' . self::availabilitySql('today') . ' AS availability, COUNT(*) AS total FROM drivers WHERE drivers.deleted_at IS NULL GROUP BY availability');
        $stmt->execute(['today' => $today]);
        foreach ($stmt->fetchAll() as $row) $tally[(string) $row['availability']] = (int) $row['total'];
        $tally['removed'] = (int) $this->db->query('SELECT COUNT(*) FROM drivers WHERE deleted_at IS NOT NULL')->fetchColumn();
        return $tally;
    }

    /** @param list<int> $driverIds @return array<int,list<array>> each driver's open bookings: the one under way first, then by start date */
    public function openBookings(array $driverIds): array
    {
        if ($driverIds === []) return [];
        $ids = implode(',', array_map('intval', $driverIds));
        $rows = $this->db->query("SELECT agreement_id, driver_id, status, start_date, end_date FROM rental_agreements WHERE driver_id IN ({$ids}) AND status IN ('reserved','confirmed','active') ORDER BY (status='active') DESC, start_date, agreement_id")->fetchAll();
        $byDriver = [];
        foreach ($rows as $row) $byDriver[(int) $row['driver_id']][] = $row;
        return $byDriver;
    }

    /** @param list<int> $driverIds @return array<int,string> each driver's main phone number, still encrypted */
    public function mainPhones(array $driverIds): array
    {
        if ($driverIds === []) return [];
        $ids = implode(',', array_map('intval', $driverIds));
        $rows = $this->db->query("SELECT driver_id, contact_ciphertext FROM driver_contacts WHERE driver_id IN ({$ids}) AND contact_type='phone' AND deleted_at IS NULL ORDER BY is_primary DESC, contact_id")->fetchAll();
        $phones = [];
        foreach ($rows as $row) $phones[(int) $row['driver_id']] ??= (string) $row['contact_ciphertext'];
        return $phones;
    }

    /** Current drivers; with $includeDeleted removed ones too; with $removedOnly only the removed ones. @return array{string,array} */
    private static function listFilter(?string $search, bool $includeDeleted, bool $removedOnly): array
    {
        $where = $removedOnly ? 'deleted_at IS NOT NULL' : ($includeDeleted ? '1=1' : 'deleted_at IS NULL');
        $params = [];
        if ($search !== null && trim($search) !== '') {
            $where .= ' AND full_name LIKE :search';
            $params['search'] = '%' . trim($search) . '%';
        }
        return [$where, $params];
    }

    public function list(?string $search = null, bool $includeDeleted = false, bool $removedOnly = false, ?int $limit = null, int $offset = 0): array
    {
        [$where, $params] = self::listFilter($search, $includeDeleted, $removedOnly);
        $sql = "SELECT drivers.*, (SELECT COUNT(*) FROM rental_agreements r WHERE r.driver_id = drivers.driver_id AND r.status IN ('reserved','confirmed','active')) AS open_assignments FROM drivers WHERE " . $where . ' ORDER BY full_name, driver_id';
        if ($limit !== null) $sql .= ' LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset);
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

    public function availableForAssignment(string $manilaDate, string $start, string $end, ?int $excludeAgreementId = null): array
    {
        $sql = "SELECT d.driver_id, d.full_name, d.license_expiry FROM drivers d WHERE d.status='active' AND d.deleted_at IS NULL AND d.license_expiry >= :today AND NOT " . self::busySql('d');
        $params = ['today' => $manilaDate, 'request_start' => $start, 'request_end' => self::requestEnd($start, $end)];
        if ($excludeAgreementId !== null) { $sql .= " AND r.agreement_id<>:exclude"; $params['exclude'] = $excludeAgreementId; }
        $sql .= ") ORDER BY d.full_name, d.driver_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
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
        $stmt = $this->db->prepare('INSERT INTO status_logs (subject,driver_id,old_status,new_status,actor_user_id) VALUES (\'driver\',:driver,:old,:new,:actor)');
        $stmt->execute(['driver'=>$driverId,'old'=>$old,'new'=>$new,'actor'=>$actor]);
    }

    public function statusHistory(int $driverId): array
    {
        $stmt = $this->db->prepare('SELECT h.*,u.email AS actor_email FROM status_logs h JOIN users u ON u.id=h.actor_user_id WHERE h.driver_id=:id AND h.subject=\'driver\' ORDER BY h.created_at,h.status_log_id');
        $stmt->execute(['id'=>$driverId]);
        return $stmt->fetchAll();
    }

    /**
     * A driver's own chauffeur bookings, newest first: what they need to turn up, and nothing about
     * money or the customer beyond a name.
     */
    public function trips(int $driverId): array
    {
        $stmt = $this->db->prepare("SELECT a.agreement_id, a.booking_reference, a.status, a.start_date, a.end_date, a.scheduled_pickup_at, a.scheduled_return_at, c.full_name AS customer_name, v.plate_number, v.make, v.model, v.color FROM rental_agreements a JOIN customers c ON c.customer_id = a.customer_id JOIN vehicles v ON v.vehicle_id = a.vehicle_id WHERE a.driver_id = :driver AND a.rental_type = 'chauffeur' AND a.status NOT IN ('cancelled','no_show') ORDER BY a.start_date DESC, a.agreement_id DESC LIMIT 100");
        $stmt->execute(['driver' => $driverId]);
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
