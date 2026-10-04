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
        // The simulated checkout takes no money, so it never runs on the live site: a customer could
        // otherwise mark their own downpayment paid. config/site.php 'is_demo' => false is the live site.
        if ($name === 'simulated' && \TripleR\Support\SiteProfile::get('is_demo', true) !== true) {
            error_log('Online payment is off: the simulated checkout is only for the demonstration site.');
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
