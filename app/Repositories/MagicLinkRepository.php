<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class MagicLinkRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(string $tokenHash, string $purpose, ?int $bookingId, string $expiresAt, int $bookingIssueLimit): int
    {
        $this->db->beginTransaction();
        try {
            if ($bookingId !== null) {
                $this->reserveBookingIssue($bookingId, $bookingIssueLimit);
            }
            $statement = $this->db->prepare('INSERT INTO booking_access_tokens (token_hash, purpose, booking_id, expires_at) VALUES (:hash, :purpose, :booking_id, :expires_at)');
            $statement->execute(['hash' => $tokenHash, 'purpose' => $purpose, 'booking_id' => $bookingId, 'expires_at' => $expiresAt]);
            $id = (int) $this->db->lastInsertId();
            $this->db->commit();
            return $id;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function attachBooking(int $tokenId, int $bookingId, string $holdExpiresAt, int $bookingIssueLimit): bool
    {
        $this->db->beginTransaction();
        try {
            $this->reserveBookingIssue($bookingId, $bookingIssueLimit);
            $statement = $this->db->prepare('UPDATE booking_access_tokens SET booking_id = :booking_id, expires_at = LEAST(expires_at, :hold_expires_at) WHERE id = :id AND booking_id IS NULL AND used_at IS NULL');
            $statement->execute(['booking_id' => $bookingId, 'hold_expires_at' => $holdExpiresAt, 'id' => $tokenId]);
            if ($statement->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }
            $this->db->commit();
            return true;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function sessionContext(int $tokenId, string $expectedPurpose): ?array
    {
        $statement = $this->db->prepare('SELECT id, purpose, booking_id, expires_at, (expires_at > UTC_TIMESTAMP(6)) AS is_unexpired FROM booking_access_tokens WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $tokenId]);
        $token = $statement->fetch();
        if ($token === false || (int) $token['is_unexpired'] !== 1 || !hash_equals((string) $token['purpose'], $expectedPurpose)) {
            return null;
        }
        return ['token_id' => (int) $token['id'], 'purpose' => (string) $token['purpose'], 'booking_id' => $token['booking_id'] === null ? null : (int) $token['booking_id'], 'expires_at' => (string) $token['expires_at']];
    }

    public function consume(string $tokenHash, string $expectedPurpose, string $ip, string $userAgent): ?array
    {
        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare('SELECT id, purpose, booking_id, expires_at, used_at, (expires_at > UTC_TIMESTAMP(6)) AS is_unexpired FROM booking_access_tokens WHERE token_hash = :hash FOR UPDATE');
            $select->execute(['hash' => $tokenHash]);
            $token = $select->fetch();
            if ($token === false) {
                $this->db->commit();
                return null;
            }

            $valid = hash_equals((string) $token['purpose'], $expectedPurpose)
                && $token['used_at'] === null
                && (int) $token['is_unexpired'] === 1;
            $action = $valid ? 'magic_link_redeem' : 'magic_link_redeem_rejected';
            $this->recordUsage((int) $token['id'], $ip, $userAgent, $action);
            if (!$valid) {
                $this->db->commit();
                return null;
            }

            $update = $this->db->prepare('UPDATE booking_access_tokens SET used_at = UTC_TIMESTAMP(6) WHERE id = :id AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6) AND purpose = :purpose');
            $update->execute(['id' => $token['id'], 'purpose' => $expectedPurpose]);
            if ($update->rowCount() !== 1) {
                $this->db->commit();
                return null;
            }
            $this->db->commit();
            return ['id' => (int) $token['id'], 'purpose' => (string) $token['purpose'], 'booking_id' => $token['booking_id'] === null ? null : (int) $token['booking_id'], 'expires_at' => (string) $token['expires_at']];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function invalidate(int $tokenId): void
    {
        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare('SELECT booking_id, used_at FROM booking_access_tokens WHERE id = :id FOR UPDATE');
            $select->execute(['id' => $tokenId]);
            $token = $select->fetch();
            if ($token === false || $token['used_at'] !== null) {
                $this->db->commit();
                return;
            }
            $statement = $this->db->prepare('UPDATE booking_access_tokens SET used_at = UTC_TIMESTAMP(6) WHERE id = :id AND used_at IS NULL');
            $statement->execute(['id' => $tokenId]);
            if ($statement->rowCount() === 1 && $token['booking_id'] !== null) {
                $release = $this->db->prepare("UPDATE rate_counters SET hits = IF(hits > 0, hits - 1, 0) WHERE scope = 'magic_link_booking' AND counter_key = :booking_id");
                $release->execute(['booking_id' => (string) $token['booking_id']]);
            }
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private function reserveBookingIssue(int $bookingId, int $limit): void
    {
        // The number of secure links issued for one booking is a rate_counters row keyed by the agreement id.
        $insert = $this->db->prepare("INSERT IGNORE INTO rate_counters (scope, counter_key, window_started_at, hits) VALUES ('magic_link_booking', :booking_id, UTC_TIMESTAMP(), 0)");
        $insert->execute(['booking_id' => (string) $bookingId]);
        $update = $this->db->prepare("UPDATE rate_counters SET hits = hits + 1 WHERE scope = 'magic_link_booking' AND counter_key = :booking_id AND hits < :limit");
        $update->execute(['booking_id' => (string) $bookingId, 'limit' => max(1, min(255, $limit))]);
        if ($update->rowCount() !== 1) {
            throw new \DomainException('The secure-link limit for this booking has been reached. Please contact the rental office.');
        }
    }

    /** Every attempt to open a secure link, accepted or refused, is a security event tied to its token. */
    private function recordUsage(int $tokenId, string $ip, string $userAgent, string $action): void
    {
        $statement = $this->db->prepare('INSERT INTO security_logs (token_id, event_type, ip_address, user_agent, created_at) VALUES (:token_id, :action, :ip, :user_agent, UTC_TIMESTAMP(6))');
        $statement->execute([
            'token_id' => $tokenId,
            'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null,
            'user_agent' => substr($userAgent, 0, 512),
            'action' => $action,
        ]);
    }
}
