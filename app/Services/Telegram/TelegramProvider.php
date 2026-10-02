<?php
declare(strict_types=1);

namespace TripleR\Services\Telegram;

use TripleR\Services\Sms\SmsProviderException;
use TripleR\Services\Sms\SmsProviderInterface;

/**
 * Delivers a queued notification through the Telegram bot. The recipient is a chat id.
 *
 * Telegram gives bots no delivery receipt, so "accepted" means Telegram took the message.
 * A temporary problem is retried by the queue (a repeated booking update costs nothing and
 * is harmless); a blocked or closed chat is reported as TelegramChatUnavailableException so
 * the queue can end the connection and fall back.
 */
final class TelegramProvider implements SmsProviderInterface
{
    public function __construct(private readonly TelegramBotClient $client)
    {
    }

    public function send(string $recipient, string $message, string $priority): array
    {
        try {
            $sent = $this->client->sendMessage($recipient, $message);
        } catch (TelegramApiException $error) {
            if ($error->isChatUnavailable()) {
                throw new TelegramChatUnavailableException($error->getMessage());
            }
            throw new SmsProviderException($error->getMessage(), $error->isTemporary());
        }
        return ['message_id' => (string) $sent['message_id'], 'status' => 'accepted'];
    }
}
