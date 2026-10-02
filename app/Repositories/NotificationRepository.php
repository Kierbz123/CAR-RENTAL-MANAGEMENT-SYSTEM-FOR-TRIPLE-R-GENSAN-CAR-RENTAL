<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class NotificationRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function begin(): void
    {
        $this->db->beginTransaction();
    }

    public function commit(): void
    {
        $this->db->commit();
    }

    public function rollback(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }

    public function findByIdempotencyKey(string $key): ?int
    {
        $statement = $this->db->prepare('SELECT id FROM notifications WHERE idempotency_key = :key LIMIT 1');
        $statement->execute(['key' => $key]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function lockDailyBudget(string $phone, string $budgetDate): int
    {
        // The day's count for one phone is a rate_counters row keyed "<phone>|<date>".
        $insert = $this->db->prepare("INSERT IGNORE INTO rate_counters (scope, counter_key, window_started_at, hits) VALUES ('message_daily', :key, :day, 0)");
        $insert->execute(['key' => self::dailyBudgetKey($phone, $budgetDate), 'day' => $budgetDate . ' 00:00:00']);
        $select = $this->db->prepare("SELECT hits FROM rate_counters WHERE scope = 'message_daily' AND counter_key = :key FOR UPDATE");
        $select->execute(['key' => self::dailyBudgetKey($phone, $budgetDate)]);
        return (int) $select->fetchColumn();
    }

    public function incrementDailyBudget(string $phone, string $budgetDate): void
    {
        $statement = $this->db->prepare("UPDATE rate_counters SET hits = hits + 1 WHERE scope = 'message_daily' AND counter_key = :key");
        $statement->execute(['key' => self::dailyBudgetKey($phone, $budgetDate)]);
    }

    private static function dailyBudgetKey(string $phone, string $budgetDate): string
    {
        return $phone . '|' . $budgetDate;
    }

    public function insertQueued(array $message, int $maxAttempts): int
    {
        $statement = $this->db->prepare('INSERT INTO notifications (recipient_phone, customer_id, telegram_link_id, idempotency_key, channel, template_key, rendered_message, message_class, provider, status, priority, max_attempts) VALUES (:phone, :customer_id, :telegram_link_id, :idempotency_key, :channel, :template, :body, :class, :provider, \'queued\', :priority, :max_attempts)');
        $statement->execute([
            'phone' => $message['recipient_phone'],
            'customer_id' => $message['customer_id'] ?? null,
            'telegram_link_id' => $message['telegram_link_id'] ?? null,
            'channel' => $message['channel'] ?? 'sms',
            'idempotency_key' => $message['idempotency_key'],
            'template' => $message['template_key'],
            'body' => $message['encrypt_at_rest']
                ? (new \TripleR\Services\SmsMessageCipher())->encrypt($message['message'], \TripleR\Services\SmsMessageCipher::context($message['recipient_phone'], $message['template_key']))
                : $message['message'],
            'class' => $message['message_class'],
            'provider' => $message['provider'],
            'priority' => $message['priority'],
            'max_attempts' => $maxAttempts,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function insertSuppressedByPolicy(array $message): int
    {
        $statement=$this->db->prepare("INSERT INTO notifications (recipient_phone,customer_id,telegram_link_id,idempotency_key,channel,template_key,rendered_message,message_class,provider,status,priority,max_attempts,last_error) VALUES (:phone,:customer_id,:telegram_link_id,:idempotency_key,:channel,:template,:body,:class,:provider,'suppressed_by_policy',:priority,0,:reason)");
        $body=$message['encrypt_at_rest']?(new \TripleR\Services\SmsMessageCipher())->encrypt($message['message'],\TripleR\Services\SmsMessageCipher::context($message['recipient_phone'],$message['template_key'])):$message['message'];
        $statement->execute(['phone'=>$message['recipient_phone'],'customer_id'=>$message['customer_id']??null,'telegram_link_id'=>$message['telegram_link_id']??null,'channel'=>$message['channel']??'sms','idempotency_key'=>$message['idempotency_key'],'template'=>$message['template_key'],'body'=>$body,'class'=>$message['message_class'],'provider'=>$message['provider'],'priority'=>$message['priority'],'reason'=>substr((string)$message['suppression_reason'],0,512)]);
        return (int)$this->db->lastInsertId();
    }

    public function claimBatch(int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $this->db->beginTransaction();
        try {
            $select = $this->db->query("SELECT id, recipient_phone, channel, telegram_link_id, template_key, rendered_message, message_class, provider, priority, attempt_count, max_attempts FROM notifications WHERE status = 'queued' AND next_attempt_at <= UTC_TIMESTAMP() ORDER BY CASE priority WHEN 'high' THEN 0 ELSE 1 END, created_at ASC LIMIT {$limit} FOR UPDATE SKIP LOCKED");
            $rows = $select->fetchAll();
            $claim = $this->db->prepare("UPDATE notifications SET status = 'sending', claim_token = :token, claimed_at = UTC_TIMESTAMP(), attempt_count = attempt_count + 1 WHERE id = :id AND status = 'queued'");
            $claimed = [];
            foreach ($rows as $row) {
                $token = bin2hex(random_bytes(18));
                $claim->execute(['token' => $token, 'id' => $row['id']]);
                if ($claim->rowCount() !== 1) {
                    continue;
                }
                $row['claim_token'] = $token;
                $row['attempt_count'] = (int) $row['attempt_count'] + 1;
                $claimed[] = $row;
            }
            $this->db->commit();
            return $claimed;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    public function hasDueEncryptedQueued(): bool
    {
        $statement = $this->db->query("SELECT 1 FROM notifications WHERE status = 'queued' AND next_attempt_at <= UTC_TIMESTAMP() AND rendered_message LIKE 'smsenc:v1:%' LIMIT 1");
        return $statement->fetchColumn() !== false;
    }

    public function hasDueQueuedOnChannel(string $channel): bool
    {
        $statement = $this->db->prepare("SELECT 1 FROM notifications WHERE status = 'queued' AND next_attempt_at <= UTC_TIMESTAMP() AND channel = :channel LIMIT 1");
        $statement->execute(['channel' => $channel]);
        return $statement->fetchColumn() !== false;
    }

    /**
     * Hands a claimed Telegram row to the SMS route after its connection ended. The Telegram
     * attempt is not counted against the row, and telegram_link_id stays as the record of how
     * it was first addressed.
     */
    public function rerouteToSms(int $id, string $claimToken, string $smsProvider, string $note): bool
    {
        $statement = $this->db->prepare("UPDATE notifications SET channel = 'sms', provider = :provider, status = 'queued', attempt_count = attempt_count - 1, next_attempt_at = UTC_TIMESTAMP(), last_error = :note, claim_token = NULL, claimed_at = NULL WHERE id = :id AND status = 'sending' AND claim_token = :token AND channel = 'telegram' AND attempt_count > 0");
        $statement->execute(['provider' => $smsProvider, 'note' => substr($note, 0, 512), 'id' => $id, 'token' => $claimToken]);
        return $statement->rowCount() === 1;
    }

    public function markSent(int $id, string $claimToken, string $providerMessageId, string $providerStatus): bool
    {
        $statement = $this->db->prepare("UPDATE notifications SET status = 'sent', provider_message_id = :message_id, provider_status = :provider_status, rendered_message = IF(LEFT(template_key,11)='magic_link.', '', rendered_message), sent_at = UTC_TIMESTAMP(), claim_token = NULL, claimed_at = NULL, last_error = NULL WHERE id = :id AND status = 'sending' AND claim_token = :token");
        $statement->execute(['id' => $id, 'token' => $claimToken, 'message_id' => substr($providerMessageId, 0, 191), 'provider_status' => substr($providerStatus, 0, 80)]);
        return $statement->rowCount() === 1;
    }

    public function markSuppressedByPolicy(int $id,string $claimToken,string $reason): bool
    {
        $statement=$this->db->prepare("UPDATE notifications SET status='suppressed_by_policy',last_error=:reason,claim_token=NULL,claimed_at=NULL WHERE id=:id AND status='sending' AND claim_token=:token");
        $statement->execute(['id'=>$id,'token'=>$claimToken,'reason'=>substr($reason,0,512)]);return $statement->rowCount()===1;
    }

    public function markFailure(int $id, string $claimToken, string $error, bool $retry, int $delaySeconds, bool $preserveEncrypted = false): bool
    {
        $nextAttempt = gmdate('Y-m-d H:i:s', time() + max(1, $delaySeconds));
        $statement = $this->db->prepare("UPDATE notifications SET status = IF(:retry = 1 AND attempt_count < max_attempts, 'queued', 'failed'), rendered_message = IF(LEFT(template_key,11)='magic_link.' AND (:redact = 1 OR attempt_count >= max_attempts) AND rendered_message LIKE 'smsenc:v1:%' AND :preserve = 0, '', rendered_message), retry_count = retry_count + IF(:retry_count = 1 AND attempt_count < max_attempts, 1, 0), next_attempt_at = IF(:next_retry = 1 AND attempt_count < max_attempts, :next_attempt, next_attempt_at), last_error = :error, claim_token = NULL, claimed_at = NULL WHERE id = :id AND status = 'sending' AND claim_token = :token");
        $statement->execute([
            'retry' => $retry ? 1 : 0,
            'retry_count' => $retry ? 1 : 0,
            'next_retry' => $retry ? 1 : 0,
            'redact' => $retry ? 0 : 1,
            'preserve' => $preserveEncrypted ? 1 : 0,
            'next_attempt' => $nextAttempt,
            'error' => substr($error, 0, 512),
            'id' => $id,
            'token' => $claimToken,
        ]);
        return $statement->rowCount() === 1;
    }

    public function recordDelivery(string $providerMessageId, string $status, ?string $error): bool
    {
        $normalized = strtolower(trim($status));
        $terminalFailure = in_array($normalized, ['failed', 'undelivered', 'rejected', 'error'], true);
        $statement = $this->db->prepare("UPDATE notifications SET provider_status = :status, status = IF(:failed = 1 AND status = 'sent', 'failed', status), last_error = IF(:failed_error = 1, :error, last_error) WHERE provider_message_id = :message_id");
        $statement->execute([
            'status' => substr($normalized, 0, 80),
            'failed' => $terminalFailure ? 1 : 0,
            'failed_error' => $terminalFailure && $error !== null ? 1 : 0,
            'error' => substr((string) $error, 0, 512),
            'message_id' => $providerMessageId,
        ]);
        return $statement->rowCount() > 0;
    }

    public function history(int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $statement = $this->db->query("SELECT id, recipient_phone, channel, template_key, rendered_message, message_class, provider, status, priority, provider_status, attempt_count, retry_count, last_error, created_at, sent_at FROM notifications ORDER BY created_at DESC, id DESC LIMIT {$limit}");
        return $statement->fetchAll();
    }

    public function monthlySentCount(): int
    {
        $statement = $this->db->query("SELECT COUNT(*) FROM notifications WHERE sent_at >= DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-01 00:00:00')");
        return (int) $statement->fetchColumn();
    }
}
