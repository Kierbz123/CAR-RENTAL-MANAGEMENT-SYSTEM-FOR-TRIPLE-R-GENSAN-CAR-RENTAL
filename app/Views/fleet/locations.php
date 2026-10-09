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
$activeLocations = count(array_filter($locations, static fn (array $place): bool => $place['location_status'] === 'active'));

View::begin('staff', ['title' => 'Locations and live map', 'crumbs' => [['Fleet', null], ['Locations', null]], 'scripts' => ['vehicles.js', 'fleet-map.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Locations and live map</h1>
        <p class="page-lead">Where each rented vehicle is right now, and the places a vehicle can be parked or picked up.</p>
    </div>
    <div class="page-header-actions">
        <a class="button button-secondary" href="#all-locations"><?= $canManage ? 'Manage locations' : 'All locations' ?></a>
    </div>
</header>

<section class="panel" id="live-map" aria-labelledby="live-map-title">
    <div class="panel-heading"><div><h2 id="live-map-title">Vehicles out on rental</h2><p>Each position is the GPS position of a phone travelling with the vehicle. A vehicle is on the map while its phone is sharing; without one it is listed but not shown.</p></div><span class="badge <?= $vehiclesOut ? 'badge-info' : 'badge-neutral' ?>" data-map-count><?= count($vehiclesOut) ?> out · <?= $onMap ?> on the map</span></div>
    <p class="callout" data-map-tiles-failed role="status" hidden>The map pictures could not be loaded, so the background is blank. Vehicle positions are still drawn on it and listed beside it.</p>
    <p class="callout" data-map-problem role="status" hidden></p>
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
            <p class="fleet-map-hint" aria-hidden="true">Click or tap the map to zoom and move it</p>
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
<?php if ($vehicle['note'] !== null): ?>
                    <span class="item-sub"><?= $e($vehicle['note']) ?></span>
<?php endif; ?>
                    <span class="item-sub"><a href="<?= $e($vehicle['detail_url']) ?>">Agreement</a> · <a href="<?= $e($vehicle['connect_url']) ?>"><?= $vehicle['connected'] ? 'Tracker phone' : 'Connect a phone' ?></a></span>
                    <button class="button button-secondary button-small fleet-map-show" type="button" data-pick<?= $vehicle['position'] === null ? ' disabled title="No position yet. Connect a phone to see this vehicle on the map."' : ' title="Show on the map and follow it"' ?>>Show on map</button>
                </div>
                <span class="badge badge-<?= $e($vehicle['tone']) ?>"><?= $e($vehicle['status']) ?></span>
            </li>
<?php endforeach; ?>
<?php if (!$vehiclesOut): ?>
            <li class="empty-state"><strong>No vehicle is out on rental</strong>A vehicle appears here when its pickup is recorded.</li>
<?php endif; ?>
        </ul>
    </div>
    <p class="panel-note" data-map-updated>Positions are asked for every <?= (int) $mapSettings['interval'] ?> seconds. A vehicle with no report for <?= (int) round($map['quiet_after_seconds'] / 60) ?> minutes is shown where it was last seen, in grey. The dashed circle is the service area, <?= (int) $map['service_radius_km'] ?> km around the centre.</p>
    <noscript><p class="panel-note">The map needs JavaScript. The list above shows each vehicle’s last reported state when this page was loaded.</p></noscript>
</section>

<section class="panel" aria-labelledby="all-locations">
    <div class="panel-heading"><div><h2 id="all-locations">All locations</h2><p>The places a vehicle is kept or handed over. A location no longer in use is retired, not deleted, so past records keep its name. A retired location that was never used can be removed by a system admin.</p></div><span class="badge badge-neutral"><?= $activeLocations ?> active<?= $activeLocations < count($locations) ? ' · ' . (count($locations) - $activeLocations) . ' retired' : '' ?></span></div>
<?php if ($notice): ?>
    <p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?php if ($canManage): ?>
    <form class="toolbar" method="post" action="/fleet/locations/create">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <label class="field">
            <span class="field-label">New location name</span>
            <input name="name" maxlength="120" required placeholder="e.g. Main office lot">
        </label>
        <button class="button button-primary" type="submit">Add location</button>
    </form>
<?php endif; ?>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Location</th><th scope="col">Status</th><th scope="col" class="num">Vehicles</th><th scope="col" class="num">Here now</th><th scope="col" class="num">Out on rental</th><?php if ($canManage): ?><th scope="col" class="actions">Actions</th><?php endif; ?></tr></thead>
            <tbody>
<?php foreach ($locations as $loc): ?>
<?php
    // A vehicle out on rental still has this as its recorded location, so it is counted apart from the ones parked here.
    $kept = (int) $loc['vehicle_count'];
    $out = (int) $loc['out_count'];
    $listHref = '/fleet/vehicles?location=' . (int) $loc['location_id'];
    $renameId = 'rename-location-' . (int) $loc['location_id'];
    $stillUsed = $kept > 0 ? ' ' . Format::plural($kept, 'vehicle') . ' ' . ($kept === 1 ? 'is' : 'are') . ' still recorded here and will stay listed here until moved.' : '';
?>
                <tr>
                    <td class="cell-strong"><?= $e($loc['name']) ?></td>
                    <td><?= Status::badge('location', $loc['location_status']) ?></td>
                    <td class="num"><?php if ($kept > 0): ?><a href="<?= $e($listHref) ?>" title="See the vehicles recorded at <?= $e($loc['name']) ?>"><?= $kept ?></a><?php else: ?>0<?php endif; ?></td>
                    <td class="num"><?= $kept - $out ?></td>
                    <td class="num"><?php if ($out > 0): ?><a href="<?= $e($listHref) ?>&amp;status=rented" title="See the vehicles from <?= $e($loc['name']) ?> that are out on rental"><?= $out ?></a><?php else: ?>0<?php endif; ?></td>
<?php if ($canManage): ?>
                    <td class="actions">
                        <div class="cell-actions">
                            <button class="button button-secondary button-small" type="button" popovertarget="<?= $renameId ?>">Rename</button>
                            <form class="app-dialog popover-dialog" popover id="<?= $renameId ?>" method="post" action="/fleet/locations/rename">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="location_id" value="<?= (int) $loc['location_id'] ?>">
                                <h2>Rename this location</h2>
                                <p>Past records that mention it will show the new name too.</p>
                                <label class="field"><span class="field-label">Location name</span><input name="name" maxlength="120" required value="<?= $e($loc['name']) ?>" autofocus></label>
                                <div class="app-dialog-actions">
                                    <button class="button button-ghost" type="button" popovertarget="<?= $renameId ?>" popovertargetaction="hide">Cancel</button>
                                    <button class="button button-primary" type="submit">Save name</button>
                                </div>
                            </form>
<?php if ($loc['location_status'] === 'active'): ?>
                            <form method="post" action="/fleet/locations/retire" data-confirm="Retire <?= $e($loc['name']) ?>? It stays in past records but can’t be chosen for new ones.<?= $e($stillUsed) ?>" data-confirm-action="Retire location">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="location_id" value="<?= (int) $loc['location_id'] ?>">
                                <button class="button button-secondary button-small" type="submit">Retire</button>
                            </form>
<?php else: ?>
                            <form method="post" action="/fleet/locations/reactivate">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="location_id" value="<?= (int) $loc['location_id'] ?>">
                                <button class="button button-secondary button-small" type="submit">Use again</button>
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
<?php endif; ?>
                </tr>
<?php endforeach; ?>
<?php if (!$locations): ?>
                <tr><td class="empty-state" colspan="<?= $canManage ? 6 : 5 ?>"><strong>No locations yet</strong><?= $canManage ? 'Add the first one above.' : 'A fleet manager adds them.' ?></td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php View::end(); ?>
