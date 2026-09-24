<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class SessionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(int $userId, string $phpSessionId, int $ttlSeconds, string $ip, string $userAgent): void
    {
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . max(300, $ttlSeconds) . ' seconds')
            ->format('Y-m-d H:i:s.u');
        $statement = $this->db->prepare('INSERT INTO sessions (user_id, session_hash, expires_at, ip_address, user_agent) VALUES (:user_id, :session_hash, :expires_at, :ip, :user_agent)');
        $statement->execute([
            'user_id' => $userId,
            'session_hash' => hash('sha256', $phpSessionId),
            'expires_at' => $expires,
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'user_agent' => substr($userAgent, 0, 512),
        ]);
    }

    public function findCurrent(string $phpSessionId): ?array
    {
        $hash = hash('sha256', $phpSessionId);
        $statement = $this->db->prepare('SELECT u.id, u.email, u.role, u.must_change_password, u.locked_at, s.id AS session_row_id FROM sessions s INNER JOIN users u ON u.id = s.user_id WHERE s.session_hash = :hash AND s.invalidated_at IS NULL AND s.expires_at > UTC_TIMESTAMP(6) AND u.is_active = 1 AND u.deleted_at IS NULL AND u.locked_at IS NULL LIMIT 1');
        $statement->execute(['hash' => $hash]);
        $user = $statement->fetch();
        if ($user === false) {
            return null;
        }
        $touch = $this->db->prepare('UPDATE sessions SET last_seen_at = UTC_TIMESTAMP(6) WHERE id = :id AND invalidated_at IS NULL AND expires_at > UTC_TIMESTAMP(6)');
        $touch->execute(['id' => $user['session_row_id']]);
        unset($user['session_row_id'], $user['locked_at']);
        $user['id'] = (int) $user['id'];
        $user['must_change_password'] = (bool) $user['must_change_password'];
        return $user;
    }

    public function invalidateCurrent(string $phpSessionId): void
    {
        $statement = $this->db->prepare('UPDATE sessions SET invalidated_at = UTC_TIMESTAMP(6) WHERE session_hash = :hash AND invalidated_at IS NULL');
        $statement->execute(['hash' => hash('sha256', $phpSessionId)]);
    }

    public function invalidateAllForUser(int $userId): void
    {
        $statement = $this->db->prepare('UPDATE sessions SET invalidated_at = UTC_TIMESTAMP(6) WHERE user_id = :user_id AND invalidated_at IS NULL');
        $statement->execute(['user_id' => $userId]);
    }

    public function invalidateOthers(int $userId, string $currentPhpSessionId): void
    {
        $statement = $this->db->prepare('UPDATE sessions SET invalidated_at = UTC_TIMESTAMP(6) WHERE user_id = :user_id AND session_hash <> :current_hash AND invalidated_at IS NULL');
        $statement->execute(['user_id' => $userId, 'current_hash' => hash('sha256', $currentPhpSessionId)]);
    }

    public function forUser(int $userId): array
    {
        $statement = $this->db->prepare('SELECT id, created_at, last_seen_at, expires_at, ip_address, user_agent FROM sessions WHERE user_id = :user_id AND invalidated_at IS NULL AND expires_at > UTC_TIMESTAMP(6) ORDER BY created_at DESC LIMIT 200');
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchAll();
    }

    public function invalidateById(int $userId, int $sessionId): bool
    {
        $statement = $this->db->prepare('UPDATE sessions SET invalidated_at = UTC_TIMESTAMP(6) WHERE id = :id AND user_id = :user_id AND invalidated_at IS NULL');
        $statement->execute(['id' => $sessionId, 'user_id' => $userId]);
        return $statement->rowCount() === 1;
    }

    public function sweepExpired(): int
    {
        $statement = $this->db->prepare('UPDATE sessions SET invalidated_at = UTC_TIMESTAMP(6) WHERE invalidated_at IS NULL AND expires_at <= UTC_TIMESTAMP(6)');
        $statement->execute();
        return $statement->rowCount();
    }
}
