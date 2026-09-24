<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use TripleR\Config;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\InboundSmsEventRepository;
use TripleR\Repositories\NotificationRepository;
use TripleR\Support\PhoneNumber;

final class SmsWebhookController
{
    public function __construct(
        private readonly InboundSmsEventRepository $inboundEvents,
        private readonly NotificationRepository $notifications,
    ) {
    }

    public function inbound(Request $request): Response
    {
        try {
            if (!self::validSignature($request)) {
                error_log('SMS inbound callback rejected: invalid signature.');
                return Response::json(['received' => true]);
            }
            $payload = $request->json();
            $messageId = $payload['provider_message_id'] ?? $payload['message_id'] ?? $payload['id'] ?? null;
            $sender = $payload['sender_number'] ?? $payload['from'] ?? $payload['number'] ?? null;
            $message = $payload['message'] ?? $payload['text'] ?? $payload['body'] ?? null;
            if (!is_scalar($messageId) || (string) $messageId === '' || !is_scalar($sender) || !is_scalar($message) || $request->rawBody === '') {
                error_log('SMS inbound callback discarded: missing required fields.');
                return Response::json(['received' => true]);
            }
            $messageId = (string) $messageId;
            if (mb_strlen($messageId) > 191 || strlen($request->rawBody) > 16000000) {
                error_log('SMS inbound callback discarded: payload exceeds supported size.');
                return Response::json(['received' => true]);
            }
            $phone = PhoneNumber::normalize((string) $sender);
            $text = trim((string) $message);
            $eventType = preg_match('/^STOP$/i', $text) === 1 ? 'stop' : 'message';
            $providerValue = $payload['provider'] ?? Config::get('SMS_PROVIDER', 'gateway');
            $provider = substr(is_scalar($providerValue) ? (string) $providerValue : 'gateway', 0, 40);

            // Persist the immutable provider event before acknowledging the callback.
            $this->inboundEvents->append($messageId, $provider, $request->rawBody, $phone, $eventType, $text);
        } catch (\Throwable $error) {
            error_log('SMS inbound callback storage or parsing error: ' . get_class($error));
        }
        return Response::json(['received' => true]);
    }

    public function delivery(Request $request): Response
    {
        try {
            if (!self::validSignature($request)) {
                error_log('SMS delivery callback rejected: invalid signature.');
                return Response::json(['received' => true]);
            }
            $payload = $request->json();
            $messageId = $payload['provider_message_id'] ?? $payload['message_id'] ?? $payload['id'] ?? null;
            $status = $payload['status'] ?? null;
            $error = $payload['error'] ?? null;
            if (is_scalar($messageId) && is_scalar($status) && (string) $messageId !== '') {
                $this->notifications->recordDelivery((string) $messageId, (string) $status, is_scalar($error) ? (string) $error : null);
            }
        } catch (\Throwable $error) {
            error_log('SMS delivery callback processing error: ' . get_class($error));
        }
        return Response::json(['received' => true]);
    }

    public static function validSignature(Request $request): bool
    {
        $secret = Config::get('SMS_WEBHOOK_SECRET');
        $provided = $request->header('X-Webhook-Signature');
        if ($secret === null || strlen($secret) < 32 || str_starts_with($secret, 'replace-') || $provided === null || preg_match('/^sha256=([a-f0-9]{64})$/i', $provided, $matches) !== 1) {
            return false;
        }
        $expected = hash_hmac('sha256', $request->rawBody, $secret);
        return hash_equals($expected, strtolower($matches[1]));
    }
}
