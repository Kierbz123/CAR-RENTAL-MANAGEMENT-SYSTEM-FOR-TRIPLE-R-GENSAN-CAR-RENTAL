<?php
declare(strict_types=1);

namespace TripleR\Services;

use RuntimeException;
use TripleR\Config;

final class CustomerPiiCipher
{
    private string $encryptionKey;
    private string $fingerprintKey;

    public function __construct()
    {
        $master=base64_decode(Config::require('CUSTOMER_PII_KEY'),true);
        if (!is_string($master) || strlen($master)!==32) throw new RuntimeException('CUSTOMER_PII_KEY must be base64 for exactly 32 random bytes.');
        $this->encryptionKey=hash_hkdf('sha256',$master,32,'triple-r/customer-pii/encryption');
        $this->fingerprintKey=hash_hkdf('sha256',$master,32,'triple-r/customer-pii/fingerprint');
        unset($master);
    }

    public function encrypt(string $plaintext,string $context): string
    {
        $nonce=random_bytes(12); $tag='';
        $cipher=openssl_encrypt($plaintext,'aes-256-gcm',$this->encryptionKey,OPENSSL_RAW_DATA,$nonce,$tag,$context,16);
        if (!is_string($cipher) || strlen($tag)!==16) throw new RuntimeException('Customer data encryption failed.');
        return "\x01".$nonce.$tag.$cipher;
    }

    public function decrypt(string $envelope,string $context): string
    {
        if (strlen($envelope)<29 || $envelope[0]!=="\x01") throw new RuntimeException('Customer data has an unsupported encryption format.');
        $plain=openssl_decrypt(substr($envelope,29),'aes-256-gcm',$this->encryptionKey,OPENSSL_RAW_DATA,substr($envelope,1,12),substr($envelope,13,16),$context);
        if (!is_string($plain)) throw new RuntimeException('Customer data could not be decrypted. Verify CUSTOMER_PII_KEY.');
        return $plain;
    }

    public function fingerprint(string $namespace,string $normalized): string
    {
        return hash_hmac('sha256',$namespace."\0".$normalized,$this->fingerprintKey);
    }

    public static function normalizeDocumentType(string $type): string
    {
        $key=strtolower(preg_replace('/[^a-z0-9]+/i','',trim($type))??'');
        $canonical=['driverlicense'=>'ph_driver_license','driverslicense'=>'ph_driver_license','phdriverlicense'=>'ph_driver_license','passport'=>'passport','nationalid'=>'national_id','othergovernmentid'=>'other_government_id'];
        if (!isset($canonical[$key])) throw new RuntimeException('Choose a supported identity document type.');
        return $canonical[$key];
    }

    public static function normalizeDocumentNumber(string $value): string
    {
        $value=strtoupper(trim($value)); $normalized=preg_replace('/[^A-Z0-9]/','',$value)??'';
        if ($normalized==='' || strlen($normalized)>100) throw new RuntimeException('Enter a valid identity document number.');
        return $normalized;
    }

    public static function normalizeContact(string $type,string $value): string
    {
        if ($type==='phone') return \TripleR\Support\PhoneNumber::normalize($value);
        if ($type==='email') {
            $value=mb_strtolower(trim($value));
            if (filter_var($value,FILTER_VALIDATE_EMAIL)===false || mb_strlen($value)>254) throw new RuntimeException('Enter a valid email address.');
            return $value;
        }
        throw new RuntimeException('Choose phone or email as the contact type.');
    }

    public static function mask(string $type,string $value): string
    {
        if ($type==='email' && str_contains($value,'@')) { [$name,$domain]=explode('@',$value,2); return mb_substr($name,0,1).'•••@'.$domain; }
        return '••••' . substr(preg_replace('/\W/','',$value)??'',-4);
    }
}
