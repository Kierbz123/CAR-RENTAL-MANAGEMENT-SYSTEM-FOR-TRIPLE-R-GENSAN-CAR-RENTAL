<?php
declare(strict_types=1);

namespace TripleR\Services\Sms;

use TripleR\Config;
use TripleR\Services\Telegram\TelegramBotClient;
use TripleR\Services\Telegram\TelegramProvider;

final class SmsProviderFactory
{
    public static function create(?string $providerName = null): SmsProviderInterface
    {
        $http = new SmsHttpClient();
        $providerName = strtolower($providerName ?? Config::get('SMS_PROVIDER', 'semaphore') ?? 'semaphore');
        return match ($providerName) {
            'semaphore' => new SemaphoreSmsProvider($http),
            'philsms' => new PhilSmsProvider($http),
            'telegram' => new TelegramProvider(new TelegramBotClient()),
            default => throw new \RuntimeException('SMS_PROVIDER must be semaphore or philsms.'),
        };
    }

    /** True when the configured SMS provider has the credential it needs to send. */
    public static function smsConfigured(): bool
    {
        $key = match (strtolower(Config::get('SMS_PROVIDER', 'semaphore') ?? 'semaphore')) {
            'semaphore' => Config::get('SMS_SEMAPHORE_API_KEY'),
            'philsms' => Config::get('SMS_PHILSMS_API_TOKEN'),
            default => null,
        };
        return $key !== null && trim($key) !== '';
    }
}
