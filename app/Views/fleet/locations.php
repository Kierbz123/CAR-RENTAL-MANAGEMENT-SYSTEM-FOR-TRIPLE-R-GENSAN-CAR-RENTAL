<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);

/*
 * $vehiclesOut is the live feed as it stands when the page is built (VehicleTrackingService::feed());
 * fleet-map.js then asks for it again every few seconds and redraws the map and this list.
 * $map holds the settings from config/tracking.php.
 */
$mapSettings = [
    'center' => $map['center'],
    'zoom' => (int) $map['zoom'],
    'radiusKm' => (float) $map['service_radius_km'],
    'interval' => max(2, (int) $map['interval_seconds']),
    'tiles' => $map['tiles']['url'],
    'feed' => '/api/fleet/positions',
];
$onMap = count(array_filter($vehiclesOut, static fn (array $vehicle): bool => $vehicle['position'] !== null));

View::begin('staff', ['title' => 'Locations and live map', 'crumbs' => [['Fleet', null], ['Locations', null]], 'scripts' => ['vehicles.js', 'fleet-map.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Locations and live map</h1>
        <p class="page-lead">Where each rented vehicle is right now, and the places a vehicle can be parked or picked up.</p>
    </div>
</header>

<section class="panel" id="live-map" aria-labelledby="live-map-title">
    <div class="panel-heading"><div><h2 id="live-map-title">Vehicles out on rental</h2><p>Each position is the GPS position of a phone travelling with the vehicle. A vehicle is on the map while its phone is sharing; without one it is listed but not shown.</p></div><span class="badge <?= $vehiclesOut ? 'badge-info' : 'badge-neutral' ?>" data-map-count><?= count($vehiclesOut) ?> out · <?= $onMap ?> on the map</span></div>
    <div class="fleet-map-layout">
        <div class="fleet-map" data-fleet-map="<?= $e(json_encode($mapSettings, JSON_UNESCAPED_SLASHES)) ?>" tabindex="0" role="application" aria-label="Map of rented vehicles. Drag to move, scroll or use plus and minus to zoom.">
            <div class="fleet-map-tiles" data-map-tiles></div>
            <svg class="fleet-map-overlay" data-map-overlay aria-hidden="true"></svg>
            <div class="fleet-map-markers" data-map-markers></div>
            <div class="fleet-map-controls">
                <button class="fleet-map-button" type="button" data-map-zoom="in" aria-label="Zoom in">+</button>
                <button class="fleet-map-button" type="button" data-map-zoom="out" aria-label="Zoom out">−</button>
                <button class="fleet-map-button" type="button" data-map-fit>Show all</button>
            </div>
            <p class="fleet-map-credit"><a href="<?= $e($map['tiles']['credit_url']) ?>" target="_blank" rel="noopener"><?= $e($map['tiles']['credit']) ?></a></p>
        </div>
        <ul class="item-list fleet-map-list" data-map-list aria-label="Vehicles out on rental">
<?php foreach ($vehiclesOut as $vehicle): ?>
            <li data-agreement="<?= (int) $vehicle['agreement_id'] ?>">
                <div class="item-main">
                    <button class="fleet-map-pick" type="button" data-pick<?= $vehicle['position'] === null ? ' disabled' : '' ?>><?= $e($vehicle['plate'] . ' ' . $vehicle['vehicle']) ?></button>
                    <span class="item-sub"><?= $e($vehicle['customer']) ?> · <?= $e($vehicle['due_back'] !== null ? 'due back ' . $vehicle['due_back'] : 'no return time set') ?></span>
<?php foreach ($vehicle['alerts'] as $alert): ?>
                    <span class="item-sub fleet-map-alert"><?= $e($alert) ?></span>
<?php endforeach; ?>
                    <span class="item-sub"><a href="<?= $e($vehicle['detail_url']) ?>">Agreement</a> · <a href="<?= $e($vehicle['connect_url']) ?>"><?= $vehicle['connected'] ? 'Tracker phone' : 'Connect a phone' ?></a></span>
                </div>
                <span class="badge badge-<?= $e($vehicle['tone']) ?>"><?= $e($vehicle['status']) ?></span>
            </li>
<?php endforeach; ?>
<?php if (!$vehiclesOut): ?>
            <li class="empty-state"><strong>No vehicle is out on rental</strong>A vehicle appears here when its pickup is recorded.</li>
<?php endif; ?>
        </ul>
    </div>
    <p class="panel-note" data-map-updated role="status">Positions are asked for every <?= (int) $mapSettings['interval'] ?> seconds. A vehicle with no report for <?= (int) round($map['quiet_after_seconds'] / 60) ?> minutes is shown where it was last seen, in grey. The dashed circle is the service area, <?= (int) $map['service_radius_km'] ?> km around the centre.</p>
    <noscript><p class="panel-note">The map needs JavaScript. The list above shows each vehicle’s last reported state when this page was loaded.</p></noscript>
</section>

<section class="panel" aria-labelledby="add-location">
    <div class="panel-heading"><h2 id="add-location">Add a location</h2></div>
    <form class="toolbar" method="post" action="/fleet/locations/create">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <label class="field">
            <span class="field-label">Location name</span>
            <input name="name" maxlength="120" required placeholder="e.g. Main office lot">
        </label>
        <button class="button button-primary" type="submit">Add location</button>
    </form>
</section>

<section class="panel" aria-labelledby="all-locations">
    <div class="panel-heading"><div><h2 id="all-locations">All locations</h2><p>Only unused, retired locations can be removed, and only by a system admin.</p></div></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Location</th><th scope="col">Status</th><th scope="col">Created</th><th scope="col" class="actions">Actions</th></tr></thead>
            <tbody>
<?php foreach ($locations as $loc): ?>
                <tr>
                    <td class="cell-strong"><?= $e($loc['name']) ?></td>
                    <td><?= Status::badge('location', $loc['location_status']) ?></td>
                    <td class="nowrap"><?= $e(Format::datetime($loc['created_at'])) ?></td>
                    <td class="actions">
                        <div class="cell-actions">
<?php if ($loc['location_status'] === 'active'): ?>
                            <form method="post" action="/fleet/locations/retire" data-confirm="Retire <?= $e($loc['name']) ?>? It stays in past records but can’t be chosen for new ones." data-confirm-action="Retire location">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="location_id" value="<?= (int) $loc['location_id'] ?>">
                                <button class="button button-secondary button-small" type="submit">Retire</button>
                            </form>
<?php endif; ?>
<?php if ($user['role'] === 'system_admin' && $loc['location_status'] === 'retired'): ?>
                            <form method="post" action="/fleet/locations/remove" data-confirm="Remove <?= $e($loc['name']) ?> permanently? Locations used in any record can’t be removed." data-confirm-action="Remove location">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="location_id" value="<?= (int) $loc['location_id'] ?>">
                                <button class="button button-danger button-small" type="submit">Remove</button>
                            </form>
<?php endif; ?>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
<?php if (!$locations): ?>
                <tr><td class="empty-state" colspan="4"><strong>No locations yet</strong>Add the first one above.</td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php View::end(); ?>
