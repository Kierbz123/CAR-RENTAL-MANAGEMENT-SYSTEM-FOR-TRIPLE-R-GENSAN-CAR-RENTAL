<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class InboundSmsEventRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function append(string $messageId, string $provider, string $rawPayload, string $sender, string $eventType, ?string $messageText): bool
    {
        $statement = $this->db->prepare('INSERT IGNORE INTO inbound_sms_events (provider_message_id, provider, raw_payload, received_at, sender_number, event_type, message_text) VALUES (:message_id, :provider, :payload, UTC_TIMESTAMP(6), :sender, :event_type, :message_text)');
        $statement->execute([
            'message_id' => $messageId,
            'provider' => substr($provider, 0, 40),
            'payload' => $rawPayload,
            'sender' => $sender,
            'event_type' => substr($eventType, 0, 40),
            'message_text' => $messageText,
        ]);
        return $statement->rowCount() === 1;
    }

    public function hasStop(string $phone): bool
    {
        $statement = $this->db->prepare("SELECT 1 FROM inbound_sms_events WHERE sender_number = :phone AND event_type = 'stop' LIMIT 1");
        $statement->execute(['phone' => $phone]);
        return $statement->fetchColumn() !== false;
    }
}
