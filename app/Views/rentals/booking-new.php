<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$v = $values ?? [];
$isChauffeur = ($v['rental_type'] ?? '') === 'chauffeur';

View::begin('staff', ['title' => 'New reservation', 'crumbs' => [['Agreements', '/rentals'], ['New reservation', null]], 'scripts' => ['rentals.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>New reservation</h1>
        <p class="page-lead">Saving holds the vehicle for 24 hours. In that time the customer pays a 30% downpayment (non-refundable): online from their booking link, by a GCash proof, or at the counter in cash or any other method. Once it is in, the reservation is confirmed. The balance is paid at pickup.</p>
    </div>
</header>
<?php if ($error): ?>
<p class="alert" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form method="post" action="/rentals/reserve" data-reservation-form>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <div class="split">
        <div class="split-main">
            <div class="panel">
                <div class="panel-body form-section">
                    <div class="form-section-heading"><h2>Who and how</h2></div>
                    <div class="form-grid">
                        <label class="field"><span class="field-label">Customer</span>
                            <select name="customer_id" required>
                                <option value="">Choose a customer</option>
<?php foreach ($customers as $c): ?>
                                <option value="<?= (int) $c['customer_id'] ?>"<?= (string) ($v['customer_id'] ?? '') === (string) $c['customer_id'] ? ' selected' : '' ?>><?= $e($c['full_name']) ?><?= $c['company_name'] ? ' — ' . $e($c['company_name']) : '' ?></option>
<?php endforeach; ?>
                            </select>
                            <small class="field-hint">Blacklisted customers are not listed. <a href="/customers/new?return=reservation">Add a new customer</a></small>
                        </label>
                        <label class="field"><span class="field-label">Rental type</span>
                            <select name="rental_type" required data-rental-type>
                                <option value="self_drive"<?= !$isChauffeur ? ' selected' : '' ?>>Self-drive</option>
                                <option value="chauffeur"<?= $isChauffeur ? ' selected' : '' ?>>With a chauffeur</option>
                            </select>
                        </label>
                        <label class="field field--wide" id="driver-picker" data-driver-picker><span class="field-label">Driver <span class="optional">(chauffeur rentals only)</span></span>
                            <select name="driver_id">
                                <option value="">Assign later</option>
<?php foreach ($drivers ?? [] as $d): ?>
                                <option value="<?= (int) $d['driver_id'] ?>"<?= (string) ($v['driver_id'] ?? '') === (string) $d['driver_id'] ? ' selected' : '' ?>><?= $e($d['full_name']) ?> (licence valid to <?= $e(Format::date($d['license_expiry'])) ?>)</option>
<?php endforeach; ?>
                            </select>
                            <small class="field-hint">A driver is required before the reservation can be confirmed.</small>
                        </label>
                    </div>
                </div>
                <div class="panel-body form-section">
                    <div class="form-section-heading"><h2>Vehicle</h2></div>
                    <label class="field"><span class="field-label">Vehicle</span>
                        <select name="vehicle_id" required data-vehicle>
                            <option value="">Choose an available vehicle</option>
<?php foreach ($vehicles as $car): ?>
                            <option value="<?= (int) $car['vehicle_id'] ?>" data-rate="<?= $e($car['daily_rate']) ?>"<?= (string) ($v['vehicle_id'] ?? '') === (string) $car['vehicle_id'] ? ' selected' : '' ?>><?= $e($car['plate_number'] . ' — ' . $car['model_year'] . ' ' . $car['make'] . ' ' . $car['model'] . ' — ' . Format::money($car['daily_rate']) . '/day') ?></option>
<?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <div class="panel-body form-section">
                    <div class="form-section-heading"><h2>Dates and times</h2><p>All in Manila time. A same-day rental is billed as one day.</p></div>
                    <div class="form-grid">
                        <label class="field"><span class="field-label">Pickup date</span><input type="date" name="start_date" required value="<?= $e($v['start_date'] ?? '') ?>" data-start-date></label>
                        <label class="field"><span class="field-label">Return date</span><input type="date" name="end_date" required value="<?= $e($v['end_date'] ?? '') ?>" data-end-date></label>
                        <label class="field"><span class="field-label">Scheduled pickup time</span><input type="datetime-local" name="scheduled_pickup_at" required value="<?= $e($v['scheduled_pickup_at'] ?? '') ?>"></label>
                        <label class="field"><span class="field-label">Scheduled return time</span><input type="datetime-local" name="scheduled_return_at" required value="<?= $e($v['scheduled_return_at'] ?? '') ?>"></label>
                    </div>
                </div>
                <div class="panel-body form-section">
                    <div class="form-section-heading"><h2>Deposit</h2></div>
                    <div class="form-grid">
                        <label class="field"><span class="field-label">Security deposit (₱)</span><input type="number" name="deposit_amount" min="0" max="99999999.99" step="0.01" value="<?= $e($v['deposit_amount'] ?? '0.00') ?>" required data-deposit><small class="field-hint">Enter 0 if no deposit is required.</small></label>
                    </div>
                </div>
                <div class="form-actions">
                    <p class="alert" role="alert" data-form-error hidden></p>
                    <button class="button button-primary" type="submit">Create reservation</button>
                    <a class="button button-ghost" href="/rentals">Cancel</a>
                </div>
            </div>
        </div>
        <aside class="split-side" aria-label="Reservation summary">
            <section class="panel">
                <div class="panel-heading"><div><h2>Summary</h2><p>Updates as you fill in the form.</p></div></div>
                <div class="panel-body" aria-live="polite">
                    <dl class="facts facts--list">
                        <div><dt>Daily rate</dt><dd data-summary="rate">—</dd></div>
                        <div><dt>Days billed</dt><dd data-summary="days">—</dd></div>
                        <div class="facts-total"><dt>Base amount</dt><dd data-summary="base">—</dd></div>
                        <div><dt>Security deposit</dt><dd data-summary="deposit">—</dd></div>
                    </dl>
                    <p class="muted">The saved agreement shows the final amounts. For a chauffeur rental the 30% downpayment includes the chauffeur rate; the chauffeur fee itself is charged when a driver is assigned.</p>
                </div>
            </section>
        </aside>
    </div>
</form>
<?php View::end(); ?>
