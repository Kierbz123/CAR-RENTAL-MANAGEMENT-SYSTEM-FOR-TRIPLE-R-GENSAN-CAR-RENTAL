<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\Icon;
use TripleR\Support\Pager;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
// The controller fetched only this page; $total is the full count.
$pager = new Pager($vehicles, 25, 'page', $total ?? null);
// Registration and insurance are flagged once they have lapsed or are within 30 days of lapsing.
$today = Format::today();
$soon = (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d');
$papers = static function (?string $date, string $what) use ($today, $soon): string {
    if ($date === null || $date > $soon) {
        return '';
    }
    return $date < $today
        ? '<span class="badge badge-danger">' . $what . ' expired</span>'
        : '<span class="badge badge-warning">' . $what . ' due ' . View::e(Format::date($date)) . '</span>';
};

View::begin('staff', ['title' => 'Vehicles', 'crumbs' => [['Fleet', null], ['Vehicles', null]], 'scripts' => ['vehicles.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Vehicles</h1>
        <p class="page-lead">Every vehicle in the fleet, its readiness and where it is.</p>
    </div>
<?php if ($canManage): ?>
    <div class="page-header-actions">
        <a class="button button-primary" href="/fleet/vehicles/new"><?= Icon::svg('plus') ?>Register vehicle</a>
    </div>
<?php endif; ?>
</header>

<section class="panel" aria-labelledby="fleet-records">
    <h2 class="visually-hidden" id="fleet-records">Fleet records</h2>
    <form class="toolbar" method="get" action="/fleet/vehicles" role="search">
        <label class="field">
            <span class="field-label">Search plate or model</span>
            <input type="search" name="search" value="<?= $e($search) ?>" maxlength="60" placeholder="Plate or model">
        </label>
        <label class="field">
            <span class="field-label">Status</span>
            <select name="status" data-auto-submit>
                <option value="">All vehicles</option>
<?php foreach ($statuses as $vehicleStatus): ?>
                <option value="<?= $e($vehicleStatus) ?>"<?= $status === $vehicleStatus ? ' selected' : '' ?>><?= $e(Status::label($vehicleStatus)) ?></option>
<?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="field-label">Location</span>
            <select name="location" data-auto-submit>
                <option value="">All locations</option>
<?php $placeListed = false; foreach ($locations as $option): $placeListed = $placeListed || (int) $option['location_id'] === $place; ?>
                <option value="<?= (int) $option['location_id'] ?>"<?= (int) $option['location_id'] === $place ? ' selected' : '' ?>><?= $e($option['name']) ?></option>
<?php endforeach; ?>
<?php if ($place !== null && !$placeListed): ?>
                <option value="<?= (int) $place ?>" selected>A retired location</option>
<?php endif; ?>
            </select>
        </label>
        <button class="button button-secondary" type="submit"><?= Icon::svg('search') ?>Search</button>
<?php if ($status !== '' || $search !== '' || $place !== null): ?>
        <a class="button button-ghost" href="/fleet/vehicles">Clear</a>
<?php endif; ?>
        <span class="toolbar-summary"><?= $e(Format::plural($pager->total, 'vehicle')) ?></span>
    </form>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Plate</th><th scope="col">Vehicle</th><th scope="col">Status</th><th scope="col" class="num">Daily rate</th><th scope="col" class="num">Mileage</th><th scope="col">Location</th><th scope="col"><span class="visually-hidden">Open</span></th></tr></thead>
            <tbody>
<?php foreach ($pager->rows as $vehicle): $href = '/fleet/vehicles/detail?vehicle_id=' . (int) $vehicle['vehicle_id']; ?>
                <tr data-href="<?= $e($href) ?>">
                    <td><span class="cell-media"><?php if ($vehicle['cover_photo_id'] !== null): ?><img class="thumb" src="/fleet/vehicles/photos/show?photo_id=<?= (int) $vehicle['cover_photo_id'] ?>" alt="" loading="lazy"><?php else: ?><span class="thumb thumb--empty" aria-hidden="true"><?= Icon::svg('car') ?></span><?php endif; ?><a class="mono cell-strong" href="<?= $e($href) ?>"><?= $e($vehicle['plate_number']) ?></a></span></td>
                    <td><?= $e($vehicle['model_year'] . ' ' . $vehicle['make'] . ' ' . $vehicle['model']) ?><span class="cell-sub"><?= $e(implode(' · ', array_filter([$vehicle['seating_capacity'] ? $vehicle['seating_capacity'] . ' seats' : null, $vehicle['transmission'] ? ucfirst((string) $vehicle['transmission']) : null, $vehicle['color'] ?: null]))) ?></span></td>
                    <td><span class="badge-row"><?= Status::badge('vehicle', $vehicle['current_status']) ?><?= $papers($vehicle['registration_expiry'], 'Registration') ?><?= $papers($vehicle['insurance_expiry'], 'Insurance') ?></span></td>
                    <td class="num"><?= $e(Format::money($vehicle['daily_rate'])) ?></td>
                    <td class="num"><?= $e(Format::km($vehicle['current_mileage'])) ?></td>
                    <td><?= $e($vehicle['location_name'] ?? 'Not recorded') ?></td>
                    <td class="actions" data-label=""><a href="<?= $e($href) ?>" aria-label="Open <?= $e($vehicle['plate_number']) ?>">Open</a></td>
                </tr>
<?php endforeach; ?>
<?php if (!$vehicles): ?>
                <tr><td class="empty-state" colspan="7"><strong>No vehicles match this filter</strong><?php if ($status !== '' || $search !== ''): ?><a href="/fleet/vehicles">Show all vehicles</a><?php else: ?><?= $canManage ? 'Register the first vehicle to get started.' : 'No vehicles are registered yet.' ?><?php endif; ?></td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->render('vehicle') ?>
</section>
<?php View::end(); ?>
