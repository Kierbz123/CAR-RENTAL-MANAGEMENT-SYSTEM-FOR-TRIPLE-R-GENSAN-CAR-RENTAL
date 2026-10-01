<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\Icon;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$canConfigure = in_array($user['role'], ['fleet_manager', 'system_admin'], true);
$canOperate = in_array($user['role'], ['mechanic', 'fleet_manager', 'system_admin'], true);
$interval = static fn (mixed $days, mixed $km): string => implode(' or ', array_filter([
    $days === null ? null : Format::plural((int) $days, 'day'),
    $km === null ? null : Format::km($km),
])) ?: '—';
$override = static fn (mixed $days, mixed $km): string => ($days === null ? 'default days' : Format::plural((int) $days, 'day')) . ' / ' . ($km === null ? 'default km' : Format::km($km));

View::begin('staff', ['title' => 'Maintenance', 'crumbs' => [['Fleet', null], ['Maintenance', null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Maintenance</h1>
        <p class="page-lead">Service schedules, work in progress and anything due. Vehicles stay unavailable while a service is open.</p>
    </div>
    <div class="page-header-actions">
        <a class="button button-secondary" href="/maintenance/due">Due report and CSV</a>
<?php if ($canOperate): ?>
        <a class="button button-primary" href="/maintenance/service/new"><?= Icon::svg('plus') ?>Start a service</a>
<?php endif; ?>
    </div>
</header>
<?php if (!empty($notice)): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<nav class="section-nav" aria-label="On this page">
    <a href="#due">Due and due soon</a>
    <a href="#services">Services</a>
    <a href="#schedules">Schedules</a>
<?php if ($needsReview): ?><a href="#reviews">Status reviews (<?= count($needsReview) ?>)</a><?php endif; ?>
</nav>

<section class="panel" id="due" aria-labelledby="due-title">
    <div class="panel-heading"><div><h2 id="due-title">Due and due soon</h2><p>Warning window: <?= $e(Format::plural((int) $defaults['days'], 'day')) ?> or <?= $e(Format::km($defaults['kilometers'])) ?> before a schedule is due, unless the schedule sets its own.</p></div><span class="badge <?= $due ? 'badge-warning' : 'badge-success' ?>"><?= count($due) ?></span></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Vehicle</th><th scope="col">Schedule</th><th scope="col">State</th><th scope="col">Next date</th><th scope="col" class="num">Next mileage</th><th scope="col" class="num">Current mileage</th></tr></thead>
            <tbody>
<?php foreach ($due as $row): ?>
                <tr>
                    <td><span class="mono cell-strong"><?= $e($row['plate_number']) ?></span><span class="cell-sub"><?= $e($row['make'] . ' ' . $row['model']) ?></span></td>
                    <td><?= $e($row['schedule_name']) ?></td>
                    <td><?= Status::badge('due', $row['due_state']) ?></td>
                    <td class="nowrap"><?= $e(Format::date($row['next_due_date'])) ?></td>
                    <td class="num"><?= $e(Format::km($row['next_due_mileage'])) ?></td>
                    <td class="num"><?= $e(Format::km($row['current_mileage'])) ?></td>
                </tr>
<?php endforeach; ?>
<?php if (!$due): ?>
                <tr><td class="empty-state" colspan="6"><strong>Nothing due</strong>No schedule is due or inside its warning window.</td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="services" aria-labelledby="services-title">
    <div class="panel-heading"><div><h2 id="services-title">Services</h2><p>Open work first, then the most recent.</p></div></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Service</th><th scope="col">Vehicle</th><th scope="col">Schedule</th><th scope="col">Started</th><th scope="col">Status</th><th scope="col" class="num">Total cost</th></tr></thead>
            <tbody>
<?php foreach ($services as $s): $href = '/maintenance/service?service_id=' . (int) $s['service_id']; ?>
                <tr data-href="<?= $e($href) ?>">
                    <td><a class="cell-strong" href="<?= $e($href) ?>"><?= $e($s['title']) ?></a><span class="cell-sub">#<?= (int) $s['service_id'] ?></span></td>
                    <td><span class="mono"><?= $e($s['plate_number']) ?></span><span class="cell-sub"><?= $e($s['make'] . ' ' . $s['model']) ?></span></td>
                    <td><?= $e($s['schedule_name'] ?? 'Unscheduled repair') ?></td>
                    <td class="nowrap"><?= $e(Format::datetime($s['started_at'])) ?></td>
                    <td><?= Status::badge('service', $s['status']) ?><?php if ((int) ($s['needs_review'] ?? 0) === 1): ?> <span class="badge badge-warning">Needs review</span><?php endif; ?></td>
                    <td class="num"><?= $e(Format::money($s['total_cost'])) ?></td>
                </tr>
<?php endforeach; ?>
<?php if (!$services): ?>
                <tr><td class="empty-state" colspan="6"><strong>No services yet</strong><?= $canOperate ? 'Start a service when a vehicle goes in for work.' : 'Services recorded by mechanics appear here.' ?></td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" id="schedules" aria-labelledby="schedules-title">
    <div class="panel-heading">
        <div><h2 id="schedules-title">Schedules</h2><p>Each schedule is due at whichever comes first: the time interval or the mileage interval.</p></div>
<?php if ($canConfigure): ?>
        <details class="disclosure disclosure--button">
            <summary>New schedule</summary>
            <form class="disclosure-body form-grid" method="post" action="/maintenance/schedules/create">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <label class="field field--wide"><span class="field-label">Vehicle</span>
                    <select name="vehicle_id" required>
<?php foreach ($vehicles as $v): ?>
                        <option value="<?= (int) $v['vehicle_id'] ?>"><?= $e($v['plate_number'] . ' · ' . $v['make'] . ' ' . $v['model'] . ' (' . Status::label($v['current_status']) . ')') ?></option>
<?php endforeach; ?>
                    </select>
                </label>
                <label class="field field--wide"><span class="field-label">Schedule name</span><input name="schedule_name" maxlength="100" required placeholder="e.g. Oil change"></label>
                <label class="field"><span class="field-label">Every (days)</span><input type="number" name="interval_time_days" min="1"></label>
                <label class="field"><span class="field-label">Every (km)</span><input type="number" name="interval_mileage" min="1"></label>
                <label class="field"><span class="field-label">First due date <span class="optional">(optional)</span></span><input type="date" name="next_due_date"><small class="field-hint">Defaults to today plus the interval.</small></label>
                <label class="field"><span class="field-label">First due mileage <span class="optional">(optional)</span></span><input type="number" name="next_due_mileage" min="0"><small class="field-hint">Defaults to current mileage plus the interval.</small></label>
                <label class="field"><span class="field-label">Warn days before <span class="optional">(optional)</span></span><input type="number" name="due_soon_days_override" min="1"><small class="field-hint">Leave blank to use <?= (int) $defaults['days'] ?>.</small></label>
                <label class="field"><span class="field-label">Warn km before <span class="optional">(optional)</span></span><input type="number" name="due_soon_mileage_override" min="1"><small class="field-hint">Leave blank to use <?= (int) $defaults['kilometers'] ?>.</small></label>
                <label class="field field--wide"><span class="field-label">Reason</span><textarea name="reason" maxlength="500" required rows="2" placeholder="Why this schedule is being added"></textarea></label>
                <div class="field--wide"><button class="button button-primary" type="submit">Create schedule</button></div>
            </form>
        </details>
<?php endif; ?>
    </div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Vehicle and schedule</th><th scope="col">Interval</th><th scope="col">Next due</th><th scope="col">Warning window</th><th scope="col">State</th><th scope="col" class="actions"><?= $canConfigure ? 'Actions' : 'History' ?></th></tr></thead>
            <tbody>
<?php foreach ($schedules as $s): ?>
                <tr>
                    <td><span class="cell-strong"><?= $e($s['schedule_name']) ?></span><span class="cell-sub"><span class="mono"><?= $e($s['plate_number']) ?></span> · <?= $e($s['make'] . ' ' . $s['model']) ?></span></td>
                    <td><?= $e($interval($s['interval_time_days'], $s['interval_mileage'])) ?></td>
                    <td class="nowrap"><?= $e(Format::date($s['next_due_date'])) ?><span class="cell-sub"><?= $e(Format::km($s['next_due_mileage'])) ?></span></td>
                    <td><span class="cell-sub"><?= $e($override($s['due_soon_days_override'], $s['due_soon_mileage_override'])) ?></span></td>
                    <td><span class="badge <?= (int) $s['is_active'] === 1 ? 'badge-success' : 'badge-neutral' ?>"><?= (int) $s['is_active'] === 1 ? 'Active' : 'Retired' ?></span></td>
                    <td class="actions">
                        <div class="cell-actions">
                            <a class="button button-secondary button-small" href="/maintenance/history?vehicle_id=<?= (int) $s['vehicle_id'] ?>">History</a>
<?php if ($canConfigure): ?>
                            <details class="disclosure">
                                <summary>Edit</summary>
                                <form class="disclosure-body" method="post" action="/maintenance/schedules/update">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                    <input type="hidden" name="schedule_id" value="<?= (int) $s['schedule_id'] ?>">
                                    <input type="hidden" name="vehicle_id" value="<?= (int) $s['vehicle_id'] ?>">
                                    <label class="field"><span class="field-label">Name</span><input name="schedule_name" value="<?= $e($s['schedule_name']) ?>" required></label>
                                    <div class="form-grid">
                                        <label class="field"><span class="field-label">Every (days)</span><input type="number" min="1" name="interval_time_days" value="<?= $e($s['interval_time_days']) ?>"></label>
                                        <label class="field"><span class="field-label">Every (km)</span><input type="number" min="1" name="interval_mileage" value="<?= $e($s['interval_mileage']) ?>"></label>
                                        <label class="field"><span class="field-label">Due date</span><input type="date" name="next_due_date" value="<?= $e($s['next_due_date']) ?>"></label>
                                        <label class="field"><span class="field-label">Due km</span><input type="number" min="0" name="next_due_mileage" value="<?= $e($s['next_due_mileage']) ?>"></label>
                                        <label class="field"><span class="field-label">Warn days before</span><input type="number" min="1" name="due_soon_days_override" value="<?= $e($s['due_soon_days_override']) ?>"></label>
                                        <label class="field"><span class="field-label">Warn km before</span><input type="number" min="1" name="due_soon_mileage_override" value="<?= $e($s['due_soon_mileage_override']) ?>"></label>
                                    </div>
                                    <label class="check-field"><input type="checkbox" name="is_active" value="1"<?= (int) $s['is_active'] === 1 ? ' checked' : '' ?>> Active</label>
                                    <label class="field"><span class="field-label">Reason for the change</span><textarea name="reason" required maxlength="500" rows="2"></textarea></label>
                                    <button class="button button-primary button-small" type="submit">Save changes</button>
                                </form>
                            </details>
<?php endif; ?>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
<?php if (!$schedules): ?>
                <tr><td class="empty-state" colspan="6"><strong>No schedules yet</strong><?= $canConfigure ? 'Use “New schedule” to add the first one.' : 'Schedules set by fleet managers appear here.' ?></td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($needsReview): ?>
<section class="panel" id="reviews" aria-labelledby="reviews-title">
    <div class="panel-heading"><div><h2 id="reviews-title">Vehicle status reviews</h2><p>The vehicle’s status changed while it was in service. It stays in maintenance until someone chooses its next status.</p></div><span class="badge badge-warning"><?= count($needsReview) ?></span></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Vehicle</th><th scope="col">Status before service</th><th scope="col">Service</th><th scope="col" class="actions">Resolve</th></tr></thead>
            <tbody>
<?php foreach ($needsReview as $r): ?>
                <tr>
                    <td><span class="mono cell-strong"><?= $e($r['plate_number']) ?></span><span class="cell-sub"><?= $e($r['make'] . ' ' . $r['model']) ?></span></td>
                    <td><?= Status::badge('vehicle', $r['vehicle_status_before']) ?></td>
                    <td><a href="/maintenance/service?service_id=<?= (int) $r['service_id'] ?>">#<?= (int) $r['service_id'] ?></a></td>
                    <td class="actions">
<?php if ($canConfigure): ?>
                        <form class="inline-form" method="post" action="/maintenance/service/review">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="service_id" value="<?= (int) $r['service_id'] ?>">
                            <select name="target_status" required aria-label="New status">
                                <option value="">Set status to…</option>
<?php foreach (['available', 'reserved', 'rented', 'cleaning', 'out_of_service'] as $state): ?>
                                <option value="<?= $state ?>"><?= $e(Status::label($state)) ?></option>
<?php endforeach; ?>
                            </select>
                            <input name="reason" required maxlength="500" placeholder="Reason" aria-label="Reason">
                            <button class="button button-primary button-small" type="submit">Resolve</button>
                        </form>
<?php else: ?>
                        <span class="muted">Fleet manager to resolve</span>
<?php endif; ?>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
<?php View::end(); ?>
