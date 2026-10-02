<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\PaymentMethods;
use TripleR\Support\SiteProfile;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

/* One payment, laid out to print or to read back to the customer. */
$e = static fn (mixed $value): string => View::e($value);
$paid = $payment['payment_status'] === 'paid';
$agreementHref = '/rentals/detail?agreement_id=' . (int) $payment['agreement_id'] . '#payments';

View::begin('staff', ['title' => 'Receipt ' . $payment['receipt_number'], 'crumbs' => [['Payments', '/payments'], [$payment['receipt_number'], null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1><?= $paid ? 'Receipt' : 'Payment attempt' ?> <span class="mono"><?= $e($payment['receipt_number']) ?></span></h1>
        <p class="page-lead"><?= $e(SiteProfile::get('brand.full_name')) ?> · <?= $e(implode(', ', (array) SiteProfile::get('contact.address_lines', []))) ?></p>
    </div>
    <div class="page-header-actions">
        <button class="button button-secondary" type="button" data-print hidden>Print</button>
    </div>
</header>
<?php if ($payment['channel'] === 'online_demo'): ?>
<p class="demo-banner" role="note"><strong>Demonstration payment.</strong> It was made on the simulated checkout. No real money was received.</p>
<?php endif; ?>
<section class="panel" aria-labelledby="receipt-title">
    <div class="panel-heading"><div><h2 id="receipt-title"><?= $e($payment['purpose'] === 'downpayment' ? 'Downpayment' : 'Balance payment') ?></h2><p><?= $e($payment['customer_name']) ?> · booking <a class="mono" href="<?= $e($agreementHref) ?>"><?= $e($payment['booking_reference']) ?></a></p></div><?= Status::badge('payment', $payment['payment_status']) ?></div>
    <div class="panel-body">
        <dl class="facts">
            <div><dt>Amount</dt><dd><?= $e(Format::money($payment['amount'])) ?></dd></div>
            <div><dt>Method</dt><dd><?= $e(PaymentMethods::describe($payment)) ?></dd></div>
            <div><dt>Received through</dt><dd><?= $e(PaymentMethods::channelLabel($payment)) ?></dd></div>
            <div><dt>Reference</dt><dd class="mono"><?= $e($payment['external_reference'] ?? '—') ?></dd></div>
            <div><dt><?= $paid ? 'Received' : 'Closed' ?></dt><dd><?= $e(Format::datetime($payment['settled_at'])) ?></dd></div>
            <div><dt>Recorded by</dt><dd><?= $e($payment['recorded_by_email'] ?? 'The customer, online') ?></dd></div>
<?php if ($payment['failure_reason'] !== null): ?>
            <div><dt>Why it was not paid</dt><dd><?= $e($payment['failure_reason']) ?></dd></div>
<?php endif; ?>
        </dl>
    </div>
</section>
<?php View::end(); ?>
