<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$superseded = array_map('intval', array_filter(array_column($mileageHistory, 'correction_of_log_id')));
$retired = $vehicle['deleted_at'] !== null;
$name = $vehicle['model_year'] . ' ' . $vehicle['make'] . ' ' . $vehicle['model'];
$text = static fn (mixed $value): string => ($value === null || $value === '') ? '—' : (string) $value;

View::begin('staff', ['title' => (string) $vehicle['plate_number'], 'crumbs' => [['Fleet', null], ['Vehicles', '/fleet/vehicles'], [(string) $vehicle['plate_number'], null]], 'scripts' => ['vehicles.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow">Vehicle</p>
        <h1><span class="mono"><?= $e($vehicle['plate_number']) ?></span> · <?= $e($name) ?></h1>
        <div class="page-meta">
            <?= Status::badge('vehicle', $vehicle['current_status']) ?>
            <span><?= $e(Format::km($vehicle['current_mileage'])) ?></span>
            <span><?= $e($vehicle['location_name'] ?? 'Location not recorded') ?></span>
        </div>
    </div>
<?php if (!$retired): ?>
    <div class="page-header-actions">
        <a class="button button-primary" href="/fleet/vehicles/edit?vehicle_id=<?= (int) $vehicle['vehicle_id'] ?>">Edit vehicle</a>
    </div>
<?php endif; ?>
</header>
<?php if ($notice): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?php if ($retired): ?>
<p class="callout" role="status">This vehicle is retired. Its records are kept and can’t be changed.</p>
<?php endif; ?>

<div class="split">
    <div class="split-main">
        <section class="panel" aria-labelledby="specs-title">
            <div class="panel-heading"><h2 id="specs-title">Specifications and documents</h2></div>
            <div class="panel-body">
                <dl class="facts">
                    <div><dt>Body type</dt><dd><?= $e(Status::label($vehicle['body_type'])) ?></dd></div>
                    <div><dt>Transmission</dt><dd><?= $e(Status::label($vehicle['transmission'])) ?></dd></div>
                    <div><dt>Fuel</dt><dd><?= $e(Status::label($vehicle['fuel_type'])) ?></dd></div>
                    <div><dt>Seats</dt><dd><?= $e($text($vehicle['seating_capacity'])) ?></dd></div>
                    <div><dt>Color</dt><dd><?= $e($text($vehicle['color'])) ?></dd></div>
                    <div><dt>Engine number</dt><dd class="mono"><?= $e($text($vehicle['engine_number'])) ?></dd></div>
                    <div><dt>Chassis number</dt><dd class="mono"><?= $e($text($vehicle['chassis_number'])) ?></dd></div>
                    <div><dt>Registration expiry</dt><dd><?= $e(Format::date($vehicle['registration_expiry'])) ?></dd></div>
                    <div><dt>Insurance expiry</dt><dd><?= $e(Format::date($vehicle['insurance_expiry'])) ?></dd></div>
                    <div><dt>Insurance provider</dt><dd><?= $e($text($vehicle['insurance_provider'])) ?></dd></div>
                </dl>
<?php if (!empty($vehicle['notes'])): ?>
                <div><h3>Notes</h3><p class="timeline-note"><?= nl2br($e($vehicle['notes'])) ?></p></div>
<?php endif; ?>
            </div>
        </section>

        <section class="panel" aria-labelledby="photos-title">
            <div class="panel-heading"><div><h2 id="photos-title">Photos</h2><p>JPEG, PNG or WebP, up to 8 MB each.</p></div></div>
            <div class="panel-body">
<?php if (!$retired): ?>
                <form class="inline-form" method="post" action="/fleet/vehicles/photos/upload" enctype="multipart/form-data">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['vehicle_id'] ?>">
                    <label class="field"><span class="field-label">Add a photo</span><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required></label>
                    <button class="button button-secondary" type="submit">Upload photo</button>
                </form>
<?php endif; ?>
<?php if (!$photos): ?>
                <p class="muted">No photos uploaded yet.</p>
<?php else: ?>
                <div class="photo-grid">
<?php foreach ($photos as $photo): $src = '/fleet/vehicles/photos/show?photo_id=' . (int) $photo['photo_id']; ?>
                    <figure>
                        <a href="<?= $e($src) ?>" target="_blank" rel="noopener"><img src="<?= $e($src) ?>" alt="<?= $e($photo['original_filename']) ?>" loading="lazy"></a>
                        <figcaption><?= $e($photo['original_filename']) ?></figcaption>
                    </figure>
<?php endforeach; ?>
                </div>
<?php endif; ?>
            </div>
        </section>

        <section class="panel" aria-labelledby="status-title">
            <div class="panel-heading"><div><h2 id="status-title">Status history</h2><p>Every change is kept. Times are in Manila time.</p></div></div>
<?php if (!$retired): ?>
            <form class="toolbar" method="post" action="/fleet/vehicles/status" data-confirm="Change this vehicle’s status? The change is recorded in its history." data-confirm-action="Change status" data-confirm-tone="neutral">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['vehicle_id'] ?>">
                <label class="field"><span class="field-label">Change status to</span>
                    <select name="status" required>
<?php foreach ($statuses as $s): if ($s !== $vehicle['current_status']): ?>
                        <option value="<?= $e($s) ?>"><?= $e(Status::label($s)) ?></option>
<?php endif; endforeach; ?>
                    </select>
                </label>
                <button class="button button-secondary" type="submit">Change status</button>
            </form>
<?php endif; ?>
            <div class="table-wrap">
                <table class="data-table" data-stack>
                    <thead><tr><th scope="col">When</th><th scope="col">From</th><th scope="col">To</th><th scope="col">Location</th><th scope="col" class="num">Mileage</th><th scope="col">By</th></tr></thead>
                    <tbody>
<?php foreach ($statusHistory as $h): ?>
                        <tr>
                            <td class="nowrap"><?= $e(Format::datetime($h['created_at'])) ?></td>
                            <td><?= $h['old_status'] === null ? '—' : $e(Status::label($h['old_status'])) ?></td>
                            <td><?= Status::badge('vehicle', $h['new_status']) ?></td>
                            <td><?= $e($h['location_name'] ?? '—') ?></td>
                            <td class="num"><?= $e(Format::km($h['mileage'])) ?></td>
                            <td><?= $e($h['actor_email']) ?></td>
                        </tr>
<?php endforeach; ?>
<?php if (!$statusHistory): ?>
                        <tr><td class="empty-state" colspan="6">No status changes recorded.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel" aria-labelledby="mileage-title">
            <div class="panel-heading"><div><h2 id="mileage-title">Odometer readings</h2><p>Readings are never edited. A wrong reading is fixed by adding a correction, which only a system admin can do.</p></div></div>
<?php if (!$retired): ?>
            <form class="toolbar" method="post" action="/fleet/vehicles/mileage">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['vehicle_id'] ?>">
                <label class="field"><span class="field-label">New reading (km)</span><input type="number" min="0" step="1" name="mileage" required></label>
                <label class="field"><span class="field-label">Location</span>
                    <select name="location_id">
                        <option value="">No location change</option>
<?php foreach ($locations as $loc): ?>
                        <option value="<?= (int) $loc['location_id'] ?>"><?= $e($loc['name']) ?></option>
<?php endforeach; ?>
                    </select>
                </label>
                <button class="button button-secondary" type="submit">Record reading</button>
            </form>
<?php if ($user['role'] === 'system_admin'): ?>
            <div class="panel-body">
                <details class="disclosure">
                    <summary>Correct a reading…</summary>
                    <form class="disclosure-body" method="post" action="/fleet/vehicles/mileage">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['vehicle_id'] ?>">
                        <label class="field"><span class="field-label">Reading to correct</span>
                            <select name="corrects_log_id" required>
                                <option value="">Choose a reading</option>
<?php foreach ($mileageHistory as $m): if (!in_array((int) $m['mileage_log_id'], $superseded, true)): ?>
                                <option value="<?= (int) $m['mileage_log_id'] ?>">#<?= (int) $m['mileage_log_id'] ?> — <?= $e(Format::km($m['mileage'])) ?>, <?= $e(Format::datetime($m['recorded_at'])) ?><?= $m['correction_of_log_id'] ? ' (correction)' : '' ?></option>
<?php endif; endforeach; ?>
                            </select>
                        </label>
                        <div class="form-grid">
                            <label class="field"><span class="field-label">Correct mileage (km)</span><input type="number" min="0" step="1" name="mileage" required></label>
                            <label class="field"><span class="field-label">Reason</span><input name="correction_reason" maxlength="500" required></label>
                        </div>
                        <div><button class="button button-primary button-small" type="submit">Add correction</button></div>
                    </form>
                </details>
            </div>
<?php endif; ?>
<?php endif; ?>
            <div class="table-wrap">
                <table class="data-table" data-stack>
                    <thead><tr><th scope="col">When</th><th scope="col" class="num">Reading</th><th scope="col">Location</th><th scope="col">Corrects</th><th scope="col">Reason</th><th scope="col">By</th></tr></thead>
                    <tbody>
<?php foreach ($mileageHistory as $m): ?>
                        <tr>
                            <td class="nowrap"><?= $e(Format::datetime($m['recorded_at'])) ?></td>
                            <td class="num"><?= $e(Format::km($m['mileage'])) ?></td>
                            <td><?= $e($m['location_name'] ?? '—') ?></td>
                            <td><?= $m['correction_of_log_id'] ? '#' . (int) $m['correction_of_log_id'] : '—' ?></td>
                            <td><?= $e($m['correction_reason'] ?? '—') ?></td>
                            <td><?= $e($m['actor_email']) ?></td>
                        </tr>
<?php endforeach; ?>
<?php if (!$mileageHistory): ?>
                        <tr><td class="empty-state" colspan="6">No readings recorded.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <aside class="split-side" aria-label="Rates">
        <section class="panel">
            <div class="panel-heading"><h2>Rates</h2></div>
            <div class="panel-body">
                <dl class="facts facts--list">
                    <div><dt>Self-drive, per day</dt><dd><?= $e(Format::money($vehicle['daily_rate'])) ?></dd></div>
                    <div><dt>Chauffeur, per day</dt><dd><?= $vehicle['chauffeur_daily_rate'] === null ? 'Not offered' : $e(Format::money($vehicle['chauffeur_daily_rate'])) ?></dd></div>
                </dl>
            </div>
        </section>
    </aside>
</div>
<?php View::end(); ?>
