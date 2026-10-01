<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);

View::begin('staff', ['title' => 'Locations', 'crumbs' => [['Fleet', null], ['Locations', null]], 'scripts' => ['vehicles.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Locations</h1>
        <p class="page-lead">Places a vehicle can be parked or picked up. Retired locations stay in history but can’t be chosen for new records.</p>
    </div>
</header>

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
