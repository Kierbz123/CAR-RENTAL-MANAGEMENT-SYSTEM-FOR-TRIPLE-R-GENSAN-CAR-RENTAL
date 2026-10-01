<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\Icon;
use TripleR\Support\Pager;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$pager = new Pager($vehicles);

View::begin('staff', ['title' => 'Vehicles', 'crumbs' => [['Fleet', null], ['Vehicles', null]], 'scripts' => ['vehicles.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Vehicles</h1>
        <p class="page-lead">Every vehicle in the fleet, its readiness and where it is.</p>
    </div>
    <div class="page-header-actions">
        <a class="button button-primary" href="/fleet/vehicles/new"><?= Icon::svg('plus') ?>Register vehicle</a>
    </div>
</header>

<section class="panel" aria-labelledby="fleet-records">
    <h2 class="visually-hidden" id="fleet-records">Fleet records</h2>
    <form class="toolbar" method="get" action="/fleet/vehicles">
        <label class="field">
            <span class="field-label">Status</span>
            <select name="status" data-auto-submit>
                <option value="">All vehicles</option>
<?php foreach ($statuses as $vehicleStatus): ?>
                <option value="<?= $e($vehicleStatus) ?>"<?= $status === $vehicleStatus ? ' selected' : '' ?>><?= $e(Status::label($vehicleStatus)) ?></option>
<?php endforeach; ?>
            </select>
        </label>
        <button class="button button-secondary" type="submit" data-auto-apply>Apply</button>
<?php if ($status !== ''): ?>
        <a class="button button-ghost" href="/fleet/vehicles">Clear filter</a>
<?php endif; ?>
        <span class="toolbar-summary"><?= $e(Format::plural(count($vehicles), 'vehicle')) ?></span>
    </form>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Plate</th><th scope="col">Vehicle</th><th scope="col">Status</th><th scope="col" class="num">Daily rate</th><th scope="col" class="num">Mileage</th><th scope="col">Location</th><th scope="col"><span class="visually-hidden">Open</span></th></tr></thead>
            <tbody>
<?php foreach ($pager->rows as $vehicle): $href = '/fleet/vehicles/detail?vehicle_id=' . (int) $vehicle['vehicle_id']; ?>
                <tr data-href="<?= $e($href) ?>">
                    <td><a class="mono cell-strong" href="<?= $e($href) ?>"><?= $e($vehicle['plate_number']) ?></a></td>
                    <td><?= $e($vehicle['model_year'] . ' ' . $vehicle['make'] . ' ' . $vehicle['model']) ?></td>
                    <td><?= Status::badge('vehicle', $vehicle['current_status']) ?></td>
                    <td class="num"><?= $e(Format::money($vehicle['daily_rate'])) ?></td>
                    <td class="num"><?= $e(Format::km($vehicle['current_mileage'])) ?></td>
                    <td><?= $e($vehicle['location_name'] ?? 'Not recorded') ?></td>
                    <td class="actions" data-label=""><a href="<?= $e($href) ?>" aria-label="Open <?= $e($vehicle['plate_number']) ?>">Open</a></td>
                </tr>
<?php endforeach; ?>
<?php if (!$vehicles): ?>
                <tr><td class="empty-state" colspan="7"><strong>No vehicles match this filter</strong><?php if ($status !== ''): ?><a href="/fleet/vehicles">Show all vehicles</a><?php else: ?>Register the first vehicle to get started.<?php endif; ?></td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->render('vehicle') ?>
</section>
<?php View::end(); ?>
