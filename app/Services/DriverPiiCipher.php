<?php
declare(strict_types=1);

namespace TripleR\Services;

use RuntimeException;
use TripleR\Config;

final class DriverPiiCipher
{
    private string $encryptionKey;
    private string $fingerprintKey;

    public function __construct()
    {
        $master = base64_decode(Config::require('DRIVER_PII_KEY'), true);
        if (!is_string($master) || strlen($master) !== 32) {
            throw new RuntimeException('DRIVER_PII_KEY must be base64 for exactly 32 random bytes.');
        }
        $this->encryptionKey = hash_hkdf('sha256', $master, 32, 'triple-r/driver-pii/encryption');
        $this->fingerprintKey = hash_hkdf('sha256', $master, 32, 'triple-r/driver-pii/license-fingerprint');
        unset($master);
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Driver data encryption failed.');
        }
        return "\x01" . $nonce . $tag . $ciphertext;
    }

    public function decrypt(string $envelope, string $context): string
    {
        if (strlen($envelope) < 29 || $envelope[0] !== "\x01") {
            throw new RuntimeException('Driver data has an unsupported encryption format.');
        }
        $plaintext = openssl_decrypt(substr($envelope, 29), 'aes-256-gcm', $this->encryptionKey, OPENSSL_RAW_DATA, substr($envelope, 1, 12), substr($envelope, 13, 16), $context);
        if (!is_string($plaintext)) {
            throw new RuntimeException('Driver data could not be decrypted. Verify DRIVER_PII_KEY.');
        }
        return $plaintext;
    }

    public function licenseFingerprint(string $normalized): string
    {
        return hash_hmac('sha256', "ph_driver_license\0" . $normalized, $this->fingerprintKey);
    }

    public static function normalizeLicense(string $value): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');
        if ($normalized === '' || strlen($normalized) > 100) {
            throw new RuntimeException('Enter a valid driver license number.');
        }
        return $normalized;
    }

    public static function normalizeContact(string $type, string $value): string
    {
        if ($type === 'phone') {
            try { return \TripleR\Support\PhoneNumber::normalize($value); }
            catch (\InvalidArgumentException $error) { throw new RuntimeException($error->getMessage(), 0, $error); }
        }
        if ($type === 'email') {
            $value = mb_strtolower(trim($value));
            if (filter_var($value, FILTER_VALIDATE_EMAIL) === false || mb_strlen($value) > 254) {
                throw new RuntimeException('Enter a valid email address.');
            }
            return $value;
        }
        throw new RuntimeException('Choose phone or email as the contact type.');
    }

    public static function mask(string $type, string $value): string
    {
        if ($type === 'email' && str_contains($value, '@')) {
            [$name, $domain] = explode('@', $value, 2);
            return mb_substr($name, 0, 1) . '***@' . $domain;
        }
        return '****' . substr(preg_replace('/\W/', '', $value) ?? '', -4);
    }
}
