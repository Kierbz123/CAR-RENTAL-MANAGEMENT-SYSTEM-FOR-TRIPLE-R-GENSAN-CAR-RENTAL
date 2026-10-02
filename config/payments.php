<?php
declare(strict_types=1);

/*
 * PAYMENT METHODS
 * ---------------
 * Every way a booking can be paid. The keys match the `method` column of the payments table;
 * adding a method means one entry here and one value in that column's ENUM.
 *
 *   online  the customer can choose it on the checkout. The checkout is SIMULATED
 *           (PAYMENT_GATEWAY=simulated): it shows what the system does when a payment is
 *           approved, declined, cancelled or left to time out. No real money moves.
 *   staff   finance can record it on the agreement page for money received at the counter.
 *   reference_label  what the outside reference is called for that method. Cash has none:
 *           the system's own receipt number is the record.
 */

return [
    'methods' => [
        'gcash' => ['label' => 'GCash', 'kind' => 'E-wallet', 'online' => true, 'staff' => true, 'reference_label' => 'GCash reference number'],
        'maya' => ['label' => 'Maya', 'kind' => 'E-wallet', 'online' => true, 'staff' => true, 'reference_label' => 'Maya reference number'],
        'grabpay' => ['label' => 'GrabPay', 'kind' => 'E-wallet', 'online' => true, 'staff' => true, 'reference_label' => 'GrabPay reference number'],
        'card' => ['label' => 'Credit or debit card', 'kind' => 'Card', 'online' => true, 'staff' => true, 'reference_label' => 'Card terminal approval code'],
        'online_banking' => ['label' => 'Online banking', 'kind' => 'Bank', 'online' => true, 'staff' => true, 'reference_label' => 'Bank transfer reference'],
        'cash' => ['label' => 'Cash', 'kind' => 'Cash', 'online' => false, 'staff' => true, 'reference_label' => null],
    ],

    // Banks offered on the simulated online-banking screen.
    'banks' => ['BPI', 'BDO', 'UnionBank'],

    /*
     * The only card numbers the simulated checkout accepts. Each one produces one outcome, so a
     * presenter can show an approval and each kind of refusal. Any other number is turned away
     * and nothing about it is kept, so a real card can never be entered by mistake.
     */
    'test_cards' => [
        '4242424242424242' => ['brand' => 'Visa', 'outcome' => 'approved', 'shows' => 'Approved'],
        '5555555555554444' => ['brand' => 'Mastercard', 'outcome' => 'approved', 'shows' => 'Approved'],
        '4000000000000002' => ['brand' => 'Visa', 'outcome' => 'declined', 'shows' => 'Declined by the bank'],
        '4000000000009995' => ['brand' => 'Visa', 'outcome' => 'insufficient', 'shows' => 'Not enough funds'],
        '4000000000000069' => ['brand' => 'Visa', 'outcome' => 'expired_card', 'shows' => 'Card has expired'],
    ],
];
