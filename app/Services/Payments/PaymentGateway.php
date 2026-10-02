<?php
declare(strict_types=1);

namespace TripleR\Services\Payments;

/**
 * What the booking system needs from an online payment gateway, and nothing more: somewhere
 * to send the customer, and a way to be sure a result really came from the gateway.
 *
 * SimulatedGateway is the only implementation. A real gateway would be a second class beside
 * it; PaymentService and the payments table would not change.
 */
interface PaymentGateway
{
    /** The value stored in payments.channel for payments taken through this gateway. */
    public function channel(): string;

    /** Where the customer is sent to pay a pending payment (a row of the payments table). */
    public function checkoutUrl(array $payment): string;

    /**
     * Checks the signature on a result and returns what it says, or null when the signature
     * does not match or the result is not one this system understands.
     *
     * @return array{receipt:string,result:string,amount:string,reference:?string,detail:?string,reason:?string}|null
     *         result is one of paid, failed, cancelled, expired
     */
    public function verifiedResult(string $payload, string $signature): ?array;
}
