<?php
declare(strict_types=1);

namespace TripleR\Services\Sms;

use TripleR\Config;

final class SmsProviderFactory
{
    public static function create(?string $providerName = null): SmsProviderInterface
    {
        $http = new SmsHttpClient();
        $providerName = strtolower($providerName ?? Config::get('SMS_PROVIDER', 'semaphore') ?? 'semaphore');
        return match ($providerName) {
            'semaphore' => new SemaphoreSmsProvider($http),
            'philsms' => new PhilSmsProvider($http),
            default => throw new \RuntimeException('SMS_PROVIDER must be semaphore or philsms.'),
        };
    }
}
