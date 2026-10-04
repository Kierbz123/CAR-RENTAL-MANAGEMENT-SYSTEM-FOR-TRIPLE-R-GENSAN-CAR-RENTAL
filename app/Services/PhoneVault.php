<?php
declare(strict_types=1);

namespace TripleR\Services;

use TripleR\Support\PhoneNumber;

/**
 * How a mobile number is kept outside the customer record: in the SMS queue, the inbound SMS
 * ledger and the consent ledger (migration 026). Three forms are stored instead of the number:
 *   - masked      "••••4567", for showing on screens that may not see the number;
 *   - ciphertext  the number, AES-256-GCM with CUSTOMER_PII_KEY, read only to send or to show to
 *                 roles allowed to see customer contacts;
 *   - fingerprint a keyed HMAC, the same value customer_contacts.contact_fingerprint holds for that
 *                 number, used to find rows (daily SMS limit, STOP replies) without decrypting.
 */
final class PhoneVault
{
    private const CONTEXT = 'stored-phone';
    private readonly CustomerPiiCipher $cipher;

    public function __construct(?CustomerPiiCipher $cipher = null)
    {
        $this->cipher = $cipher ?? new CustomerPiiCipher();
    }

    public function fingerprint(string $phone): string
    {
        return $this->cipher->fingerprint('contact:phone', PhoneNumber::normalize($phone));
    }

    public function seal(string $phone): string
    {
        return $this->cipher->encrypt(PhoneNumber::normalize($phone), self::CONTEXT);
    }

    public function open(string $ciphertext): string
    {
        return $this->cipher->decrypt($ciphertext, self::CONTEXT);
    }

    public static function mask(string $phone): string
    {
        return CustomerPiiCipher::mask('phone', $phone);
    }

    /** @return array{masked:string,ciphertext:string,fingerprint:string} */
    public function store(string $phone): array
    {
        return ['masked' => self::mask($phone), 'ciphertext' => $this->seal($phone), 'fingerprint' => $this->fingerprint($phone)];
    }

    /**
     * The number held in a row that has $ciphertext (sealed) and $legacy (the old plaintext column,
     * which still holds the number on rows written before migration 026 and not yet sealed).
     */
    public function numberOf(?string $ciphertext, string $legacy): string
    {
        return $ciphertext !== null && $ciphertext !== '' ? $this->open($ciphertext) : $legacy;
    }
}
