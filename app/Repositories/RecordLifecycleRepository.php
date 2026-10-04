<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

/** The append-only record of customers and drivers being removed and restored (migration 025). */
final class RecordLifecycleRepository
{
    public function __construct(private readonly PDO $db) {}

    /** $subject is 'customer' or 'driver'; $action is 'removed' or 'restored'. */
    public function append(string $subject, int $id, string $action, ?string $reason, int $actor): void
    {
        $column = ['customer' => 'customer_id', 'driver' => 'driver_id'][$subject] ?? throw new \InvalidArgumentException('Unknown record type.');
        $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
        $this->db->prepare("INSERT INTO record_lifecycle_logs (subject, {$column}, action, reason, actor_user_id) VALUES (:subject, :id, :action, :reason, :actor)")
            ->execute(['subject' => $subject, 'id' => $id, 'action' => $action, 'reason' => $reason, 'actor' => $actor]);
    }

    /** Newest first, with who did it. */
    public function history(string $subject, int $id): array
    {
        $column = ['customer' => 'customer_id', 'driver' => 'driver_id'][$subject] ?? throw new \InvalidArgumentException('Unknown record type.');
        $statement = $this->db->prepare("SELECT l.action, l.reason, l.created_at, u.email AS actor_email FROM record_lifecycle_logs l JOIN users u ON u.id = l.actor_user_id WHERE l.{$column} = :id ORDER BY l.created_at DESC, l.log_id DESC");
        $statement->execute(['id' => $id]);
        return $statement->fetchAll();
    }
}
