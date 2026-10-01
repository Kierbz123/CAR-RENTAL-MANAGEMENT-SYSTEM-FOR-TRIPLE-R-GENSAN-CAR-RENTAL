<?php
declare(strict_types=1);

use TripleR\Support\Format;
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

// Lifecycle actions available to this role at this stage.
$canConfirm = $canOps && $s === 'reserved';
$canPickup = $canTrip && $s === 'confirmed';
$canReturn = $canTrip && $s === 'active';
$canComplete = $canFinance && $s === 'returned';
$canCancel = $canOps && in_array($s, ['reserved', 'confirmed'], true);
$canNoShow = $canCancel && $agreement['scheduled_pickup_at'] !== null;
$hasAction = $canConfirm || $canPickup || $canReturn || $canComplete || $canCancel;
$nextStepHelp = match ($s) {
    'reserved' => $isChauffeur && $agreement['driver_id'] === null ? 'Assign a driver, then confirm the reservation before its hold expires.' : 'Confirm the reservation before its hold expires.',
    'confirmed' => 'Record the pickup when the customer collects the vehicle.',
    'active' => 'Record the return when the vehicle comes back.',
    'returned' => 'Settle charges and the deposit, then complete the agreement.',
    'completed' => 'This agreement is closed.',
    'cancelled' => 'This agreement was cancelled.',
    'no_show' => 'The customer did not collect the vehicle.',
    default => '',
};

View::begin('staff', ['title' => 'Agreement #' . $id, 'crumbs' => [['Agreements', '/rentals'], ['#' . $id, null]], 'scripts' => ['rentals.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow">Agreement #<?= $id ?> · <?= $e(Status::label($agreement['rental_type'])) ?></p>
        <h1><?= $e($agreement['customer_name']) ?></h1>
        <div class="page-meta">
            <?= Status::badge('rental', $s) ?>
<?php if ($isChauffeur && $s === 'reserved' && $agreement['driver_id'] === null): ?>
            <span class="badge badge-danger">Needs driver</span>
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
<?php if (!$hasAction): ?>
                <p class="muted">Nothing for your role to do at this stage.</p>
<?php else: ?>
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
            <div class="panel-heading"><div><h2>Customer’s booking link</h2><p>Sends an SMS with a secure link to view this booking. The customer needs a primary phone number.</p></div></div>
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
