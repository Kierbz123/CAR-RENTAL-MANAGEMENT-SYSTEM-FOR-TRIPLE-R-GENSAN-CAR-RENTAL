<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDO;
use PDOException;
use RuntimeException;
use TripleR\Config;
use TripleR\Repositories\CustomerRepository;
use TripleR\Repositories\TelegramLinkRepository;
use TripleR\Services\Telegram\TelegramBotClient;

/**
 * Connects a customer to the Telegram bot and ends that connection.
 *
 * A customer connects by sending the bot a one-time code that staff created for them.
 * The chat id is stored like a phone number: encrypted, with a keyed fingerprint for lookup.
 * Every write locks the customer row first, then the code, then the connection.
 */
final class TelegramLinkService
{
    /** No 0/O or 1/I, so a code read aloud or typed by hand is unambiguous. */
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const CODE_LENGTH = 8;
    private const CIPHER_CONTEXT = 'customer-telegram:chat';
    private const FINGERPRINT_NAMESPACE = 'telegram-chat';

    public function __construct(
        private readonly PDO $db,
        private readonly TelegramLinkRepository $links,
        private readonly CustomerRepository $customers,
        private readonly CustomerPiiCipher $cipher,
        private readonly RateLimiter $rateLimiter,
    ) {
    }

    public static function create(PDO $db): self
    {
        return new self($db, new TelegramLinkRepository($db), new CustomerRepository($db), new CustomerPiiCipher(), new RateLimiter($db));
    }

    public function isConfigured(): bool
    {
        return TelegramBotClient::isConfigured();
    }

    /**
     * Creates the customer's connection code. The code itself is returned once and never stored.
     *
     * @return array{code:string,expires_at:int,link:string}
     */
    public function createCode(int $customerId, int $userId): array
    {
        $username = TelegramBotClient::botUsername();
        if (!$this->isConfigured() || $username === null) {
            throw new RuntimeException('Telegram is not set up yet. Add the bot token and username to the configuration first.');
        }
        if (!$this->rateLimiter->allow('telegram-code-create', (string) $customerId, 10, 3600)) {
            throw new RuntimeException('Too many connection codes were created for this customer. Please wait before creating another.');
        }
        $ttl = $this->codeTtlSeconds();
        $code = self::randomCode();
        $this->db->beginTransaction();
        try {
            if (!$this->customers->find($customerId, true)) {
                throw new RuntimeException('Customer not found.');
            }
            $this->links->expireOpenCodes($customerId);
            $this->links->insertCode($customerId, self::hashCode($code), $ttl, $userId);
            $this->db->commit();
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
        return ['code' => $code, 'expires_at' => time() + $ttl, 'link' => 'https://t.me/' . $username . '?start=' . $code];
    }

    /**
     * Connects the chat that sent a code.
     *
     * @return 'connected'|'invalid'|'rate_limited'
     */
    public function redeem(string $rawCode, string $chatId): string
    {
        $fingerprint = $this->chatFingerprint($chatId);
        // Guessing is limited per chat; a wrong and a right attempt both count.
        if (!$this->rateLimiter->allow('telegram-code-attempt', $fingerprint, 5, 900)) {
            return 'rate_limited';
        }
        $code = self::normalizeCode($rawCode);
        if ($code === null) {
            return 'invalid';
        }
        $hash = self::hashCode($code);
        $found = $this->links->findCode($hash);
        if ($found === null || $found['usable'] !== 1) {
            return 'invalid';
        }
        $customerId = $found['customer_id'];
        $this->db->beginTransaction();
        try {
            // Lock order: customers first (lowest id first), then the code, then connections.
            $current = $this->links->activeForChat($fingerprint);
            $toLock = array_unique(array_filter([$customerId, $current === null ? null : (int) $current['customer_id']]));
            sort($toLock);
            foreach ($toLock as $id) {
                $this->customers->find($id, true, true);
            }
            if (!$this->customers->find($customerId)) {
                $this->rollback();
                return 'invalid';
            }
            $locked = $this->links->findCode($hash, true);
            if ($locked === null || $locked['usable'] !== 1 || !$this->links->markCodeUsed($locked['code_id'])) {
                $this->rollback();
                return 'invalid';
            }
            $chatLink = $this->links->activeForChat($fingerprint, true);
            $customerLink = $this->links->activeForCustomer($customerId, true);
            if ($chatLink !== null && (int) $chatLink['customer_id'] === $customerId) {
                $this->db->commit(); // This chat is already this customer's connection.
                return 'connected';
            }
            if ($chatLink !== null) {
                $this->links->revoke((int) $chatLink['link_id'], 'relinked');
            }
            if ($customerLink !== null) {
                $this->links->revoke((int) $customerLink['link_id'], 'relinked');
            }
            $this->links->insertLink($customerId, $this->cipher->encrypt($chatId, self::CIPHER_CONTEXT), $fingerprint, $locked['code_id']);
            $this->db->commit();
            return 'connected';
        } catch (PDOException $error) {
            $this->rollback();
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                return 'invalid'; // Two connections raced; the database let exactly one through.
            }
            throw $error;
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    public function disconnectByStaff(int $customerId, int $userId): bool
    {
        return $this->endForCustomer($customerId, 'staff', $userId);
    }

    /** The customer sent /stop, or blocked the bot, from this chat. */
    public function disconnectChat(string $chatId, string $reason = 'customer_stop'): bool
    {
        $link = $this->links->activeForChat($this->chatFingerprint($chatId));
        return $link !== null && $this->endForCustomer((int) $link['customer_id'], $reason, null, (int) $link['link_id']);
    }

    /** Telegram refused a send to this connection: the bot was blocked or the chat is gone. */
    public function disconnectBlocked(int $linkId): bool
    {
        $link = $this->links->find($linkId);
        return $link !== null && $link['link_status'] === 'active'
            && $this->endForCustomer((int) $link['customer_id'], 'bot_blocked', null, $linkId);
    }

    public function activeLink(int $customerId): ?array
    {
        return $this->links->activeForCustomer($customerId);
    }

    /** The chat id for a connection, or null when the connection has ended. */
    public function chatIdForLink(int $linkId): ?string
    {
        $link = $this->links->find($linkId);
        if ($link === null || $link['link_status'] !== 'active') {
            return null;
        }
        return $this->cipher->decrypt((string) $link['chat_id_ciphertext'], self::CIPHER_CONTEXT);
    }

    /**
     * What the customer page shows.
     *
     * @return array{configured:bool,bot_username:?string,connected:bool,linked_at:?string,last_ended_at:?string,last_ended_reason:?string,last_ended_by:?string,open_code_expires_at:?string}
     */
    public function statusFor(int $customerId): array
    {
        $latest = $this->links->latestForCustomer($customerId);
        $active = $latest !== null && $latest['link_status'] === 'active';
        return [
            'configured' => $this->isConfigured(),
            'bot_username' => TelegramBotClient::botUsername(),
            'connected' => $active,
            'linked_at' => $active ? (string) $latest['linked_at'] : null,
            'last_ended_at' => !$active && $latest !== null ? (string) $latest['revoked_at'] : null,
            'last_ended_reason' => !$active && $latest !== null ? (string) $latest['revoked_reason'] : null,
            'last_ended_by' => !$active && $latest !== null ? ($latest['revoked_by_email'] ?? null) : null,
            'open_code_expires_at' => $active ? null : $this->links->openCodeExpiry($customerId),
        ];
    }

    public function chatFingerprint(string $chatId): string
    {
        return $this->cipher->fingerprint(self::FINGERPRINT_NAMESPACE, $chatId);
    }

    /** Upper-cases and strips spaces and dashes; null when what is left is not shaped like a code. */
    public static function normalizeCode(string $raw): ?string
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', $raw) ?? '');
        return preg_match('/^[' . self::CODE_ALPHABET . ']{' . self::CODE_LENGTH . '}$/D', $code) === 1 ? $code : null;
    }

    private function endForCustomer(int $customerId, string $reason, ?int $userId, ?int $onlyLinkId = null): bool
    {
        $this->db->beginTransaction();
        try {
            $this->customers->find($customerId, true, true);
            $link = $this->links->activeForCustomer($customerId, true);
            $ended = $link !== null
                && ($onlyLinkId === null || (int) $link['link_id'] === $onlyLinkId)
                && $this->links->revoke((int) $link['link_id'], $reason, $userId);
            // A code that was handed out but not yet used must not reconnect the customer afterwards.
            $this->links->expireOpenCodes($customerId);
            $this->db->commit();
            return $ended;
        } catch (\Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function codeTtlSeconds(): int
    {
        return max(1, min(60, Config::int('TELEGRAM_LINK_CODE_TTL_MINUTES', 15))) * 60;
    }

    private static function randomCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        return $code;
    }

    private static function hashCode(string $code): string
    {
        return hash('sha256', 'telegram-link-code:' . $code);
    }

    private function rollback(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
    }
}
