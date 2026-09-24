<?php
declare(strict_types=1);

namespace TripleR\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use TripleR\Config;
use TripleR\Repositories\MagicLinkRepository;
use TripleR\Support\PhoneNumber;

final class MagicLinkService
{
    private const PURPOSES = ['booking_manage', 'accept_rules', 'submit_payment'];
    private const MAX_TTL_SECONDS = 172800;

    public function __construct(
        private readonly MagicLinkRepository $tokens,
        private readonly RateLimiter $rateLimiter,
        private readonly NotificationService $notifications,
    ) {
    }

    public function issue(
        string $phone,
        ?string $email,
        string $purpose,
        ?int $bookingId = null,
        ?DateTimeInterface $holdExpiresAt = null,
        ?string $bookingReference = null,
    ): int {
        $phone = PhoneNumber::normalize($phone);
        $email = $email === null || trim($email) === '' ? null : mb_strtolower(trim($email));
        if ($email !== null && (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            throw new \InvalidArgumentException('A valid email address is required for this magic-link request.');
        }
        $this->assertPurpose($purpose);

        $contactLimit = max(1, Config::int('MAGIC_LINK_MAX_PER_CONTACT_24H', 10));
        if (!$this->rateLimiter->allow('magic-link-phone-issue', $phone, $contactLimit, 86400)
            || ($email !== null && !$this->rateLimiter->allow('magic-link-email-issue', $email, $contactLimit, 86400))) {
            throw new \DomainException('A secure link cannot be sent right now. Please try later or contact the rental office.');
        }
        $bookingCap = max(1, min(255, Config::int('MAGIC_LINK_MAX_PER_BOOKING', 6)));
        if ($bookingId !== null) {
            if ($holdExpiresAt === null) {
                throw new \InvalidArgumentException('A booking-linked magic link requires its hold expiry.');
            }
        } elseif ($holdExpiresAt !== null) {
            throw new \InvalidArgumentException('A hold expiry requires a booking ID.');
        }

        $ttl = min(self::MAX_TTL_SECONDS, max(60, Config::int('MAGIC_LINK_TTL_SECONDS', self::MAX_TTL_SECONDS)));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expiresAt = $now->modify('+' . $ttl . ' seconds');
        if ($holdExpiresAt !== null) {
            $hold = DateTimeImmutable::createFromInterface($holdExpiresAt)->setTimezone(new DateTimeZone('UTC'));
            if ($hold < $expiresAt) {
                $expiresAt = $hold;
            }
            if ($expiresAt <= $now) {
                throw new \DomainException('The booking hold has already expired.');
            }
        }

        $rawToken = self::base64UrlEncode(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $tokenId = $this->tokens->create($tokenHash, $purpose, $bookingId, $expiresAt->format('Y-m-d H:i:s.u'), $bookingCap);
        try {
            $link = $this->linkFor($rawToken, $purpose);
            $hours = max(1, (int) ceil(($expiresAt->getTimestamp() - $now->getTimestamp()) / 3600));
            $reference = $bookingReference === null ? '' : ' Booking reference: ' . trim($bookingReference) . '.';
            $refundReminder = $purpose === 'submit_payment' ? ' The 30% GCash downpayment is non-refundable.' : '';
            $message = 'Use this secure Triple R Gensan link to continue: ' . $link . ' It expires in about ' . $hours . ' hour(s).' . $reference . $refundReminder;
            $this->notifications->enqueue(
                $phone,
                'magic_link.' . $purpose,
                $message,
                'transactional',
                'high',
                'magic-link:' . $tokenId,
                true,
            );
        } catch (\Throwable $error) {
            $this->tokens->invalidate($tokenId);
            throw $error;
        }
        return $tokenId;
    }

    public function redeem(string $rawToken, string $expectedPurpose, string $ip, string $userAgent): ?array
    {
        if (!$this->allowRedemptionRequest($ip)) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9_-]{43}$/D', $rawToken) !== 1) {
            return null;
        }
        if (!in_array($expectedPurpose, self::PURPOSES, true)) {
            return null;
        }
        $hash = hash('sha256', $rawToken);
        $tokenLimit = max(1, Config::int('MAGIC_LINK_VERIFY_PER_TOKEN_PER_HOUR', 10));
        if (!$this->rateLimiter->allow('magic-link-redeem-token', $hash, $tokenLimit, 3600)) {
            return null;
        }
        return $this->tokens->consume($hash, $expectedPurpose, $ip, $userAgent);
    }

    private function allowRedemptionRequest(string $ip): bool
    {
        $ipLimit = max(1, Config::int('MAGIC_LINK_VERIFY_PER_IP_PER_HOUR', 20));
        return $this->rateLimiter->allow('magic-link-redeem-ip', $ip, $ipLimit, 3600);
    }

    public function attachBooking(int $tokenId, int $bookingId, DateTimeInterface $holdExpiresAt): bool
    {
        if ($bookingId <= 0) {
            throw new \InvalidArgumentException('A valid booking ID is required.');
        }
        $hold = DateTimeImmutable::createFromInterface($holdExpiresAt)->setTimezone(new DateTimeZone('UTC'));
        $bookingCap = max(1, min(255, Config::int('MAGIC_LINK_MAX_PER_BOOKING', 6)));
        return $this->tokens->attachBooking($tokenId, $bookingId, $hold->format('Y-m-d H:i:s.u'), $bookingCap);
    }

    public function currentSessionContext(string $expectedPurpose): ?array
    {
        $this->assertPurpose($expectedPurpose);
        $context = $_SESSION['magic_link_context'] ?? null;
        if (!is_array($context) || ($context['purpose'] ?? null) !== $expectedPurpose || !isset($context['token_id'])) {
            return null;
        }
        $current = $this->tokens->sessionContext((int) $context['token_id'], $expectedPurpose);
        if ($current === null) {
            unset($_SESSION['magic_link_context']);
            return null;
        }
        return $current;
    }

    public static function purposes(): array
    {
        return self::PURPOSES;
    }

    private function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new \InvalidArgumentException('Unsupported magic-link purpose.');
        }
    }

    private function linkFor(string $token, string $purpose): string
    {
        $baseUrl = rtrim(Config::require('APP_BASE_URL'), '/');
        $parts = parse_url($baseUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || (strtolower($parts['scheme']) !== 'https' && !in_array(strtolower($parts['host']), ['localhost', '127.0.0.1'], true))
            || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new \RuntimeException('APP_BASE_URL must be HTTPS (HTTP is allowed for localhost only).');
        }
        return $baseUrl . '/magic-link#token=' . rawurlencode($token) . '&purpose=' . rawurlencode($purpose);
    }

    private static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
