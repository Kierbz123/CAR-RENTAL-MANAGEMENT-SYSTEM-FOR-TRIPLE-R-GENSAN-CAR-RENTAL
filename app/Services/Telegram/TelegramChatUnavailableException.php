<?php
declare(strict_types=1);

namespace TripleR\Services\Telegram;

/** The customer blocked the bot or the chat is gone: end the connection, do not retry. */
final class TelegramChatUnavailableException extends \RuntimeException
{
}
