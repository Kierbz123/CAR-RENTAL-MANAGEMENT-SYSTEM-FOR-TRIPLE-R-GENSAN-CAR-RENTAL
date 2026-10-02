<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$id = (int) $agreement['agreement_id'];

View::begin('staff', ['title' => 'Reservation saved', 'crumbs' => [['Agreements', '/rentals'], ['#' . $id, null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow">Reservation saved</p>
        <h1>Agreement #<?= $id ?></h1>
        <p class="page-lead">The vehicle is held until <?= $e(Format::datetime($agreement['hold_expires_at'])) ?>.<?= $agreement['downpayment_status'] === 'due' ? ' To keep it, the customer pays the downpayment: online from their booking link, by a GCash proof, or at the counter, where finance records it. The reservation is then confirmed.' : ' Confirm the reservation to keep it.' ?></p>
    </div>
    <div class="page-header-actions">
        <a class="button button-secondary" href="/rentals">All agreements</a>
        <a class="button button-primary" href="/rentals/detail?agreement_id=<?= $id ?>">Open agreement</a>
    </div>
</header>
<section class="panel" aria-labelledby="saved-title">
    <div class="panel-heading"><h2 id="saved-title">What was saved</h2></div>
    <div class="panel-body">
        <dl class="facts">
            <div><dt>Customer</dt><dd><?= $e($agreement['customer_name']) ?></dd></div>
            <div><dt>Vehicle</dt><dd class="mono"><?= $e($agreement['plate_number']) ?></dd></div>
            <div><dt>Dates</dt><dd><?= $e(Format::date($agreement['start_date'])) ?> to <?= $e(Format::date($agreement['end_date'])) ?></dd></div>
            <div><dt>Days billed</dt><dd><?= (int) $agreement['rental_days'] ?></dd></div>
            <div><dt>Base amount</dt><dd><?= $e(Format::money($agreement['base_amount'])) ?></dd></div>
<?php if ($agreement['downpayment_status'] !== 'not_required'): ?>
            <div><dt>Downpayment to collect (30%, non-refundable)</dt><dd><?= $e(Format::money($agreement['downpayment_amount'])) ?></dd></div>
            <div><dt>Balance due at pickup</dt><dd><?= $e(Format::money((float) $agreement['base_amount'] - (float) $agreement['downpayment_amount'])) ?></dd></div>
<?php endif; ?>
        </dl>
        <p class="muted">If the customer has a primary phone number, a booking link has been queued to send by SMS.</p>
    </div>
</section>
<?php View::end(); ?>
