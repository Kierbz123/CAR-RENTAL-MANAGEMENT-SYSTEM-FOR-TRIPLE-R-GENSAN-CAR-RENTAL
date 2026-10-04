<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDOException;
use TripleR\Config;
use TripleR\Repositories\InboundSmsEventRepository;
use TripleR\Repositories\NotificationRepository;
use TripleR\Repositories\RulesAcceptanceRepository;
use TripleR\Services\Sms\SmsProviderException;
use TripleR\Services\Sms\SmsProviderFactory;
use TripleR\Services\Telegram\TelegramChatUnavailableException;
use TripleR\Support\PhoneNumber;

final class NotificationService
{
    private ?PhoneVault $vault = null;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly InboundSmsEventRepository $inboundEvents,
        private readonly SmsMessageCipher $messageCipher,
        private readonly ?RulesAcceptanceRepository $rulesAcceptances = null,
        private readonly ?TelegramLinkService $telegram = null,
    ) {
    }

    /**
     * Queues one message for a customer. When $customerId is given and that customer has an
     * active Telegram connection, the message is addressed to Telegram; otherwise it takes the
     * SMS route. Either way the phone number stays on the row, so the daily limit, the
     * idempotency key and the staff history work the same for both channels.
     */

    public function enqueue(
        string $recipient,
        string $templateKey,
        string $message,
        string $messageClass = 'transactional',
        string $priority = 'normal',
        ?string $idempotencyKey = null,
        bool $encryptAtRest = false,
        ?int $customerId = null,
    ): int {
        $phone = PhoneNumber::normalize($recipient);
        if (!in_array($messageClass, ['transactional', 'non_transactional'], true)) {
            throw new \InvalidArgumentException('Unknown SMS message class.');
        }
        if (!in_array($priority, ['normal', 'high'], true)) {
            throw new \InvalidArgumentException('Unknown SMS priority.');
        }
        if (preg_match('/^[a-z0-9_.-]{1,80}$/i', $templateKey) !== 1) {
            throw new \InvalidArgumentException('Invalid SMS template key.');
        }
        if (trim($message) === '' || mb_strlen($message) > 2000 || str_contains($message, "\0")) {
            throw new \InvalidArgumentException('SMS message must contain 1 to 2000 characters.');
        }
        if ($idempotencyKey !== null && (trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 191)) {
            throw new \InvalidArgumentException('The SMS idempotency key must contain 1 to 191 characters.');
        }
        $link = $customerId !== null && $this->telegram !== null && $this->telegram->isConfigured()
            ? $this->telegram->activeLink($customerId)
            : null;
        $entry = [
            'recipient_phone' => $phone,
            'customer_id' => $customerId,
            'channel' => $link === null ? 'sms' : 'telegram',
            'telegram_link_id' => $link === null ? null : (int) $link['link_id'],
            'idempotency_key' => $idempotencyKey,
            'template_key' => $templateKey,
            'message' => $message,
            // Queue bodies are always encrypted; the argument remains for call-site compatibility.
            'encrypt_at_rest' => true,
            'message_class' => $messageClass,
            'priority' => $priority,
            'provider' => $link === null ? strtolower(Config::get('SMS_PROVIDER', 'semaphore') ?? 'semaphore') : 'telegram',
        ];
        if (!in_array($entry['provider'], ['semaphore', 'philsms', 'telegram'], true)) {
            throw new \InvalidArgumentException('SMS_PROVIDER must be semaphore or philsms.');
        }

        if($messageClass==='non_transactional'){
            $enabled=strtolower(Config::get('NON_TRANSACTIONAL_SMS_ENABLED','false')??'false')==='true';
            $stopped=$this->hasStop($phone);
            // Affirmative-consent capture is not shipped yet; even an accidental flag change cannot opt customers in.
            $entry['suppression_reason']=$stopped?'Suppressed by recorded STOP event':(!$enabled?'Suppressed by policy: NON_TRANSACTIONAL_SMS_ENABLED is false':'Suppressed by policy: affirmative SMS opt-in capture is not available');
            return $this->insertPolicySuppression($entry);
        }

        $budgetDate = date('Y-m-d');
        $dailyLimit = Config::int('SMS_DAILY_LIMIT_PER_PHONE', 10);
        $this->notifications->begin();
        try {
            if ($idempotencyKey !== null) {
                $existingId = $this->notifications->findByIdempotencyKey($idempotencyKey);
                if ($existingId !== null) {
                    $this->notifications->commit();
                    return $existingId;
                }
            }
            $used = $this->notifications->lockDailyBudget($phone, $budgetDate);
            if ($used >= $dailyLimit) {
                throw new \DomainException('The daily SMS limit for this recipient has been reached.');
            }
            $this->notifications->incrementDailyBudget($phone, $budgetDate);
            $id = $this->notifications->insertQueued($entry, max(1, Config::int('SMS_MAX_ATTEMPTS', 5)));
            $this->notifications->commit();
            return $id;
        } catch (\Throwable $error) {
            $this->notifications->rollback();
            if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062 && $idempotencyKey !== null) {
                $existingId = $this->notifications->findByIdempotencyKey($idempotencyKey);
                if ($existingId !== null) {
                    return $existingId;
                }
            }
            throw $error;
        }
    }

    public function processBatch(int $batchSize = 25): array
    {
        $configuredProvider = strtolower(Config::get('SMS_PROVIDER', 'semaphore') ?? 'semaphore');
        // A wrong SMS setting must not hold back messages that are going to Telegram.
        if (!in_array($configuredProvider, ['semaphore', 'philsms'], true) && $this->notifications->hasDueQueuedOnChannel('sms')) {
            throw new \RuntimeException('SMS_PROVIDER must be semaphore or philsms.');
        }
        if ($this->notifications->hasDueEncryptedQueued() && !$this->messageCipher->canDecryptConfiguredKey()) {
            throw new \RuntimeException('A valid SMS_CIPHER_KEY is required before encrypted SMS can be sent.');
        }
        $claimed = $this->notifications->claimBatch($batchSize);
        $result = ['claimed' => count($claimed), 'sent' => 0, 'suppressed' => 0, 'failed' => 0, 'retrying' => 0, 'rerouted' => 0];
        if ($claimed === []) {
            return $result;
        }

        foreach ($claimed as $item) {
            $id = (int) $item['id'];
            $token = (string) $item['claim_token'];
            $viaTelegram = ($item['channel'] ?? 'sms') === 'telegram';
            $linkId = (int) ($item['telegram_link_id'] ?? 0);
            try {
                $provider = SmsProviderFactory::create((string) $item['provider']);
                if ($item['message_class'] === 'non_transactional') {
                    $hasStopped = $this->hasStop($this->recipientOf($item));
                    $reason = $hasStopped ? 'Suppressed by recorded STOP event' : 'Suppressed by policy: affirmative SMS opt-in capture is not available';
                    if ($this->notifications->markSuppressedByPolicy($id, $token, $reason)) {
                        $result['suppressed']++;
                    }
                    continue;
                }
                try {
                    $message = $this->messageCipher->decrypt(
                        (string) $item['rendered_message'],
                        SmsMessageCipher::context($this->recipientOf($item), (string) $item['template_key']),
                    );
                } catch (\Throwable $error) {
                    error_log('Encrypted SMS could not be decrypted for notification ' . $id . ': ' . get_class($error));
                    if ($this->notifications->markFailure(
                        $id,
                        $token,
                        'Encrypted SMS could not be decrypted. Verify SMS_CIPHER_KEY and SMS_CIPHER_KEY_PREVIOUS, then reconcile this row.',
                        false,
                        1,
                        true,
                    )) {
                        $result['failed']++;
                    }
                    continue;
                }
                $recipient = $this->recipientOf($item);
                if ($viaTelegram) {
                    $chatId = $this->telegram?->chatIdForLink($linkId);
                    if ($chatId === null) {
                        $this->fallBackFromTelegram($item, $result); // Disconnected after this was queued.
                        continue;
                    }
                    $recipient = $chatId;
                }
                $sent = $provider->send($recipient, $message, (string) $item['priority']);
                // Telegram numbers messages per chat, so its ids are made unique with the connection id.
                $messageId = $viaTelegram ? 'tg:' . $linkId . ':' . $sent['message_id'] : $sent['message_id'];
                if ($this->notifications->markSent($id, $token, $messageId, $sent['status'])) {
                    $result['sent']++;
                }
            } catch (TelegramChatUnavailableException) {
                $this->telegram?->disconnectBlocked($linkId);
                $this->fallBackFromTelegram($item, $result);
            } catch (SmsProviderException $error) {
                $this->recordFailure($item, $error->getMessage(), $error->retryable, $result);
            } catch (\Throwable $error) {
                error_log('SMS worker error for notification ' . $id . ': ' . get_class($error));
                $this->recordFailure(
                    $item,
                    'SMS provider configuration or response error. Check configuration and reconcile before retrying.',
                    false,
                    $result,
                    $this->messageCipher->isEncrypted((string) $item['rendered_message']),
                );
            }
        }
        return $result;
    }

    /** The customer's Telegram connection is gone: use SMS when a provider is set up, else fail plainly. */
    private function fallBackFromTelegram(array $item, array &$result): void
    {
        $id = (int) $item['id'];
        $token = (string) $item['claim_token'];
        if (SmsProviderFactory::smsConfigured()) {
            $provider = strtolower(Config::get('SMS_PROVIDER', 'semaphore') ?? 'semaphore');
            if ($this->notifications->rerouteToSms($id, $token, $provider, 'The customer\'s Telegram connection has ended; sending by SMS instead.')) {
                $result['rerouted']++;
            }
            return;
        }
        if ($this->notifications->markFailure($id, $token, 'Not delivered: the customer\'s Telegram connection has ended and no SMS provider is set up.', false, 1)) {
            $result['failed']++;
        }
    }

    private function recordFailure(array $item, string $message, bool $retryable, array &$result, bool $preserveEncrypted = false): void
    {
        $attempt = (int) $item['attempt_count'];
        $retry = $retryable && $attempt < (int) $item['max_attempts'];
        $base = $item['priority'] === 'high' ? 10 : 60;
        $cap = $item['priority'] === 'high' ? 120 : 3600;
        $delay = min($cap, $base * (2 ** min(10, max(0, $attempt - 1))));
        if ($this->notifications->markFailure((int) $item['id'], (string) $item['claim_token'], $message, $retry, $delay, $preserveEncrypted)) {
            $result[$retry ? 'retrying' : 'failed']++;
        }
    }

    private function insertPolicySuppression(array $entry): int
    {
        $this->notifications->begin();
        try{
            if($entry['idempotency_key']!==null){$existing=$this->notifications->findByIdempotencyKey($entry['idempotency_key']);if($existing!==null){$this->notifications->commit();return $existing;}}
            $id=$this->notifications->insertSuppressedByPolicy($entry);$this->notifications->commit();return $id;
        }catch(\Throwable $e){$this->notifications->rollback();if($e instanceof PDOException&&(int)($e->errorInfo[1]??0)===1062&&$entry['idempotency_key']!==null){$existing=$this->notifications->findByIdempotencyKey($entry['idempotency_key']);if($existing!==null)return $existing;}throw $e;}
    }

    /** The real number of a queued row: sealed rows (migration 026) are decrypted, older rows hold it plainly. */
    private function recipientOf(array $item): string
    {
        $this->vault ??= new PhoneVault();
        return $this->vault->numberOf($item['recipient_ciphertext'] ?? null, (string) $item['recipient_phone']);
    }

    private function hasStop(string $phone): bool
    { return ($this->rulesAcceptances?->hasStop($phone) ?? false) || $this->inboundEvents->hasStop($phone); }
}
