<?php
declare(strict_types=1);

namespace TripleR\Services\Payments;

use TripleR\Config;

final class PaymentGatewayFactory
{
    /**
     * The gateway named by PAYMENT_GATEWAY, or null when paying online is switched off.
     * A setting that cannot work switches it off rather than taking the whole site down;
     * the counter and the proof upload keep working.
     */
    public static function create(): ?PaymentGateway
    {
        $name = strtolower(trim(Config::get('PAYMENT_GATEWAY', '') ?? ''));
        if ($name === '' || $name === 'none') {
            return null;
        }
        try {
            return match ($name) {
                'simulated' => new SimulatedGateway(Config::get('PAYMENT_WEBHOOK_SECRET', '') ?? ''),
                default => throw new \RuntimeException('PAYMENT_GATEWAY must be simulated or none.'),
            };
        } catch (\RuntimeException $error) {
            error_log('Online payment is off: ' . $error->getMessage());
            return null;
        }
    }
}
