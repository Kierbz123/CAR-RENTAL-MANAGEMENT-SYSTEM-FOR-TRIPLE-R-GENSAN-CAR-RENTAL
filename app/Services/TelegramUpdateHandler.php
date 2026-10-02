<?php
declare(strict_types=1);

namespace TripleR\Services;

use TripleR\Repositories\TelegramLinkRepository;
use TripleR\Services\Telegram\TelegramBotClient;
use TripleR\Support\SiteProfile;

/**
 * Applies one update from the bot: connect with a code, /stop, /help, or a fixed reply.
 *
 * Only private chats are answered. Each update is recorded once by Telegram's update_id, so a
 * repeated delivery changes nothing. The text a customer sends is never stored or logged.
 */
final class TelegramUpdateHandler
{
    public function __construct(
        private readonly TelegramLinkRepository $updates,
        private readonly TelegramLinkService $links,
        private readonly TelegramBotClient $client,
    ) {
    }

    /** @return string what was done, as stored in telegram_updates.outcome */
    public function handle(array $update): string
    {
        $updateId = $update['update_id'] ?? null;
        if (!is_int($updateId) || $updateId < 0) {
            return 'malformed';
        }
        $membership = is_array($update['my_chat_member'] ?? null) ? $update['my_chat_member'] : null;
        $message = is_array($update['message'] ?? null) ? $update['message'] : null;
        $chat = ($message ?? $membership)['chat'] ?? null;
        $chatId = is_array($chat) && (is_int($chat['id'] ?? null) || is_string($chat['id'] ?? null)) ? (string) $chat['id'] : null;
        $private = is_array($chat) && ($chat['type'] ?? null) === 'private' && $chatId !== null && preg_match('/^-?\d{1,20}$/D', $chatId) === 1;
        $fingerprint = $private ? $this->links->chatFingerprint($chatId) : null;
        $type = $message !== null ? 'message' : ($membership !== null ? 'my_chat_member' : 'other');

        if (!$this->updates->recordUpdate($updateId, $fingerprint, $type)) {
            return 'duplicate';
        }
        $outcome = 'ignored';
        try {
            if ($private && $membership !== null) {
                $outcome = $this->membershipChanged($membership, $chatId);
            } elseif ($private && $message !== null) {
                $outcome = $this->messageReceived($message, $chatId);
            }
        } catch (\Throwable $error) {
            $this->updates->setUpdateOutcome($updateId, 'failed'); // Lets the same update be taken again.
            throw $error;
        }
        $this->updates->setUpdateOutcome($updateId, $outcome);
        return $outcome;
    }

    private function messageReceived(array $message, string $chatId): string
    {
        $text = is_string($message['text'] ?? null) ? trim($message['text']) : '';
        $office = $this->officeLine();

        if (preg_match('/^\/(start|stop|help)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$/is', $text, $command) === 1) {
            $argument = trim($command[2] ?? '');
            switch (strtolower($command[1])) {
                case 'stop':
                    // The connection is ended before the reply goes out.
                    $ended = $this->links->disconnectChat($chatId, 'customer_stop');
                    $this->reply($chatId, $ended
                        ? 'You are disconnected. Triple R Gensan Car Rental will not send booking updates to this chat any more.' . $office
                        : 'This chat is not connected to a Triple R Gensan Car Rental customer record, so there is nothing to stop.' . $office);
                    return $ended ? 'stopped' : 'not_connected';
                case 'help':
                    $this->reply($chatId, 'This chat sends booking updates from Triple R Gensan Car Rental: your secure booking link, confirmations and reminders. Send /stop to disconnect.' . $office);
                    return 'help';
                default:
                    return $argument === '' ? $this->explainCode($chatId) : $this->connect($chatId, $argument);
            }
        }
        // A bare code typed by hand works the same as the link.
        if (TelegramLinkService::normalizeCode($text) !== null) {
            return $this->connect($chatId, $text);
        }
        $this->reply($chatId, 'This chat only sends booking updates from Triple R Gensan Car Rental, so messages here are not read. Send /help for more.' . $office);
        return 'fixed_reply';
    }

    private function connect(string $chatId, string $code): string
    {
        $result = $this->links->redeem($code, $chatId);
        if ($result === 'connected') {
            $this->reply($chatId, 'You\'re connected to Triple R Gensan Car Rental. You\'ll get your booking updates here, including your secure booking link. Send /stop at any time to disconnect.');
            return 'connected';
        }
        if ($result === 'rate_limited') {
            $this->reply($chatId, 'Too many attempts. Please wait 15 minutes, or ask the rental office for a new code.' . $this->officeLine());
            return 'rate_limited';
        }
        $this->reply($chatId, 'That code is not valid. Codes work once and expire after a few minutes. Please ask the rental office for a new one.' . $this->officeLine());
        return 'invalid_code';
    }

    private function explainCode(string $chatId): string
    {
        $this->reply($chatId, 'Welcome to Triple R Gensan Car Rental. To get your booking updates here, ask the rental office for a connection code, then send it to this chat.' . $this->officeLine());
        return 'needs_code';
    }

    /** Telegram reports "kicked" when the customer blocks the bot. */
    private function membershipChanged(array $membership, string $chatId): string
    {
        $status = $membership['new_chat_member']['status'] ?? null;
        if ($status === 'kicked') {
            return $this->links->disconnectChat($chatId, 'bot_blocked') ? 'blocked' : 'ignored';
        }
        return 'ignored';
    }

    /** A reply that cannot be delivered must never stop updates from being processed. */
    private function reply(string $chatId, string $text): void
    {
        try {
            $this->client->sendMessage($chatId, $text);
        } catch (\Throwable $error) {
            error_log('Telegram reply could not be sent: ' . get_class($error));
        }
    }

    private function officeLine(): string
    {
        $phone = SiteProfile::get('contact.phone_display');
        return is_string($phone) && $phone !== '' ? ' Office: ' . $phone . '.' : '';
    }
}
