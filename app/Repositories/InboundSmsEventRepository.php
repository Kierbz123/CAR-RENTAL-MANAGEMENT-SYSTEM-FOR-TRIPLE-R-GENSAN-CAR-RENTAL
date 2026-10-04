<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;
use TripleR\Services\PhoneVault;

final class InboundSmsEventRepository
{
    /** Payload fields that carry the sender's number; they are masked before the payload is stored. */
    private const NUMBER_FIELDS = ['sender_number', 'from', 'number', 'sender', 'msisdn', 'mobile', 'phone'];

    private ?PhoneVault $vault = null;

    public function __construct(private readonly PDO $db)
    {
    }

    private function vault(): PhoneVault
    {
        return $this->vault ??= new PhoneVault();
    }

    /** The sender is stored sealed and masked (migration 026), and so is any number in the kept payload. */
    public function append(string $messageId, string $provider, string $rawPayload, string $sender, string $eventType, ?string $messageText): bool
    {
        $stored = $this->vault()->store($sender);
        $statement = $this->db->prepare('INSERT IGNORE INTO inbound_sms_events (provider_message_id, provider, raw_payload, received_at, sender_number, sender_ciphertext, sender_fingerprint, event_type, message_text) VALUES (:message_id, :provider, :payload, UTC_TIMESTAMP(6), :sender, :sender_cipher, :sender_fingerprint, :event_type, :message_text)');
        $statement->execute([
            'message_id' => $messageId,
            'provider' => substr($provider, 0, 40),
            'payload' => self::redactPayload($rawPayload),
            'sender' => $stored['masked'],
            'sender_cipher' => $stored['ciphertext'],
            'sender_fingerprint' => $stored['fingerprint'],
            'event_type' => substr($eventType, 0, 40),
            'message_text' => $messageText,
        ]);
        return $statement->rowCount() === 1;
    }

    /** True when this number has replied STOP. Rows written before migration 026 are matched by their plain number. */
    public function hasStop(string $phone): bool
    {
        $statement = $this->db->prepare("SELECT 1 FROM inbound_sms_events WHERE event_type = 'stop' AND (sender_fingerprint = :fingerprint OR (sender_fingerprint IS NULL AND sender_number = :phone)) LIMIT 1");
        $statement->execute(['fingerprint' => $this->vault()->fingerprint($phone), 'phone' => $phone]);
        return $statement->fetchColumn() !== false;
    }

    /** The provider's message as received, with every number field masked. A body that is not JSON is not kept. */
    public static function redactPayload(string $rawPayload): string
    {
        $data = json_decode($rawPayload, true);
        if (!is_array($data)) {
            return '{"redacted":"payload was not JSON and is not kept"}';
        }
        array_walk_recursive($data, static function (mixed &$value, int|string $key): void {
            if (is_scalar($value) && in_array(strtolower((string) $key), self::NUMBER_FIELDS, true)) {
                $value = PhoneVault::mask((string) $value);
            }
        });
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
