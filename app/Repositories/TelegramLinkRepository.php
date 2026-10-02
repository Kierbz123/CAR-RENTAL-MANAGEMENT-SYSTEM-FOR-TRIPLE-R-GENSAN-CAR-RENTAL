<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

/** Queries for telegram_link_codes, customer_telegram_links and telegram_updates. */
final class TelegramLinkRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /* ------------------------------ connection codes ------------------------------ */

    /** Only the newest code for a customer is usable: earlier unused codes stop working now. */
    public function expireOpenCodes(int $customerId): void
    {
        $statement = $this->db->prepare('UPDATE telegram_link_codes SET expires_at = UTC_TIMESTAMP(6) WHERE customer_id = :customer AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6)');
        $statement->execute(['customer' => $customerId]);
    }

    public function insertCode(int $customerId, string $codeHash, int $ttlSeconds, ?int $userId): int
    {
        $statement = $this->db->prepare('INSERT INTO telegram_link_codes (customer_id, code_hash, expires_at, created_by_user_id) VALUES (:customer, :hash, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL :ttl SECOND), :user)');
        $statement->bindValue('customer', $customerId, PDO::PARAM_INT);
        $statement->bindValue('hash', $codeHash);
        $statement->bindValue('ttl', $ttlSeconds, PDO::PARAM_INT);
        $statement->bindValue('user', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->execute();
        return (int) $this->db->lastInsertId();
    }

    /** @return array{code_id:int,customer_id:int,usable:int}|null */
    public function findCode(string $codeHash, bool $lock = false): ?array
    {
        $statement = $this->db->prepare('SELECT code_id, customer_id, (used_at IS NULL AND expires_at > UTC_TIMESTAMP(6)) AS usable FROM telegram_link_codes WHERE code_hash = :hash' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['hash' => $codeHash]);
        $row = $statement->fetch();
        return $row ? ['code_id' => (int) $row['code_id'], 'customer_id' => (int) $row['customer_id'], 'usable' => (int) $row['usable']] : null;
    }

    public function markCodeUsed(int $codeId): bool
    {
        $statement = $this->db->prepare('UPDATE telegram_link_codes SET used_at = UTC_TIMESTAMP(6) WHERE code_id = :id AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6)');
        $statement->execute(['id' => $codeId]);
        return $statement->rowCount() === 1;
    }

    /** When the customer's newest unused code stops working, or null when none is open. */
    public function openCodeExpiry(int $customerId): ?string
    {
        $statement = $this->db->prepare('SELECT MAX(expires_at) FROM telegram_link_codes WHERE customer_id = :customer AND used_at IS NULL AND expires_at > UTC_TIMESTAMP(6)');
        $statement->execute(['customer' => $customerId]);
        $value = $statement->fetchColumn();
        return is_string($value) ? $value : null;
    }

    /* -------------------------------- connections -------------------------------- */

    public function find(int $linkId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM customer_telegram_links WHERE link_id = :id');
        $statement->execute(['id' => $linkId]);
        return $statement->fetch() ?: null;
    }

    public function activeForCustomer(int $customerId, bool $lock = false): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM customer_telegram_links WHERE active_customer_id = :customer' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['customer' => $customerId]);
        return $statement->fetch() ?: null;
    }

    public function activeForChat(string $chatFingerprint, bool $lock = false): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM customer_telegram_links WHERE active_chat_fingerprint = :fingerprint' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['fingerprint' => $chatFingerprint]);
        return $statement->fetch() ?: null;
    }

    public function latestForCustomer(int $customerId): ?array
    {
        $statement = $this->db->prepare('SELECT l.*, u.email AS revoked_by_email FROM customer_telegram_links l LEFT JOIN users u ON u.id = l.revoked_by_user_id WHERE l.customer_id = :customer ORDER BY l.link_id DESC LIMIT 1');
        $statement->execute(['customer' => $customerId]);
        return $statement->fetch() ?: null;
    }

    public function insertLink(int $customerId, string $chatCiphertext, string $chatFingerprint, ?int $codeId): int
    {
        $statement = $this->db->prepare('INSERT INTO customer_telegram_links (customer_id, chat_id_ciphertext, chat_fingerprint, code_id) VALUES (:customer, :cipher, :fingerprint, :code)');
        $statement->bindValue('customer', $customerId, PDO::PARAM_INT);
        $statement->bindValue('cipher', $chatCiphertext, PDO::PARAM_LOB);
        $statement->bindValue('fingerprint', $chatFingerprint);
        $statement->bindValue('code', $codeId, $codeId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->execute();
        return (int) $this->db->lastInsertId();
    }

    /** Ends an active connection. A connection that is already ended is left exactly as it is. */
    public function revoke(int $linkId, string $reason, ?int $userId = null): bool
    {
        $statement = $this->db->prepare("UPDATE customer_telegram_links SET link_status = 'revoked', revoked_at = UTC_TIMESTAMP(6), revoked_reason = :reason, revoked_by_user_id = :user WHERE link_id = :id AND link_status = 'active'");
        $statement->bindValue('reason', $reason);
        $statement->bindValue('user', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue('id', $linkId, PDO::PARAM_INT);
        $statement->execute();
        return $statement->rowCount() === 1;
    }

    /* ------------------------------ inbound bot updates ------------------------------ */

    /**
     * Records an update once. Returns false when this update_id was already applied.
     * An update whose earlier attempt failed, or was cut short more than a minute ago, may be
     * taken again. Telegram restarts its numbering after a week of silence, so a number last
     * seen more than a week ago is treated as a new update rather than a repeat.
     */
    public function recordUpdate(int $updateId, ?string $chatFingerprint, string $eventType): bool
    {
        $parameters = ['id' => $updateId, 'fingerprint' => $chatFingerprint, 'type' => substr($eventType, 0, 40)];
        $insert = $this->db->prepare('INSERT IGNORE INTO telegram_updates (update_id, chat_fingerprint, event_type) VALUES (:id, :fingerprint, :type)');
        $insert->execute($parameters);
        if ($insert->rowCount() === 1) {
            return true;
        }
        $reuse = $this->db->prepare("UPDATE telegram_updates SET chat_fingerprint = :fingerprint, event_type = :type, outcome = 'received', received_at = UTC_TIMESTAMP(6) WHERE update_id = :id AND (outcome = 'failed' OR (outcome = 'received' AND received_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 60 SECOND)) OR received_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 7 DAY))");
        $reuse->execute($parameters);
        return $reuse->rowCount() === 1;
    }

    public function setUpdateOutcome(int $updateId, string $outcome): void
    {
        $statement = $this->db->prepare('UPDATE telegram_updates SET outcome = :outcome WHERE update_id = :id');
        $statement->execute(['outcome' => substr($outcome, 0, 40), 'id' => $updateId]);
    }
}
