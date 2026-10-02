<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\PaymentMethods;
use TripleR\Support\View;

/*
 * What happened to one payment, shown to the customer after the checkout: the receipt when it
 * was paid, and the reason with a way back when it was not. $payment is never still pending.
 */
$e = static fn (mixed $value): string => View::e($value);
$status = $payment['payment_status'];
$paid = $status === 'paid';
$demo = $payment['channel'] === 'online_demo';
$headline = ['paid' => 'Payment received', 'failed' => 'Payment not completed', 'cancelled' => 'Payment cancelled', 'expired' => 'The checkout ran out of time'][$status] ?? 'Payment';
$purpose = $payment['purpose'] === 'downpayment' ? 'Downpayment' : 'Balance';

View::begin('entry', ['title' => $headline, 'variant' => 'solo', 'back' => ['/customer/booking', 'Back to your booking']]);
?>
<?php if ($demo): ?>
<p class="demo-banner" role="note"><strong>Demonstration payment.</strong> No real money was moved.</p>
<?php endif; ?>
<p class="eyebrow"><?= $paid ? 'Receipt' : 'Payment' ?> · booking <span class="mono"><?= $e($booking['booking_reference']) ?></span></p>
<h1><?= $e($headline) ?></h1>
<?php if ($paid): ?>
<p class="notice" role="status">Your <?= $e(strtolower($purpose)) ?> of <?= $e(Format::money($payment['amount'])) ?> was received.<?= $payment['purpose'] === 'downpayment' && $booking['status'] === 'reserved' ? ' The rental office will now confirm your reservation.' : '' ?></p>
<dl class="facts facts--list">
    <div><dt>Receipt number</dt><dd class="mono"><?= $e($payment['receipt_number']) ?></dd></div>
    <div><dt>For</dt><dd><?= $e($purpose) ?></dd></div>
    <div class="facts-total"><dt>Amount paid</dt><dd><?= $e(Format::money($payment['amount'])) ?></dd></div>
    <div><dt>Method</dt><dd><?= $e(PaymentMethods::describe($payment)) ?></dd></div>
<?php if ($payment['external_reference'] !== null): ?>
    <div><dt>Payment reference</dt><dd class="mono"><?= $e($payment['external_reference']) ?></dd></div>
<?php endif; ?>
    <div><dt>Paid on</dt><dd><?= $e(Format::datetime($payment['settled_at'])) ?></dd></div>
    <div><dt>Vehicle</dt><dd><?= $e($booking['vehicle']) ?></dd></div>
    <div><dt>Rental dates</dt><dd><?= $e(Format::date($booking['start_date'])) ?> to <?= $e(Format::date($booking['end_date'])) ?></dd></div>
<?php if ((float) $booking['balance_at_pickup'] > 0): ?>
    <div><dt>Balance due at pickup</dt><dd><?= $e(Format::money($booking['balance_at_pickup'])) ?></dd></div>
<?php endif; ?>
</dl>
<p class="muted">The downpayment is non-refundable. Keep this receipt number; the rental office can find your payment with it.</p>
<?php else: ?>
<p class="callout" role="status"><strong><?= $e(['failed' => 'The payment did not go through.', 'cancelled' => 'You cancelled the payment.', 'expired' => 'The payment was not finished in time.'][$status] ?? 'The payment was not completed.') ?></strong> <?= $payment['failure_reason'] ? $e($payment['failure_reason']) . '. ' : '' ?>Nothing was charged, and your booking is unchanged.</p>
<dl class="facts facts--list">
    <div><dt>Amount</dt><dd><?= $e(Format::money($payment['amount'])) ?></dd></div>
    <div><dt>Method</dt><dd><?= $e(PaymentMethods::describe($payment)) ?></dd></div>
    <div><dt>Attempt</dt><dd class="mono"><?= $e($payment['receipt_number']) ?></dd></div>
</dl>
<?php if ($booking['status'] === 'reserved' && $booking['downpayment_status'] === 'due'): ?>
<p><a class="button button-primary button-block" href="/customer/booking">Try again or pay another way</a></p>
<?php endif; ?>
<?php endif; ?>
<?php View::end(); ?>
