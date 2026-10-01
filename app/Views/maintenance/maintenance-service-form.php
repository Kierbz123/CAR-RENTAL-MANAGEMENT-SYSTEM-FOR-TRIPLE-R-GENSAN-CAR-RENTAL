<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$vehicles = $vehicles ?? [];
$photos = $photos ?? [];
$audits = $audits ?? [];
$canOperate = in_array($user['role'], ['mechanic', 'fleet_manager', 'system_admin'], true);
$canManage = in_array($user['role'], ['fleet_manager', 'system_admin'], true);

if ($service === null):
    View::begin('staff', ['title' => 'Start a service', 'crumbs' => [['Fleet', null], ['Maintenance', '/maintenance'], ['Start a service', null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Start a service</h1>
        <p class="page-lead">The vehicle becomes unavailable for booking until the service is completed or cancelled.</p>
    </div>
</header>
<?php if (!empty($notice)): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<form class="panel" method="post" action="/maintenance/service/start">
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <div class="panel-body form-section">
        <div class="form-grid">
            <label class="field"><span class="field-label">Vehicle</span>
                <select name="vehicle_id" id="maintenance-vehicle" required>
                    <option value="">Choose a vehicle</option>
<?php foreach ($vehicles as $v): ?>
                    <option value="<?= (int) $v['vehicle_id'] ?>"<?= $vehicle && (int) $vehicle['vehicle_id'] === (int) $v['vehicle_id'] ? ' selected' : '' ?>><?= $e($v['plate_number'] . ' · ' . $v['make'] . ' ' . $v['model'] . ' (' . Status::label($v['current_status']) . ')') ?></option>
<?php endforeach; ?>
                </select>
                <small class="field-hint">A vehicle on an active rental or held for a reservation can’t be started.</small>
            </label>
            <label class="field"><span class="field-label">Schedule <span class="optional">(optional)</span></span>
                <select name="schedule_id">
                    <option value="">Unscheduled repair</option>
<?php foreach ($schedules as $s): ?>
                    <option value="<?= (int) $s['schedule_id'] ?>"><?= $e($s['plate_number'] . ' · ' . $s['schedule_name']) ?></option>
<?php endforeach; ?>
                </select>
                <small class="field-hint">Must belong to the vehicle you chose.</small>
            </label>
            <label class="field field--wide"><span class="field-label">What is being done</span><input name="title" maxlength="160" required placeholder="e.g. Oil and filter change"></label>
            <label class="field field--wide"><span class="field-label">Notes <span class="optional">(optional)</span></span><textarea name="notes" maxlength="2000" rows="3"></textarea></label>
        </div>
    </div>
    <div class="form-actions">
        <button class="button button-primary" type="submit">Start service</button>
        <a class="button button-ghost" href="/maintenance">Cancel</a>
    </div>
</form>
<?php
    View::end();
    return;
endif;

$inProgress = $service['status'] === 'in_progress';
View::begin('staff', ['title' => 'Service #' . (int) $service['service_id'], 'crumbs' => [['Fleet', null], ['Maintenance', '/maintenance'], ['Service #' . (int) $service['service_id'], null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow">Service #<?= (int) $service['service_id'] ?></p>
        <h1><?= $e($service['title']) ?></h1>
        <div class="page-meta">
            <?= Status::badge('service', $service['status']) ?>
<?php if ((int) $service['needs_review'] === 1): ?>
            <span class="badge badge-warning">Needs manager review</span>
<?php endif; ?>
            <span><span class="mono"><?= $e($service['plate_number']) ?></span> <?= $e($service['make'] . ' ' . $service['model']) ?></span>
            <span><?= $e($service['schedule_name'] ?? 'Unscheduled repair') ?></span>
        </div>
    </div>
    <div class="page-header-actions">
        <button class="button button-secondary" type="button" data-print hidden>Print</button>
        <a class="button button-secondary" href="/maintenance/history?vehicle_id=<?= (int) $service['vehicle_id'] ?>">Vehicle history</a>
    </div>
</header>
<?php if (!empty($notice)): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?php if ((int) $service['needs_review'] === 1): ?>
<p class="callout" role="status">The vehicle’s status changed during this service. It stays in maintenance until a fleet manager sets its next status on the <a href="/maintenance#reviews">Maintenance page</a>.</p>
<?php endif; ?>

<div class="split">
    <div class="split-main">
<?php if ($canOperate && $inProgress): ?>
        <section class="panel" aria-labelledby="finish-title">
            <div class="panel-heading"><div><h2 id="finish-title">Finish this service</h2><p>Completing returns the vehicle to its earlier status and moves the schedule forward.</p></div></div>
            <div class="panel-body">
                <form class="inline-form" method="post" action="/maintenance/service/complete">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="service_id" value="<?= (int) $service['service_id'] ?>">
                    <label class="field"><span class="field-label">Odometer at completion (km)</span><input type="number" name="mileage" min="0" max="4294967295" required></label>
                    <button class="button button-primary" type="submit">Complete service</button>
                </form>
                <details class="disclosure disclosure--danger">
                    <summary>Cancel this service instead…</summary>
                    <form class="disclosure-body" method="post" action="/maintenance/service/cancel" data-confirm="Cancel this service? The vehicle goes back to the status it had before." data-confirm-action="Cancel service">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="service_id" value="<?= (int) $service['service_id'] ?>">
                        <label class="field"><span class="field-label">Reason for cancelling</span><input name="reason" maxlength="500" required></label>
                        <button class="button button-danger button-small" type="submit">Cancel service</button>
                    </form>
                </details>
            </div>
        </section>
<?php endif; ?>

        <section class="panel" aria-labelledby="costs-title">
            <div class="panel-heading"><h2 id="costs-title">Costs</h2></div>
            <div class="panel-body">
                <dl class="facts">
                    <div><dt>Labor</dt><dd><?= $e(Format::money($service['labor_cost'])) ?></dd></div>
                    <div><dt>Parts</dt><dd><?= $e(Format::money($service['parts_cost'])) ?></dd></div>
                    <div><dt>Other</dt><dd><?= $e(Format::money($service['other_cost'])) ?></dd></div>
                    <div><dt>Total</dt><dd><?= $e(Format::money($service['total_cost'])) ?></dd></div>
                </dl>
<?php if ($canOperate && $service['status'] !== 'cancelled' && ($inProgress || $canManage)): ?>
                <details class="disclosure"<?= $inProgress ? ' open' : '' ?>>
                    <summary><?= $inProgress ? 'Update costs' : 'Correct costs' ?></summary>
                    <form class="disclosure-body" method="post" action="/maintenance/service/costs">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="service_id" value="<?= (int) $service['service_id'] ?>">
                        <div class="form-grid form-grid--three">
                            <label class="field"><span class="field-label">Labor (₱)</span><input name="labor_cost" type="number" min="0" step="0.01" value="<?= $e($service['labor_cost']) ?>" required></label>
                            <label class="field"><span class="field-label">Parts (₱)</span><input name="parts_cost" type="number" min="0" step="0.01" value="<?= $e($service['parts_cost']) ?>" required></label>
                            <label class="field"><span class="field-label">Other (₱)</span><input name="other_cost" type="number" min="0" step="0.01" value="<?= $e($service['other_cost']) ?>" required></label>
                        </div>
                        <label class="field"><span class="field-label">Reason for the change</span><input name="reason" maxlength="500" required></label>
                        <div><button class="button button-primary button-small" type="submit">Save costs</button></div>
                    </form>
                </details>
<?php endif; ?>
            </div>
<?php if ($audits): ?>
            <div class="panel-body">
                <h3>Cost changes</h3>
                <ol class="timeline">
<?php foreach ($audits as $a): ?>
                    <li>
                        <div class="timeline-title"><?= $e(Format::money((float) $a['old_labor_cost'] + (float) $a['old_parts_cost'] + (float) $a['old_other_cost'])) ?> → <?= $e(Format::money((float) $a['new_labor_cost'] + (float) $a['new_parts_cost'] + (float) $a['new_other_cost'])) ?></div>
                        <div class="timeline-meta"><?= $e(Format::datetime($a['created_at'])) ?> · <?= $e($a['actor_name']) ?></div>
                        <div class="timeline-note"><?= $e($a['reason']) ?> <span class="muted">(labor / parts / other: <?= $e($a['old_labor_cost'] . ' / ' . $a['old_parts_cost'] . ' / ' . $a['old_other_cost']) ?> → <?= $e($a['new_labor_cost'] . ' / ' . $a['new_parts_cost'] . ' / ' . $a['new_other_cost']) ?>)</span></div>
                    </li>
<?php endforeach; ?>
                </ol>
            </div>
<?php endif; ?>
        </section>

        <section class="panel" aria-labelledby="photos-title">
            <div class="panel-heading"><div><h2 id="photos-title">Photos</h2><p>Before photos while the service is open; after photos once it is completed. JPEG, PNG or WebP.</p></div></div>
            <div class="panel-body">
<?php if ($canOperate && ($inProgress || $service['status'] === 'completed')): $phase = $inProgress ? 'before' : 'after'; ?>
                <form class="inline-form" method="post" action="/maintenance/service/photo" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="service_id" value="<?= (int) $service['service_id'] ?>">
                    <input type="hidden" name="phase" value="<?= $phase ?>">
                    <label class="field"><span class="field-label"><?= ucfirst($phase) ?>-service photo</span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label>
                    <button class="button button-secondary" type="submit">Upload <?= $phase ?> photo</button>
                </form>
<?php endif; ?>
<?php if (!$photos): ?>
                <p class="muted">No photos attached.</p>
<?php else: ?>
                <div class="photo-grid">
<?php foreach ($photos as $p): $src = '/maintenance/photo?photo_id=' . (int) $p['photo_id']; ?>
                    <figure>
                        <a href="<?= $e($src) ?>" target="_blank" rel="noopener"><img src="<?= $e($src) ?>" alt="<?= $e(ucfirst((string) $p['phase']) . ' photo: ' . $p['original_filename']) ?>" loading="lazy"></a>
                        <figcaption><?= $e(ucfirst((string) $p['phase'])) ?> · <?= $e($p['original_filename']) ?></figcaption>
                    </figure>
<?php endforeach; ?>
                </div>
<?php endif; ?>
            </div>
        </section>
    </div>

    <aside class="split-side" aria-label="Service summary">
        <section class="panel">
            <div class="panel-heading"><h2>Summary</h2></div>
            <div class="panel-body">
                <dl class="facts facts--list">
                    <div><dt>Status</dt><dd><?= $e(Status::label($service['status'])) ?></dd></div>
                    <div><dt>Mechanic</dt><dd><?= $e($service['mechanic_name']) ?></dd></div>
                    <div><dt>Started</dt><dd><?= $e(Format::datetime($service['started_at'])) ?></dd></div>
                    <div><dt>Vehicle status before</dt><dd><?= $e(Status::label($service['vehicle_status_before'])) ?></dd></div>
                    <div class="facts-total"><dt>Total cost</dt><dd><?= $e(Format::money($service['total_cost'])) ?></dd></div>
                </dl>
<?php if ($service['notes']): ?>
                <div>
                    <h3>Notes</h3>
                    <p class="timeline-note"><?= nl2br($e($service['notes'])) ?></p>
                </div>
<?php endif; ?>
            </div>
        </section>
    </aside>
</div>
<?php View::end(); ?>
