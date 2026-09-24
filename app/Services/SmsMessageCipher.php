<?php
declare(strict_types=1);

namespace TripleR\Services;

use TripleR\Config;

final class SmsMessageCipher
{
    private const PREFIX = 'smsenc:v1:';

    public function encrypt(string $message, string $context): string
    {
        $key = $this->key(Config::require('SMS_CIPHER_KEY'));
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($message, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, "triple-r-sms:v1\0" . $context, 16);
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException('Unable to encrypt queued SMS content.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $stored, string $context): string
    {
        if (!str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }
        $payload = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) < 29) {
            throw new \RuntimeException('Encrypted SMS content is malformed.');
        }
        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $ciphertext = substr($payload, 28);
        $encodedKeys = [Config::get('SMS_CIPHER_KEY'), Config::get('SMS_CIPHER_KEY_PREVIOUS')];
        foreach ($encodedKeys as $encodedKey) {
            if ($encodedKey === null || $encodedKey === '') {
                continue;
            }
            try {
                $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key($encodedKey), OPENSSL_RAW_DATA, $iv, $tag, "triple-r-sms:v1\0" . $context);
                if ($plain !== false) {
                    return $plain;
                }
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
        throw new \RuntimeException('No configured SMS cipher key can decrypt this queued message.');
    }

    public function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    public function canDecryptConfiguredKey(): bool
    {
        foreach ([Config::get('SMS_CIPHER_KEY'), Config::get('SMS_CIPHER_KEY_PREVIOUS')] as $encodedKey) {
            if ($encodedKey === null || $encodedKey === '') {
                continue;
            }
            try {
                $this->key($encodedKey);
                return true;
            } catch (\InvalidArgumentException) {
                continue;
            }
        }
        return false;
    }

    public function staffPreview(string $stored, string $templateKey, string $recipient): string
    {
        if (str_starts_with($templateKey, 'magic_link')) {
            return '[Magic-link content masked]';
        }
        try {
            return $this->decrypt($stored, $this->context($recipient, $templateKey));
        } catch (\Throwable) {
            return $this->isEncrypted($stored) ? '[Encrypted message unavailable]' : $stored;
        }
    }

    private function key(string $encodedKey): string
    {
        $decoded = base64_decode($encodedKey, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \InvalidArgumentException('SMS_CIPHER_KEY must be base64-encoded 32-byte key material.');
        }
        return $decoded;
    }

    public static function context(string $recipient, string $templateKey): string
    {
        return $recipient . "\0" . $templateKey;
    }
}
