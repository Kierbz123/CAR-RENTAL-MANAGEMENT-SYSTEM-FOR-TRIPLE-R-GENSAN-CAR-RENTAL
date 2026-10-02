<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\PaymentMethods;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

/*
 * Finance's payments page: GCash proofs waiting for a decision, the money received in the
 * last 30 days by method, the newest payments and online attempts, then recent proof decisions.
 */
$e = static fn (mixed $value): string => View::e($value);
$receivedTotal = 0.0;
$demoTotal = 0.0;
foreach ($received as $row) {
    $receivedTotal += (float) $row['total'];
    if ($row['channel'] === 'online_demo') {
        $demoTotal += (float) $row['total'];
    }
}

View::begin('staff', ['title' => 'Payments', 'crumbs' => [['Payments', null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Payments</h1>
        <p class="page-lead">A downpayment arrives in one of three ways: the customer pays on the online checkout, sends a GCash reference with a screenshot for you to check here, or pays at the counter, where you record it on the agreement. Once it is in, front desk confirms the reservation.</p>
    </div>
</header>
<?php if ($notice): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>

<section class="panel" aria-labelledby="waiting-title">
    <div class="panel-heading"><div><h2 id="waiting-title">Waiting for a decision</h2><p>Longest wait first. A reservation is not cancelled while its proof is waiting here.</p></div><span class="badge <?= $waiting ? 'badge-warning' : 'badge-neutral' ?>"><?= count($waiting) ?></span></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Sent</th><th scope="col">Customer</th><th scope="col">Booking</th><th scope="col" class="num">Downpayment</th><th scope="col">GCash reference</th><th scope="col">Screenshot</th><?php if ($canDecide): ?><th scope="col" class="actions">Decision</th><?php endif; ?></tr></thead>
            <tbody>
<?php foreach ($waiting as $proof): $agreementHref = '/rentals/detail?agreement_id=' . (int) $proof['agreement_id'] . '#downpayment'; ?>
                <tr>
                    <td class="nowrap"><?= $e(Format::datetime($proof['submitted_at'])) ?></td>
                    <td><?= $e($proof['customer_name']) ?><span class="cell-sub"><?= $e($proof['booking_source'] === 'online' ? 'Booked online' : 'Booked at the counter') ?></span></td>
                    <td><a class="cell-strong mono" href="<?= $e($agreementHref) ?>"><?= $e($proof['booking_reference']) ?></a><span class="cell-sub"><span class="mono"><?= $e($proof['plate_number']) ?></span> <?= $e($proof['make'] . ' ' . $proof['model']) ?> · <?= $e(Format::date($proof['start_date'])) ?> to <?= $e(Format::date($proof['end_date'])) ?></span></td>
                    <td class="num"><?= $e(Format::money($proof['downpayment_amount'])) ?></td>
                    <td class="mono"><?= $e($proof['reference_number']) ?></td>
                    <td><a href="/payments/proof?proof_id=<?= (int) $proof['proof_id'] ?>" target="_blank" rel="noopener">Open screenshot</a></td>
<?php if ($canDecide): ?>
                    <td class="actions">
                        <div class="cell-actions">
                            <form method="post" action="/payments/verify" data-confirm="Verify this payment of <?= $e(Format::money($proof['downpayment_amount'])) ?> from <?= $e($proof['customer_name']) ?>? The downpayment is recorded and cannot be changed afterwards." data-confirm-action="Verify payment">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="proof_id" value="<?= (int) $proof['proof_id'] ?>">
                                <button class="button button-primary button-small" type="submit">Verify</button>
                            </form>
                            <details class="disclosure disclosure--danger">
                                <summary>Reject…</summary>
                                <form class="disclosure-body" method="post" action="/payments/reject">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                    <input type="hidden" name="proof_id" value="<?= (int) $proof['proof_id'] ?>">
                                    <label class="field"><span class="field-label">Reason (sent to the customer)</span><input name="reason" maxlength="500" required></label>
                                    <div><button class="button button-danger button-small" type="submit">Reject proof</button></div>
                                </form>
                            </details>
                        </div>
                    </td>
<?php endif; ?>
                </tr>
<?php endforeach; ?>
<?php if (!$waiting): ?>
                <tr><td class="empty-state" colspan="<?= $canDecide ? 7 : 6 ?>"><strong>Nothing to check</strong>Proofs appear here when customers send them.</td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="received" aria-labelledby="received-title">
    <div class="panel-heading"><div><h2 id="received-title">Money received, last 30 days</h2><p>By payment method. Payments made on the demonstration checkout are listed apart: no real money came in for them.</p></div><span class="badge badge-neutral"><?= $e(Format::money($receivedTotal - $demoTotal)) ?> real</span></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Method</th><th scope="col">Received through</th><th scope="col" class="num">Payments</th><th scope="col" class="num">Total</th></tr></thead>
            <tbody>
<?php foreach ($received as $row): ?>
                <tr>
                    <td><?= $e(PaymentMethods::label($row['method'])) ?></td>
                    <td><?php if ($row['channel'] === 'online_demo'): ?><span class="badge badge-info">Demonstration</span><?php else: ?>Counter or verified proof<?php endif; ?></td>
                    <td class="num"><?= (int) $row['payments'] ?></td>
                    <td class="num"><?= $e(Format::money($row['total'])) ?></td>
                </tr>
<?php endforeach; ?>
<?php if (!$received): ?>
                <tr><td class="empty-state" colspan="4"><strong>Nothing received yet</strong>Payments appear here as they are recorded.</td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="recent" aria-labelledby="recent-title">
    <div class="panel-heading"><div><h2 id="recent-title">Latest payments and attempts</h2><p>The last 30, newest first, including online payments that were declined, cancelled or left unfinished.</p></div></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">When</th><th scope="col">Customer</th><th scope="col">Booking</th><th scope="col">For</th><th scope="col">Method</th><th scope="col" class="num">Amount</th><th scope="col">Result</th><th scope="col">Receipt</th></tr></thead>
            <tbody>
<?php foreach ($recent as $payment): ?>
                <tr>
                    <td class="nowrap"><?= $e(Format::datetime($payment['settled_at'] ?? $payment['created_at'])) ?></td>
                    <td><?= $e($payment['customer_name']) ?></td>
                    <td><a class="mono" href="/rentals/detail?agreement_id=<?= (int) $payment['agreement_id'] ?>#payments"><?= $e($payment['booking_reference']) ?></a></td>
                    <td><?= $e($payment['purpose'] === 'downpayment' ? 'Downpayment' : 'Balance') ?></td>
                    <td><?= $e(PaymentMethods::describe($payment)) ?><span class="cell-sub"><?= $e(PaymentMethods::channelLabel($payment)) ?></span></td>
                    <td class="num"><?= $e(Format::money($payment['amount'])) ?></td>
                    <td><?= Status::badge('payment', $payment['payment_status']) ?><?php if ($payment['failure_reason']): ?><span class="cell-sub"><?= $e($payment['failure_reason']) ?></span><?php endif; ?></td>
                    <td><a class="mono" href="/payments/receipt?receipt=<?= $e($payment['receipt_number']) ?>"><?= $e($payment['receipt_number']) ?></a></td>
                </tr>
<?php endforeach; ?>
<?php if (!$recent): ?>
                <tr><td class="empty-state" colspan="8">No payments yet.</td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" aria-labelledby="decided-title">
    <div class="panel-heading"><div><h2 id="decided-title">Recent proof decisions</h2><p>The last 20. A decision cannot be changed.</p></div></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Decided</th><th scope="col">Customer</th><th scope="col">Booking</th><th scope="col" class="num">Downpayment</th><th scope="col">GCash reference</th><th scope="col">Decision</th><th scope="col">By</th></tr></thead>
            <tbody>
<?php foreach ($decided as $proof): ?>
                <tr>
                    <td class="nowrap"><?= $e(Format::datetime($proof['reviewed_at'])) ?></td>
                    <td><?= $e($proof['customer_name']) ?></td>
                    <td><a class="mono" href="/rentals/detail?agreement_id=<?= (int) $proof['agreement_id'] ?>#downpayment"><?= $e($proof['booking_reference']) ?></a></td>
                    <td class="num"><?= $e(Format::money($proof['downpayment_amount'])) ?></td>
                    <td class="mono"><?= $e($proof['reference_number']) ?></td>
                    <td><span class="badge <?= $proof['proof_status'] === 'verified' ? 'badge-success' : 'badge-danger' ?>"><?= $proof['proof_status'] === 'verified' ? 'Verified' : 'Rejected' ?></span><?php if ($proof['review_note']): ?><span class="cell-sub"><?= $e($proof['review_note']) ?></span><?php endif; ?></td>
                    <td><?= $e($proof['reviewed_by_email'] ?? '—') ?></td>
                </tr>
<?php endforeach; ?>
<?php if (!$decided): ?>
                <tr><td class="empty-state" colspan="7">No decisions yet.</td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php View::end(); ?>
