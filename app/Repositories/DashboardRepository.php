<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

/**
 * Read-only counts and short lists for the staff workspace page.
 * Nothing here writes, locks rows, or decrypts customer or driver PII.
 */
final class DashboardRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,int> vehicle count per current status, retired and deleted vehicles excluded */
    public function vehicleCounts(): array
    {
        $rows = $this->db->query("SELECT current_status, COUNT(*) AS total FROM vehicles WHERE deleted_at IS NULL AND current_status <> 'retired' GROUP BY current_status")->fetchAll();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['current_status']] = (int) $row['total'];
        }
        return $counts;
    }

    /** @return array<string,int> */
    public function rentalCounts(string $today): array
    {
        $q = $this->db->prepare(
            "SELECT
                COALESCE(SUM(status = 'active'), 0) AS active,
                COALESCE(SUM(status IN ('reserved','confirmed') AND start_date = :today1), 0) AS pickups_today,
                COALESCE(SUM(status IN ('reserved','confirmed') AND start_date < :today4), 0) AS late_pickups,
                COALESCE(SUM(status = 'active' AND end_date = :today2), 0) AS returns_today,
                COALESCE(SUM(status = 'active' AND end_date < :today3), 0) AS overdue,
                COALESCE(SUM(status = 'reserved'), 0) AS awaiting_confirmation,
                COALESCE(SUM(status = 'returned'), 0) AS awaiting_completion,
                COALESCE(SUM(rental_type = 'chauffeur' AND status = 'reserved' AND driver_id IS NULL), 0) AS needs_driver
             FROM rental_agreements"
        );
        $q->execute(['today1' => $today, 'today2' => $today, 'today3' => $today, 'today4' => $today]);
        return array_map('intval', $q->fetch() ?: []);
    }

    /** Pickups due today or missed on an earlier day, plus active rentals due back today or already overdue. */
    public function todaySchedule(string $today, int $limit = 12): array
    {
        $q = $this->db->prepare(
            "SELECT r.agreement_id, r.status, r.rental_type, r.start_date, r.end_date,
                    r.scheduled_pickup_at, r.scheduled_return_at,
                    c.full_name AS customer_name, v.plate_number, v.make, v.model,
                    CASE WHEN r.status = 'active' THEN 'return' ELSE 'pickup' END AS movement
             FROM rental_agreements r
             JOIN customers c ON c.customer_id = r.customer_id
             JOIN vehicles v ON v.vehicle_id = r.vehicle_id
             WHERE (r.status IN ('reserved','confirmed') AND r.start_date <= :today1)
                OR (r.status = 'active' AND r.end_date <= :today2)
             ORDER BY (CASE WHEN r.status = 'active' THEN r.end_date ELSE r.start_date END < :today3) DESC,
                      COALESCE(CASE WHEN r.status = 'active' THEN r.scheduled_return_at ELSE r.scheduled_pickup_at END, r.start_date),
                      r.agreement_id
             LIMIT " . max(1, min(50, $limit))
        );
        $q->execute(['today1' => $today, 'today2' => $today, 'today3' => $today]);
        return $q->fetchAll();
    }
}
