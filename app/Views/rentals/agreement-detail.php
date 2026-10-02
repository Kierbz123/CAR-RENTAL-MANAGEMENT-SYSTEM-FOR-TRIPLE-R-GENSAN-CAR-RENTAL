<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\PaymentMethods;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$id = (int) $agreement['agreement_id'];
$role = $user['role'];
$s = $agreement['status'];
$isChauffeur = $agreement['rental_type'] === 'chauffeur';

// What this role may do. These only decide which controls are shown; the controllers enforce access.
$canOps = in_array($role, ['system_admin', 'front_desk'], true);
$canTrip = in_array($role, ['system_admin', 'front_desk', 'fleet_manager'], true);
$canFinance = in_array($role, ['system_admin', 'finance_staff'], true);
$canDriver = in_array($role, ['system_admin', 'front_desk', 'driver_coordinator'], true);
$canInspect = in_array($role, ['front_desk', 'fleet_manager'], true);
$canDecideLiability = in_array($role, ['fleet_manager', 'system_admin'], true);
$canPostDamageCharge = $role === 'finance_staff';
$closed = in_array($s, ['completed', 'cancelled', 'no_show'], true);
// Driver coordinators only schedule drivers: they see the booking, its dates and the driver panel.
$schedulingOnly = $role === 'driver_coordinator';

$steps = ['reserved' => 'Reserved', 'confirmed' => 'Confirmed', 'active' => 'Active', 'returned' => 'Returned', 'completed' => 'Completed'];
$stepKeys = array_keys($steps);
$currentStep = array_search($s, $stepKeys, true);
$stopped = in_array($s, ['cancelled', 'no_show'], true);

$extras = (float) $total - (float) $agreement['base_amount'];
$vehicleName = trim(($agreement['make'] ?? '') . ' ' . ($agreement['model'] ?? ''));
$defaultPhase = match ($s) { 'active' => 'during', 'returned', 'completed' => 'post', default => 'pre' };

$csrf = '<input type="hidden" name="_csrf" value="' . $e($csrfToken) . '"><input type="hidden" name="agreement_id" value="' . $id . '">';

// The 30% downpayment: it is paid online, by a proof finance verifies, or at the counter, and a
// reservation cannot be confirmed before that.
$downpayment = $agreement['downpayment_status'];
$downpaymentRequired = $downpayment !== 'not_required';
$downpaymentDue = $downpayment === 'due';
// Every payment and online attempt for this agreement. $outstanding is the total less all money received.
$paidDownpayment = null;
$paymentInProgress = null;
$paidTotal = 0.0;
$paidBalance = 0.0;
foreach ($payments as $payment) {
    if ($payment['payment_status'] === 'pending') {
        $paymentInProgress = $payment;
    }
    if ($payment['payment_status'] !== 'paid') {
        continue;
    }
    $paidTotal += (float) $payment['amount'];
    if ($payment['purpose'] === 'downpayment') {
        $paidDownpayment = $payment;
    } else {
        $paidBalance += (float) $payment['amount'];
    }
}
$balanceAtPickup = max(0, (float) $total - (float) $agreement['downpayment_amount'] - $paidBalance);
$owesBalance = $downpayment === 'received' && $outstanding > 0;
$canRecordDownpayment = $canFinance && $downpaymentDue && $s === 'reserved' && $paymentInProgress === null;
$canRecordBalance = $canFinance && !$downpaymentDue && in_array($s, ['confirmed', 'active', 'returned'], true) && $outstanding > 0;
$staffMethods = PaymentMethods::staff();
// Proofs the customer sent from their booking page. Finance decides; finance, administrators and auditors may open the screenshot.
$pendingProof = null;
foreach ($proofs as $proof) {
    if ($proof['proof_status'] === 'submitted') {
        $pendingProof = $proof;
    }
}
$canSeeScreenshots = in_array($role, ['system_admin', 'finance_staff', 'auditor'], true);
$bookedOnline = $agreement['booking_source'] === 'online';

// Lifecycle actions available to this role at this stage.
$canConfirm = $canOps && $s === 'reserved' && !$downpaymentDue;
$canPickup = $canTrip && $s === 'confirmed';
$canReturn = $canTrip && $s === 'active';
$canComplete = $canFinance && $s === 'returned';
$canCancel = $canOps && in_array($s, ['reserved', 'confirmed'], true);
$canNoShow = $canCancel && $agreement['scheduled_pickup_at'] !== null;
$hasAction = $canConfirm || $canPickup || $canReturn || $canComplete || $canCancel;
$nextStepHelp = match ($s) {
    'reserved' => $downpaymentDue
        ? ($isChauffeur && $agreement['driver_id'] === null ? 'Assign a driver and record the customer’s downpayment before the hold expires. Then the reservation can be confirmed.' : 'Record the customer’s downpayment before the hold expires. Then the reservation can be confirmed.')
        : ($isChauffeur && $agreement['driver_id'] === null ? 'Assign a driver, then confirm the reservation.' : ($downpayment === 'received' ? 'The downpayment is in. Confirm the reservation.' : 'Confirm the reservation before its hold expires.')),
    'confirmed' => 'Record the pickup when the customer collects the vehicle.',
    'active' => 'Record the return when the vehicle comes back.',
    'returned' => $owesBalance ? 'Record the balance, settle charges and the deposit, then complete the agreement.' : 'Settle charges and the deposit, then complete the agreement.',
    'completed' => 'This agreement is closed.',
    'cancelled' => 'This agreement was cancelled.',
    'no_show' => 'The customer did not collect the vehicle.',
    default => '',
};

View::begin('staff', ['title' => 'Agreement #' . $id, 'crumbs' => [['Agreements', '/rentals'], ['#' . $id, null]], 'scripts' => ['rentals.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow">Agreement #<?= $id ?> · <?= $e(Status::label($agreement['rental_type'])) ?> · Reference <span class="mono"><?= $e($agreement['booking_reference']) ?></span></p>
        <h1><?= $e($agreement['customer_name']) ?></h1>
        <div class="page-meta">
            <?= Status::badge('rental', $s) ?>
<?php if ($isChauffeur && $s === 'reserved' && $agreement['driver_id'] === null): ?>
            <span class="badge badge-danger">Needs driver</span>
<?php endif; ?>
<?php if ($bookedOnline): ?>
            <span class="badge badge-info">Booked online</span>
<?php endif; ?>
            <span><span class="mono"><?= $e($agreement['plate_number']) ?></span><?= $vehicleName !== '' ? ' ' . $e($vehicleName) : '' ?></span>
            <span><?= $e(Format::date($agreement['start_date'])) ?> to <?= $e(Format::date($agreement['end_date'])) ?></span>
            <span><?= $e(Format::plural((int) $agreement['rental_days'], 'day')) ?></span>
        </div>
    </div>
    <div class="page-header-actions">
        <button class="button button-secondary" type="button" data-print hidden>Print</button>
    </div>
</header>
<?php if ($notice): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>

<?php if ($stopped): ?>
<p class="callout" role="status"><strong><?= $e(Status::label($s)) ?>.</strong> <?= $e($nextStepHelp) ?> The reason is in the history below.</p>
<?php else: ?>
<ol class="stepper" aria-label="Agreement progress">
<?php foreach ($steps as $key => $label):
    $index = array_search($key, $stepKeys, true);
    $state = $index === $currentStep ? ' is-active' : ($index < $currentStep ? ' is-complete' : '');
?>
    <li class="stepper-step<?= $state ?>"<?= $index === $currentStep ? ' aria-current="step"' : '' ?>><span class="stepper-number"><?= $index + 1 ?></span><span class="stepper-label"><?= $e($label) ?></span></li>
<?php endforeach; ?>
</ol>
<?php endif; ?>

<nav class="section-nav" aria-label="On this page">
<?php if ($isChauffeur): ?><a href="#driver">Driver</a><?php endif; ?>
<?php if (!$schedulingOnly): ?>
    <a href="#charges">Charges</a>
<?php if ($downpaymentRequired): ?>
    <a href="#downpayment">Downpayment</a>
<?php endif; ?>
    <a href="#payments">Payments<?= $payments ? ' (' . count($payments) . ')' : '' ?></a>
    <a href="#deposit">Deposit</a>
    <a href="#damage">Damage inspections<?= $damageReports ? ' (' . count($damageReports) . ')' : '' ?></a>
<?php endif; ?>
    <a href="#history">History</a>
</nav>

<div class="split">
    <div class="split-main">
<?php if (!$closed): ?>
        <section class="panel" aria-labelledby="next-title">
            <div class="panel-heading"><div><h2 id="next-title">Next step</h2><p><?= $e($nextStepHelp) ?></p></div></div>
            <div class="panel-body">
<?php if ($s === 'reserved' && $downpaymentDue && !$schedulingOnly): ?>
<?php if ($pendingProof !== null): ?>
                <p class="callout" role="status"><strong>The customer sent proof of the downpayment of <?= $e(Format::money($agreement['downpayment_amount'])) ?>.</strong> <?= $canFinance ? 'Check it under' : 'Finance checks it under' ?> <a href="#downpayment">Downpayment</a>. The reservation is not cancelled while the proof is waiting.</p>
<?php elseif ($paymentInProgress !== null): ?>
                <p class="callout" role="status"><strong>The customer is paying the downpayment of <?= $e(Format::money($agreement['downpayment_amount'])) ?> online right now.</strong> Their checkout is open until <?= $e(Format::time($paymentInProgress['expires_at'])) ?>. The reservation is not cancelled while it is open; reload to see the result.</p>
<?php else: ?>
                <p class="callout" role="status"><strong>Waiting for the downpayment of <?= $e(Format::money($agreement['downpayment_amount'])) ?>.</strong> The customer can pay it online or send a proof from their booking page. For a payment at the counter, <?= $canRecordDownpayment ? 'record it under' : 'finance records it under' ?> <a href="#downpayment">Downpayment</a>. The hold ends <?= $e(Format::datetime($agreement['hold_expires_at'])) ?>.</p>
<?php endif; ?>
<?php endif; ?>
<?php if (!$hasAction && !$canRecordDownpayment): ?>
                <p class="muted">Nothing for your role to do at this stage.</p>
<?php elseif ($hasAction): ?>
                <div class="button-row">
<?php if ($canConfirm): ?>
                    <form method="post" action="/rentals/action">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="confirm">
                        <button class="button button-primary" type="submit">Confirm reservation</button>
                    </form>
<?php endif; ?>
<?php if ($canPickup): ?>
                    <form class="inline-form" method="post" action="/rentals/action">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="pickup">
                        <label class="field"><span class="field-label">Odometer at pickup (km)</span><input type="number" name="mileage" min="0" max="4294967295" step="1" required></label>
                        <button class="button button-primary" type="submit">Record pickup</button>
                    </form>
<?php endif; ?>
<?php if ($canReturn): ?>
                    <form class="inline-form" method="post" action="/rentals/action">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="return">
                        <label class="field"><span class="field-label">Odometer at return (km)</span><input type="number" name="mileage" min="0" max="4294967295" step="1" required></label>
                        <button class="button button-primary" type="submit">Record return</button>
                    </form>
<?php endif; ?>
<?php if ($canComplete): ?>
                    <form method="post" action="/rentals/action">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="complete">
                        <button class="button button-primary" type="submit">Complete agreement</button>
                    </form>
<?php endif; ?>
                </div>
<?php if ($canComplete && in_array($agreement['deposit_status'], ['due', 'held'], true)): ?>
                <p class="callout">The deposit is still <?= $e(strtolower(Status::label($agreement['deposit_status']))) ?>. Record what happened to it under <a href="#deposit">Deposit</a> before completing.</p>
<?php endif; ?>
<?php if ($canComplete && $owesBalance): ?>
                <p class="callout"><?= $e(Format::money($outstanding)) ?> is still owed. Record it under <a href="#payments">Payments</a> before completing.</p>
<?php endif; ?>
<?php if ($canCancel): ?>
                <div class="button-row">
                    <details class="disclosure disclosure--danger">
                        <summary>Cancel this agreement…</summary>
                        <form class="disclosure-body" method="post" action="/rentals/action">
                            <?= $csrf ?>
                            <input type="hidden" name="action" value="cancel">
                            <label class="field"><span class="field-label">Reason for cancelling</span><input name="reason" maxlength="500" required></label>
                            <div><button class="button button-danger button-small" type="submit">Cancel agreement</button></div>
                        </form>
                    </details>
<?php if ($canNoShow): ?>
                    <details class="disclosure disclosure--danger">
                        <summary>Customer didn’t show up…</summary>
                        <form class="disclosure-body" method="post" action="/rentals/action">
                            <?= $csrf ?>
                            <input type="hidden" name="action" value="no_show">
                            <label class="field"><span class="field-label">What happened</span><input name="reason" maxlength="500" required></label>
                            <small class="field-hint">Available once the grace period after the scheduled pickup has passed.</small>
                            <div><button class="button button-danger button-small" type="submit">Mark as no-show</button></div>
                        </form>
                    </details>
<?php endif; ?>
                </div>
<?php endif; ?>
<?php endif; ?>
            </div>
        </section>
<?php endif; ?>

<?php if ($isChauffeur): ?>
        <section class="panel" id="driver" aria-labelledby="driver-title">
            <div class="panel-heading"><div><h2 id="driver-title">Driver</h2><p>A driver must be assigned before a chauffeur booking can be confirmed.</p></div><?= $agreement['driver_id'] ? '<span class="badge badge-success">Assigned</span>' : '<span class="badge badge-warning">Not assigned</span>' ?></div>
            <div class="panel-body">
                <p>Assigned driver: <strong><?= $agreement['driver_id'] ? $e($agreement['driver_name'] ?? '') : 'None yet' ?></strong></p>
<?php if ($canDriver && in_array($s, ['reserved', 'confirmed'], true)): ?>
                <form method="post" action="/rentals/driver/assign" class="inline-form">
                    <?= $csrf ?>
                    <label class="field"><span class="field-label">Driver</span>
                        <select name="driver_id" required>
                            <option value="">Choose a driver</option>
<?php foreach ($drivers ?? [] as $d): ?>
                            <option value="<?= (int) $d['driver_id'] ?>"<?= (string) ($agreement['driver_id'] ?? '') === (string) $d['driver_id'] ? ' selected' : '' ?>><?= $e($d['full_name']) ?></option>
<?php endforeach; ?>
                        </select>
                    </label>
                    <button class="button button-primary" type="submit"><?= $agreement['driver_id'] ? 'Change driver' : 'Assign driver' ?></button>
                </form>
<?php if ($agreement['driver_id'] !== null && $s === 'reserved'): ?>
                <form method="post" action="/rentals/driver/remove">
                    <?= $csrf ?>
                    <button class="button button-danger button-small" type="submit">Remove driver</button>
                </form>
<?php endif; ?>
<?php endif; ?>
            </div>
        </section>
<?php endif; ?>

<?php if (!$schedulingOnly): ?>
        <section class="panel" id="charges" aria-labelledby="charges-title">
            <div class="panel-heading"><div><h2 id="charges-title">Charges</h2><p>Fees, discounts and taxes on top of the base amount. Entries are never edited; a wrong one is reversed.</p></div></div>
            <div class="table-wrap">
                <table class="data-table" data-stack>
                    <thead><tr><th scope="col">Type</th><th scope="col">Description</th><th scope="col" class="num">Amount</th><th scope="col">Recorded</th><th scope="col" class="actions"><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
<?php foreach ($charges as $c): ?>
                        <tr>
                            <td><?= $e(Status::label($c['charge_type'])) ?><?php if ($c['entry_kind'] !== 'charge'): ?> <span class="badge badge-neutral"><?= $e(Status::label($c['entry_kind'])) ?></span><?php endif; ?></td>
                            <td><?= $e($c['description']) ?></td>
                            <td class="num"><?= $e(Format::money($c['amount'])) ?></td>
                            <td><span class="nowrap"><?= $e(Format::datetime($c['created_at'])) ?></span><span class="cell-sub"><?= $e($c['actor_email']) ?></span></td>
                            <td class="actions" data-label="">
<?php if ($canFinance && $c['entry_kind'] === 'charge'): ?>
                                <details class="disclosure">
                                    <summary>Reverse</summary>
                                    <form class="disclosure-body" method="post" action="/rentals/charge/reverse">
                                        <?= $csrf ?>
                                        <input type="hidden" name="charge_id" value="<?= (int) $c['charge_id'] ?>">
                                        <label class="field"><span class="field-label">Reason for reversing</span><input name="reason" maxlength="490" required></label>
                                        <div><button class="button button-danger button-small" type="submit">Reverse charge</button></div>
                                    </form>
                                </details>
<?php endif; ?>
                            </td>
                        </tr>
<?php endforeach; ?>
<?php if (!$charges): ?>
                        <tr><td class="empty-state" colspan="5">No charges beyond the base amount.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
<?php if ($canFinance && !$closed): ?>
            <form class="toolbar" method="post" action="/rentals/charge">
                <?= $csrf ?>
                <label class="field"><span class="field-label">Type</span>
                    <select name="charge_type">
<?php foreach (['fee', 'discount', 'tax', 'damage', 'other'] as $chargeType): ?>
                        <option value="<?= $chargeType ?>"><?= $e(Status::label($chargeType)) ?></option>
<?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span class="field-label">Amount (₱)</span><input name="amount" type="number" min="0.01" step="0.01" required></label>
                <label class="field"><span class="field-label">Description</span><input name="description" maxlength="500" required></label>
                <button class="button button-secondary" type="submit">Add charge</button>
            </form>
<?php endif; ?>
        </section>

<?php if ($downpaymentRequired): ?>
        <section class="panel" id="downpayment" aria-labelledby="downpayment-title">
            <div class="panel-heading"><div><h2 id="downpayment-title">Downpayment</h2><p>30% of the rental as booked, paid before the reservation is confirmed: online, by a GCash proof that finance verifies, or at the counter. It is non-refundable. The balance is paid in person at pickup.</p></div><?= Status::badge('downpayment', $downpayment) ?></div>
            <div class="panel-body">
                <dl class="facts">
                    <div><dt>Downpayment</dt><dd><?= $e(Format::money($agreement['downpayment_amount'])) ?></dd></div>
                    <div><dt>Balance due at pickup</dt><dd><?= $e(Format::money($balanceAtPickup)) ?></dd></div>
<?php if ($paidDownpayment !== null): ?>
                    <div><dt>Paid by</dt><dd><?= $e(PaymentMethods::describe($paidDownpayment)) ?><span class="cell-sub"><?= $e(PaymentMethods::channelLabel($paidDownpayment)) ?></span></dd></div>
                    <div><dt><?= $paidDownpayment['external_reference'] !== null ? 'Reference' : 'Receipt' ?></dt><dd class="mono"><?= $e($paidDownpayment['external_reference'] ?? $paidDownpayment['receipt_number']) ?></dd></div>
                    <div><dt>Received</dt><dd><?= $e(Format::datetime($paidDownpayment['settled_at'])) ?><span class="cell-sub"><?= $e($paidDownpayment['recorded_by_email'] ?? 'By the customer, online') ?></span></dd></div>
<?php endif; ?>
                </dl>
<?php if ($policyAcceptance !== null): ?>
                <p class="muted">The customer accepted the <?= $e($policyAcceptance['title']) ?> (version <?= (int) $policyAcceptance['version_number'] ?>) online on <?= $e(Format::datetime($policyAcceptance['recorded_at'])) ?>.</p>
<?php elseif ($downpaymentRequired): ?>
                <p class="muted">No online acceptance of the downpayment policy is recorded: this booking was made at the counter.</p>
<?php endif; ?>
<?php if ($proofs): ?>
                <div class="table-wrap">
                    <table class="data-table" data-stack>
                        <thead><tr><th scope="col">Proof sent</th><th scope="col">GCash reference</th><th scope="col">Screenshot</th><th scope="col">Decision</th></tr></thead>
                        <tbody>
<?php foreach ($proofs as $proof): ?>
                            <tr>
                                <td class="nowrap"><?= $e(Format::datetime($proof['submitted_at'])) ?></td>
                                <td class="mono"><?= $e($proof['reference_number']) ?></td>
                                <td><?php if ($canSeeScreenshots): ?><a href="/payments/proof?proof_id=<?= (int) $proof['proof_id'] ?>" target="_blank" rel="noopener">Open screenshot</a><?php else: ?><span class="muted">Finance only</span><?php endif; ?></td>
                                <td>
<?php if ($proof['proof_status'] === 'submitted' && $canFinance): ?>
                                    <div class="cell-actions">
                                        <form method="post" action="/payments/verify" data-confirm="Verify this payment of <?= $e(Format::money($agreement['downpayment_amount'])) ?>? The downpayment is recorded and cannot be changed afterwards." data-confirm-action="Verify payment">
                                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="proof_id" value="<?= (int) $proof['proof_id'] ?>">
                                            <input type="hidden" name="return" value="agreement">
                                            <button class="button button-primary button-small" type="submit">Verify</button>
                                        </form>
                                        <details class="disclosure disclosure--danger">
                                            <summary>Reject…</summary>
                                            <form class="disclosure-body" method="post" action="/payments/reject">
                                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                                <input type="hidden" name="proof_id" value="<?= (int) $proof['proof_id'] ?>">
                                                <input type="hidden" name="return" value="agreement">
                                                <label class="field"><span class="field-label">Reason (sent to the customer)</span><input name="reason" maxlength="500" required></label>
                                                <div><button class="button button-danger button-small" type="submit">Reject proof</button></div>
                                            </form>
                                        </details>
                                    </div>
<?php elseif ($proof['proof_status'] === 'submitted'): ?>
                                    <span class="badge badge-warning">Waiting for finance</span>
<?php else: ?>
                                    <span class="badge <?= $proof['proof_status'] === 'verified' ? 'badge-success' : 'badge-danger' ?>"><?= $proof['proof_status'] === 'verified' ? 'Verified' : 'Rejected' ?></span>
                                    <span class="cell-sub"><?= $e(Format::datetime($proof['reviewed_at'])) ?> · <?= $e($proof['reviewed_by_email'] ?? '') ?><?= $proof['review_note'] ? ' · ' . $e($proof['review_note']) : '' ?></span>
<?php endif; ?>
                                </td>
                            </tr>
<?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
<?php endif; ?>
<?php if ($canRecordDownpayment && $pendingProof === null): ?>
                <form class="toolbar" method="post" action="/rentals/downpayment" data-confirm="Record that the downpayment of <?= $e(Format::money($agreement['downpayment_amount'])) ?> was received? This cannot be changed afterwards." data-confirm-action="Record downpayment">
                    <?= $csrf ?>
                    <label class="field"><span class="field-label">Paid by</span>
                        <select name="method">
<?php foreach ($staffMethods as $methodKey => $method): ?>
                            <option value="<?= $e($methodKey) ?>"><?= $e($method['label']) ?></option>
<?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field"><span class="field-label">Reference number</span><input name="reference" maxlength="40" autocomplete="off" pattern="[A-Za-z0-9 \-]{6,40}"><small class="field-hint">For a payment made at the counter. Leave it blank for cash. For anything else, check the account first; each reference can be used once.</small></label>
                    <button class="button button-primary" type="submit">Record downpayment</button>
                </form>
<?php elseif ($downpaymentDue && $paymentInProgress !== null && $s === 'reserved'): ?>
                <p class="muted">The customer has a checkout open. A payment at the counter can be recorded once it closes.</p>
<?php elseif ($downpaymentDue && $s === 'reserved'): ?>
                <p class="muted">Finance or an administrator records a payment made at the counter here.</p>
<?php elseif ($downpaymentDue): ?>
                <p class="muted">No downpayment was received before this agreement ended.</p>
<?php endif; ?>
            </div>
        </section>
<?php endif; ?>

        <section class="panel" id="payments" aria-labelledby="payments-title">
            <div class="panel-heading"><div><h2 id="payments-title">Payments</h2><p>Money received for this agreement and every attempt on the online checkout. A payment is never edited or removed.</p></div><?php if ($owesBalance): ?><span class="badge badge-warning"><?= $e(Format::money($outstanding)) ?> owed</span><?php elseif ($paidTotal > 0): ?><span class="badge badge-success">Paid in full</span><?php endif; ?></div>
            <div class="panel-body">
                <dl class="facts">
                    <div><dt>Total to bill</dt><dd><?= $e(Format::money($total)) ?></dd></div>
                    <div><dt>Received so far</dt><dd><?= $e(Format::money($paidTotal)) ?></dd></div>
                    <div><dt>Still owed</dt><dd><?= $e(Format::money($outstanding)) ?></dd></div>
                </dl>
            </div>
            <div class="table-wrap">
                <table class="data-table" data-stack>
                    <thead><tr><th scope="col">When</th><th scope="col">For</th><th scope="col">Method</th><th scope="col" class="num">Amount</th><th scope="col">Result</th><th scope="col">Receipt</th></tr></thead>
                    <tbody>
<?php foreach ($payments as $payment): ?>
                        <tr>
                            <td class="nowrap"><?= $e(Format::datetime($payment['settled_at'] ?? $payment['created_at'])) ?></td>
                            <td><?= $e($payment['purpose'] === 'downpayment' ? 'Downpayment' : 'Balance') ?></td>
                            <td><?= $e(PaymentMethods::describe($payment)) ?><span class="cell-sub"><?= $e(PaymentMethods::channelLabel($payment)) ?><?= $payment['recorded_by_email'] ? ' · ' . $e($payment['recorded_by_email']) : '' ?></span></td>
                            <td class="num"><?= $e(Format::money($payment['amount'])) ?></td>
                            <td><?= Status::badge('payment', $payment['payment_status']) ?><?php if ($payment['failure_reason']): ?><span class="cell-sub"><?= $e($payment['failure_reason']) ?></span><?php endif; ?></td>
                            <td><?php if ($canSeeScreenshots): ?><a class="mono" href="/payments/receipt?receipt=<?= $e($payment['receipt_number']) ?>"><?= $e($payment['receipt_number']) ?></a><?php else: ?><span class="mono"><?= $e($payment['receipt_number']) ?></span><?php endif; ?><?php if ($payment['external_reference'] !== null): ?><span class="cell-sub mono"><?= $e($payment['external_reference']) ?></span><?php endif; ?></td>
                        </tr>
<?php endforeach; ?>
<?php if (!$payments): ?>
                        <tr><td class="empty-state" colspan="6">No payments yet.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
<?php if ($canRecordBalance): ?>
            <form class="toolbar" method="post" action="/rentals/payment" data-confirm="Record this payment toward the balance? It cannot be changed afterwards." data-confirm-action="Record payment">
                <?= $csrf ?>
                <label class="field"><span class="field-label">Paid by</span>
                    <select name="method">
<?php foreach ($staffMethods as $methodKey => $method): ?>
                        <option value="<?= $e($methodKey) ?>"<?= $methodKey === 'cash' ? ' selected' : '' ?>><?= $e($method['label']) ?></option>
<?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span class="field-label">Amount (₱)</span><input name="amount" type="number" min="0.01" max="<?= $e(number_format($outstanding, 2, '.', '')) ?>" step="0.01" value="<?= $e(number_format($outstanding, 2, '.', '')) ?>" required></label>
                <label class="field"><span class="field-label">Reference number</span><input name="reference" maxlength="40" autocomplete="off" pattern="[A-Za-z0-9 \-]{6,40}"><small class="field-hint">Leave it blank for cash.</small></label>
                <button class="button button-primary" type="submit">Record balance payment</button>
            </form>
<?php elseif ($owesBalance && !$closed): ?>
            <p class="panel-note muted"><?= $s === 'reserved' ? 'The balance is recorded here once the reservation is confirmed.' : 'Finance or an administrator records the balance here when the customer pays it.' ?></p>
<?php endif; ?>
        </section>

        <section class="panel" id="deposit" aria-labelledby="deposit-title">
            <div class="panel-heading"><div><h2 id="deposit-title">Deposit</h2><p>An agreement can’t be completed while the deposit is due or held.</p></div><?= Status::badge('deposit', $agreement['deposit_status']) ?></div>
            <div class="table-wrap">
                <table class="data-table" data-stack>
                    <thead><tr><th scope="col">When</th><th scope="col">Change</th><th scope="col" class="num">Amount</th><th scope="col">Reason</th><th scope="col">By</th></tr></thead>
                    <tbody>
<?php foreach ($depositHistory as $d): ?>
                        <tr>
                            <td class="nowrap"><?= $e(Format::datetime($d['created_at'])) ?></td>
                            <td><?= $e($d['old_status'] === null ? 'Set' : Status::label($d['old_status'])) ?> → <?= $e(Status::label($d['new_status'])) ?></td>
                            <td class="num"><?= $d['old_amount'] === null || (float) $d['old_amount'] === (float) $d['new_amount'] ? '' : $e(Format::money($d['old_amount'])) . ' → ' ?><?= $e(Format::money($d['new_amount'])) ?></td>
                            <td><?= $e($d['reason']) ?></td>
                            <td><?= $e($d['actor_email']) ?></td>
                        </tr>
<?php endforeach; ?>
<?php if (!$depositHistory): ?>
                        <tr><td class="empty-state" colspan="5">No deposit changes recorded.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
<?php if ($canFinance && !$closed): ?>
            <form class="toolbar" method="post" action="/rentals/deposit">
                <?= $csrf ?>
                <label class="field"><span class="field-label">Deposit status</span>
                    <select name="deposit_status">
<?php foreach (['not_required', 'due', 'held', 'released', 'refunded', 'forfeited'] as $ds): ?>
                        <option value="<?= $e($ds) ?>"<?= $agreement['deposit_status'] === $ds ? ' selected' : '' ?>><?= $e(Status::label($ds)) ?></option>
<?php endforeach; ?>
                    </select>
                </label>
                <label class="field"><span class="field-label">Amount (₱)</span><input name="amount" type="number" min="0" step="0.01" value="<?= $e($agreement['security_deposit_amount']) ?>" required></label>
                <label class="field"><span class="field-label">Reason</span><input name="reason" maxlength="500" required></label>
                <button class="button button-secondary" type="submit">Record change</button>
            </form>
<?php endif; ?>
        </section>

        <section class="panel" id="damage" aria-labelledby="damage-title">
            <div class="panel-heading"><div><h2 id="damage-title">Damage inspections</h2><p>One inspection before the rental, one after, and as many as needed during it. Inspections and photos can’t be changed once saved.</p></div></div>
<?php if ($canInspect): ?>
            <div class="panel-body">
                <details class="disclosure disclosure--button">
                    <summary>Record an inspection</summary>
                    <form class="disclosure-body" method="post" action="/rentals/damage/report" enctype="multipart/form-data">
                        <?= $csrf ?>
                        <div class="form-grid">
                            <label class="field"><span class="field-label">When</span>
                                <select name="phase">
<?php foreach (['pre', 'during', 'post'] as $phase): ?>
                                    <option value="<?= $phase ?>"<?= $defaultPhase === $phase ? ' selected' : '' ?>><?= $e(Status::label($phase)) ?></option>
<?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field"><span class="field-label">Damage found?</span>
                                <select name="has_damage"><option value="0">No damage</option><option value="1">Yes, damage found</option></select>
                            </label>
                        </div>
                        <p class="muted">Fill in the next four fields only if damage was found.</p>
                        <div class="form-grid">
                            <label class="field"><span class="field-label">Where on the vehicle</span><input name="location" maxlength="120" placeholder="e.g. left rear door"></label>
                            <label class="field"><span class="field-label">Kind of damage</span><input name="damage_type" maxlength="40" placeholder="e.g. dent"></label>
                            <label class="field"><span class="field-label">Severity</span>
                                <select name="severity"><option value="">Choose</option><option value="minor">Minor</option><option value="moderate">Moderate</option><option value="severe">Severe</option></select>
                            </label>
                            <label class="field"><span class="field-label">Estimated repair cost (₱)</span><input name="repair_cost_suggestion" type="number" min="0" step="0.01"></label>
                            <label class="field field--wide"><span class="field-label">Notes</span><input name="notes" maxlength="1000"></label>
                            <label class="field field--wide"><span class="field-label">Photos</span><input name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple><small class="field-hint">JPEG, PNG or WebP, up to 8 MB each. At least one is needed when damage is found during or after the rental.</small></label>
                        </div>
                        <div><button class="button button-primary" type="submit">Save inspection</button></div>
                    </form>
                </details>
            </div>
<?php endif; ?>
<?php if (!$damageReports): ?>
            <p class="empty-state">No inspections recorded yet.</p>
<?php endif; ?>
<?php foreach ($damageReports as $dr): $hasDamage = (bool) $dr['has_damage']; ?>
            <article class="record-card">
                <div class="record-card-heading">
                    <h3><a href="/rentals/damage/detail?report_id=<?= (int) $dr['report_id'] ?>"><?= $e(Status::label($dr['phase'])) ?> inspection</a></h3>
                    <span class="badge <?= $hasDamage ? 'badge-danger' : 'badge-success' ?>"><?= $hasDamage ? 'Damage reported' : 'No damage' ?></span>
<?php if ($hasDamage && $dr['severity']): ?>
                    <?= Status::badge('severity', $dr['severity']) ?>
<?php endif; ?>
                </div>
                <p class="timeline-meta"><?= $e(Format::datetime($dr['created_at'])) ?> · <?= $e($dr['recorded_by_email']) ?></p>
<?php if ($hasDamage): ?>
                <dl class="facts">
                    <div><dt>Where</dt><dd><?= $e($dr['location']) ?></dd></div>
                    <div><dt>Kind</dt><dd><?= $e($dr['damage_type']) ?></dd></div>
                    <div><dt>Estimated repair</dt><dd><?= $e(Format::money($dr['repair_cost_suggestion'])) ?></dd></div>
                </dl>
<?php endif; ?>
<?php if ($dr['notes']): ?>
                <p class="timeline-note"><?= $e($dr['notes']) ?></p>
<?php endif; ?>
<?php if ($dr['photos']): ?>
                <ul class="file-list">
<?php foreach ($dr['photos'] as $photo): ?>
                    <li><a href="/rentals/damage/photo?photo_id=<?= (int) $photo['photo_id'] ?>" target="_blank" rel="noopener"><?= $e($photo['original_filename']) ?></a></li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
<?php if ($hasDamage && $dr['decision_id']): ?>
                <p><strong><?= $dr['customer_liable'] ? 'Customer liable' : 'Customer not liable' ?></strong> · <?= $e(Format::money($dr['liable_amount'])) ?> · <?= $e($dr['liability_reason']) ?> <span class="muted">(<?= $e(Format::datetime($dr['decided_at'])) ?>)</span></p>
<?php endif; ?>
<?php if ($hasDamage && $canDecideLiability): ?>
                <details class="disclosure">
                    <summary><?= $dr['decision_id'] ? 'Replace the liability decision' : 'Record a liability decision' ?></summary>
                    <form class="disclosure-body" method="post" action="/rentals/damage/liability">
                        <?= $csrf ?>
                        <input type="hidden" name="report_id" value="<?= (int) $dr['report_id'] ?>">
                        <input type="hidden" name="supersedes_decision_id" value="<?= (int) ($dr['decision_id'] ?? 0) ?: '' ?>">
                        <div class="form-grid">
                            <label class="field"><span class="field-label">Finding</span>
                                <select name="customer_liable"><option value="1">Customer liable</option><option value="0">Customer not liable</option></select>
                            </label>
                            <label class="field"><span class="field-label">Amount the customer owes (₱)</span><input name="liable_amount" type="number" min="0" step="0.01" value="<?= $e($dr['decision_id'] ? $dr['liable_amount'] : ($dr['repair_cost_suggestion'] ?? '0')) ?>" required></label>
                            <label class="field field--wide"><span class="field-label"><?= $dr['decision_id'] ? 'Reason for the new decision' : 'Reason' ?></span><input name="reason" maxlength="1000" required></label>
                        </div>
                        <div><button class="button button-primary button-small" type="submit"><?= $dr['decision_id'] ? 'Replace decision' : 'Save decision' ?></button></div>
                    </form>
                </details>
<?php endif; ?>
<?php if ($dr['decision_id'] && (int) $dr['customer_liable'] === 1 && $canPostDamageCharge): ?>
                <details class="disclosure">
                    <summary>Post the damage charge</summary>
                    <form class="disclosure-body" method="post" action="/rentals/damage/charge">
                        <?= $csrf ?>
                        <input type="hidden" name="decision_id" value="<?= (int) $dr['decision_id'] ?>">
                        <div class="form-grid">
                            <label class="field"><span class="field-label">Charge amount (₱)</span><input name="amount" type="number" min="0.01" max="<?= $e($dr['liable_amount']) ?>" step="0.01" value="<?= $e($dr['liable_amount']) ?>" required><small class="field-hint">Up to <?= $e(Format::money($dr['liable_amount'])) ?>.</small></label>
                            <label class="field"><span class="field-label">Reason, if less than the full amount</span><input name="adjustment_reason" maxlength="500"></label>
                        </div>
                        <div><button class="button button-primary button-small" type="submit">Post charge</button></div>
                    </form>
                </details>
<?php endif; ?>
            </article>
<?php endforeach; ?>
        </section>

<?php endif; ?>

        <section class="panel" id="history" aria-labelledby="history-title">
            <div class="panel-heading"><div><h2 id="history-title">History</h2><p>Times are in Manila time.</p></div></div>
            <div class="panel-body">
<?php if (!$statusHistory): ?>
                <p class="muted">No changes recorded.</p>
<?php else: ?>
                <ol class="timeline">
<?php foreach ($statusHistory as $h): ?>
                    <li>
                        <div class="timeline-title"><?= $h['old_status'] === null ? 'Created as' : $e(Status::label($h['old_status'])) . ' →' ?> <?= Status::badge('rental', $h['new_status']) ?></div>
                        <div class="timeline-meta"><?= $e(Format::datetime($h['created_at'])) ?> · <?= $e($h['actor_email']) ?></div>
<?php if ($h['reason']): ?>
                        <div class="timeline-note"><?= $e($h['reason']) ?></div>
<?php endif; ?>
                    </li>
<?php endforeach; ?>
                </ol>
<?php endif; ?>
            </div>
        </section>
    </div>

    <aside class="split-side" aria-label="Agreement summary">
<?php if (!$schedulingOnly): ?>
        <section class="panel">
            <div class="panel-heading"><h2>Cost summary</h2></div>
            <div class="panel-body">
                <dl class="facts facts--list">
                    <div><dt><?= $e(Format::plural((int) $agreement['rental_days'], 'day')) ?> × <?= $e(Format::money($agreement['daily_rate'] ?? null)) ?></dt><dd><?= $e(Format::money($agreement['base_amount'])) ?></dd></div>
                    <div><dt>Charges and discounts</dt><dd><?= $e(Format::money($extras)) ?></dd></div>
                    <div class="facts-total"><dt>Total to bill</dt><dd><?= $e(Format::money($total)) ?></dd></div>
<?php if ($downpaymentRequired): ?>
                    <div><dt>Downpayment (<?= $e(strtolower(Status::label($downpayment))) ?>)</dt><dd><?= $e(Format::money($agreement['downpayment_amount'])) ?></dd></div>
                    <div><dt>Balance due at pickup</dt><dd><?= $e(Format::money($balanceAtPickup)) ?></dd></div>
<?php endif; ?>
                    <div><dt>Received so far</dt><dd><?= $e(Format::money($paidTotal)) ?></dd></div>
                    <div><dt>Security deposit</dt><dd><?= $e(Format::money($agreement['security_deposit_amount'])) ?></dd></div>
                    <div><dt>Deposit status</dt><dd><?= $e(Status::label($agreement['deposit_status'])) ?></dd></div>
                </dl>
            </div>
        </section>
<?php endif; ?>
        <section class="panel">
            <div class="panel-heading"><h2>Schedule</h2></div>
            <div class="panel-body">
                <dl class="facts facts--list">
                    <div><dt>Scheduled pickup</dt><dd><?= $e($agreement['scheduled_pickup_at'] ? Format::datetime($agreement['scheduled_pickup_at']) : 'Not set') ?></dd></div>
                    <div><dt>Scheduled return</dt><dd><?= $e($agreement['scheduled_return_at'] ? Format::datetime($agreement['scheduled_return_at']) : 'Not set') ?></dd></div>
<?php if (!empty($agreement['actual_pickup_at'])): ?>
                    <div><dt>Picked up</dt><dd><?= $e(Format::datetime($agreement['actual_pickup_at'])) ?></dd></div>
<?php endif; ?>
<?php if (!empty($agreement['actual_return_at'])): ?>
                    <div><dt>Returned</dt><dd><?= $e(Format::datetime($agreement['actual_return_at'])) ?></dd></div>
<?php endif; ?>
                </dl>
                <p class="muted">Times are in Manila time.</p>
            </div>
        </section>
<?php if ($canOps): ?>
        <section class="panel">
            <div class="panel-heading"><div><h2>Customer’s booking link</h2><p>Sends a secure link to the customer’s booking page, where they see what to pay and send their proof. The customer needs a primary phone number. They can also open it with reference <span class="mono"><?= $e($agreement['booking_reference']) ?></span> and their mobile number.</p></div></div>
            <div class="panel-body">
                <form method="post" action="/rentals/link">
                    <?= $csrf ?>
                    <button class="button button-secondary" type="submit">Send booking link</button>
                </form>
            </div>
        </section>
<?php endif; ?>
    </aside>
</div>
<?php View::end(); ?>
