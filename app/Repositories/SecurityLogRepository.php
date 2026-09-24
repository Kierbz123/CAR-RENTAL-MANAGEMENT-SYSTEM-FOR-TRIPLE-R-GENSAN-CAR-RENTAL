<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class SecurityLogRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function append(string $eventType, ?int $subjectUserId, ?string $email, string $ip, string $userAgent, ?int $actorUserId = null): void
    {
        $normalizedEmail = $email === null ? null : mb_strtolower(trim($email));
        $statement = $this->db->prepare('INSERT INTO security_logs (actor_user_id, subject_user_id, email_hash, event_type, ip_address, user_agent) VALUES (:actor_user_id, :subject_user_id, :email_hash, :event_type, :ip, :user_agent)');
        $statement->execute([
            'actor_user_id' => $actorUserId,
            'subject_user_id' => $subjectUserId,
            'email_hash' => $normalizedEmail === null || $normalizedEmail === '' ? null : hash('sha256', $normalizedEmail),
            'event_type' => substr($eventType, 0, 64),
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'user_agent' => substr($userAgent, 0, 512),
        ]);
    }
}
