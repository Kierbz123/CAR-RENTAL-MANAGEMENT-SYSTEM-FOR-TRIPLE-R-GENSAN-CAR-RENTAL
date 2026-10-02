<?php
declare(strict_types=1);

namespace TripleR\Services\Telegram;

use TripleR\Config;

/**
 * The only class that talks to Telegram's Bot API.
 *
 * The bot token is part of every request address, so nothing here logs an address, a curl
 * error string or a raw response. Callers only ever see TelegramApiException's fixed sentences.
 */
final class TelegramBotClient
{
    private const DEFAULT_API_BASE = 'https://api.telegram.org';

    public static function isConfigured(): bool
    {
        return self::validToken((string) Config::get('TELEGRAM_BOT_TOKEN', '')) && self::botUsername() !== null;
    }

    /** The bot's public @username without the @, or null when it is missing or malformed. */
    public static function botUsername(): ?string
    {
        $name = ltrim(trim((string) Config::get('TELEGRAM_BOT_USERNAME', '')), '@');
        return preg_match('/^[A-Za-z][A-Za-z0-9_]{3,31}$/D', $name) === 1 ? $name : null;
    }

    /** @return array{message_id:int} */
    public function sendMessage(string $chatId, string $text): array
    {
        $result = $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            // Plain text only, and no link preview: Telegram must not fetch a customer's booking link.
            'link_preview_options' => json_encode(['is_disabled' => true]),
        ], 15);
        if (!isset($result['message_id']) || !is_int($result['message_id'])) {
            throw new TelegramApiException('Telegram returned an unreadable answer; the send outcome is unknown.', 200, null, 'unreadable');
        }
        return ['message_id' => $result['message_id']];
    }

    /** @return list<array<string,mixed>> */
    public function getUpdates(?int $offset, int $waitSeconds, int $limit = 50): array
    {
        $waitSeconds = max(0, min(50, $waitSeconds));
        $parameters = ['timeout' => $waitSeconds, 'limit' => max(1, min(100, $limit)), 'allowed_updates' => json_encode(['message', 'my_chat_member'])];
        if ($offset !== null) {
            $parameters['offset'] = $offset;
        }
        $result = $this->call('getUpdates', $parameters, $waitSeconds + 10);
        return array_values(array_filter($result, 'is_array'));
    }

    /** @return array{id:int,username:string} */
    public function getMe(): array
    {
        $result = $this->call('getMe', [], 15);
        return ['id' => (int) ($result['id'] ?? 0), 'username' => (string) ($result['username'] ?? '')];
    }

    private function call(string $method, array $parameters, int $timeoutSeconds): array
    {
        $token = (string) Config::get('TELEGRAM_BOT_TOKEN', '');
        if (!self::validToken($token)) {
            throw new TelegramApiException('Telegram is not set up: TELEGRAM_BOT_TOKEN is missing or malformed.', 0, null, 'not_configured');
        }
        $handle = curl_init($this->apiBase() . '/bot' . $token . '/' . $method);
        if ($handle === false) {
            throw new TelegramApiException('The Telegram request could not be started.', 0, null, 'network');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($response === false) {
            throw new TelegramApiException('Telegram could not be reached. Check the internet connection.', 0, null, 'network');
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new TelegramApiException('Telegram returned an unreadable answer (HTTP ' . $status . ').', $status, null, $status >= 500 ? 'server' : 'unreadable');
        }
        if (($decoded['ok'] ?? false) === true && $status >= 200 && $status < 300) {
            return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
        }
        throw $this->refusal($status, $decoded);
    }

    private function refusal(int $status, array $decoded): TelegramApiException
    {
        $code = is_int($decoded['error_code'] ?? null) ? $decoded['error_code'] : $status;
        $description = strtolower(is_string($decoded['description'] ?? null) ? $decoded['description'] : '');
        $retryAfter = $decoded['parameters']['retry_after'] ?? null;
        $retryAfter = is_int($retryAfter) && $retryAfter > 0 ? min(3600, $retryAfter) : null;

        if ($code === 429) {
            return new TelegramApiException('Telegram asked the system to slow down; the message will be retried.', 429, $retryAfter, 'rate_limited');
        }
        if ($code === 401 || $code === 404) {
            return new TelegramApiException('Telegram rejected the bot token. Check TELEGRAM_BOT_TOKEN.', $code, null, 'bad_token');
        }
        if ($code === 409) {
            return new TelegramApiException('Another program is already reading this bot\'s messages, or a webhook is set for it.', 409, null, 'conflict');
        }
        if ($code === 403 || ($code === 400 && (str_contains($description, 'chat not found') || str_contains($description, 'user is deactivated') || str_contains($description, 'peer_id_invalid')))) {
            return new TelegramApiException('The customer has blocked the bot or closed the chat.', $code, null, 'chat_unavailable');
        }
        if ($code >= 500) {
            return new TelegramApiException('Telegram is temporarily unavailable (HTTP ' . $code . ').', $code, null, 'server');
        }
        return new TelegramApiException('Telegram refused the request (HTTP ' . $code . ').', $code, null, 'refused');
    }

    /** api.telegram.org, unless TELEGRAM_API_BASE points the tests at a stand-in on this machine. */
    private function apiBase(): string
    {
        $base = rtrim(trim((string) Config::get('TELEGRAM_API_BASE', '')), '/');
        if ($base === '') {
            return self::DEFAULT_API_BASE;
        }
        $parts = parse_url($base);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $local = in_array($host, ['127.0.0.1', 'localhost'], true);
        if (!is_array($parts) || $host === '' || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])
            || !($scheme === 'https' || ($scheme === 'http' && $local))) {
            throw new TelegramApiException('TELEGRAM_API_BASE must be an https address (http is allowed for this machine only).', 0, null, 'not_configured');
        }
        return $base;
    }

    private static function validToken(string $token): bool
    {
        return preg_match('/^\d{5,20}:[A-Za-z0-9_-]{30,80}$/D', $token) === 1;
    }
}
