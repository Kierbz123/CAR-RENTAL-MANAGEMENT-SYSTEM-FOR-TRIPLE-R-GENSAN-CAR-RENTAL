<?php
declare(strict_types=1);

namespace TripleR\Services\Telegram;

/**
 * A refusal or failure from Telegram's Bot API.
 * The message is always one of this class's own fixed sentences: never the request address
 * (it contains the bot token) and never Telegram's raw response.
 */
final class TelegramApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?int $retryAfterSeconds = null,
        public readonly string $reason = 'error',
    ) {
        parent::__construct($message);
    }

    /** Telegram could not be reached, or answered with a temporary error. */
    public function isTemporary(): bool
    {
        return in_array($this->reason, ['network', 'rate_limited', 'server'], true);
    }

    /** The customer blocked the bot, deleted their account, or the chat no longer exists. */
    public function isChatUnavailable(): bool
    {
        return $this->reason === 'chat_unavailable';
    }
}
