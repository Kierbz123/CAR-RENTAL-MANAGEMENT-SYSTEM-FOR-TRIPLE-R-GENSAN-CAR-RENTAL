<?php
declare(strict_types=1);

namespace TripleR\Services\Sms;

final class SmsHttpClient
{
    public function post(string $url, array $headers, string $body): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new SmsProviderException('SMS provider request could not be initialized.', true);
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($response === false) {
            throw new SmsProviderException('SMS provider outcome could not be confirmed after a connection failure.', false);
        }
        if ($status < 200 || $status >= 300) {
            throw new SmsProviderException('SMS provider rejected the send request (HTTP ' . $status . ').', $status === 429 || $status >= 500);
        }
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new SmsProviderException('SMS provider returned an unreadable response; the send outcome is unknown.', false);
        }
        return $decoded;
    }
}
