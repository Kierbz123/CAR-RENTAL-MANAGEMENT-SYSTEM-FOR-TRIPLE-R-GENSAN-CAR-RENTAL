<?php
declare(strict_types=1);

namespace TripleR\Services\Sms;

use TripleR\Config;

final class PhilSmsProvider implements SmsProviderInterface
{
    public function __construct(private readonly SmsHttpClient $http)
    {
    }

    public function send(string $recipient, string $message, string $priority): array
    {
        $token = Config::require('SMS_PHILSMS_API_TOKEN');
        $sender = Config::require('SMS_PHILSMS_SENDER_ID');
        $response = $this->http->post(
            'https://app.philsms.com/api/v3/sms/send',
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json'],
            (string) json_encode(['recipient' => $recipient, 'sender_id' => $sender, 'type' => 'plain', 'message' => $message], JSON_UNESCAPED_SLASHES),
        );
        if (strtolower((string) ($response['status'] ?? '')) !== 'success') {
            throw new SmsProviderException('PhilSMS did not accept the send request.', false);
        }
        $data = $response['data'] ?? null;
        $id = is_array($data) ? ($data['uid'] ?? $data['id'] ?? $data['message_id'] ?? null) : ($response['uid'] ?? $response['id'] ?? null);
        if (!is_scalar($id) || (string) $id === '') {
            throw new SmsProviderException('PhilSMS response had no message ID; the send outcome is unknown.', false);
        }
        return ['message_id' => (string) $id, 'status' => 'queued'];
    }
}
