<?php
declare(strict_types=1);

namespace TripleR\Services\Sms;

use TripleR\Config;

final class SemaphoreSmsProvider implements SmsProviderInterface
{
    public function __construct(private readonly SmsHttpClient $http)
    {
    }

    public function send(string $recipient, string $message, string $priority): array
    {
        $apiKey = Config::require('SMS_SEMAPHORE_API_KEY');
        $payload = ['apikey' => $apiKey, 'number' => $recipient, 'message' => $message];
        $sender = Config::get('SMS_SEMAPHORE_SENDER_NAME');
        if ($sender !== null && $sender !== '') {
            $payload['sendername'] = $sender;
        }
        $endpoint = $priority === 'high' ? 'priority' : 'messages';
        $response = $this->http->post(
            'https://api.semaphore.co/api/v4/' . $endpoint,
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query($payload, '', '&', PHP_QUERY_RFC3986),
        );
        $record = isset($response[0]) && is_array($response[0]) ? $response[0] : $response;
        $id = $record['message_id'] ?? $record['id'] ?? $record['messageId'] ?? null;
        if (!is_scalar($id) || (string) $id === '') {
            throw new SmsProviderException('Semaphore response had no identifiable message record; the send outcome is unknown.', false);
        }
        $status = $record['status'] ?? 'queued';
        $status = is_scalar($status) ? (string) $status : 'queued';
        if (in_array(strtolower($status), ['failed', 'refunded', 'rejected', 'error'], true)) {
            throw new SmsProviderException('Semaphore reported that the message was rejected by the network.', true);
        }
        return ['message_id' => (string) $id, 'status' => $status];
    }
}
