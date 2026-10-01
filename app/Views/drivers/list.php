<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\Icon;
use TripleR\Support\Pager;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$pager = new Pager($drivers);
$today = Format::today();

View::begin('staff', ['title' => 'Drivers', 'crumbs' => [['Fleet', null], ['Drivers', null]], 'scripts' => ['drivers.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Drivers</h1>
        <p class="page-lead">Chauffeurs, their licence validity and who can take a new assignment.</p>
    </div>
<?php if ($canManage): ?>
    <div class="page-header-actions">
        <a class="button button-primary" href="/fleet/drivers/new"><?= Icon::svg('plus') ?>Add driver</a>
    </div>
<?php endif; ?>
</header>

<section class="panel" aria-labelledby="driver-records">
    <h2 class="visually-hidden" id="driver-records">Driver records</h2>
    <form class="toolbar" method="get" action="/fleet/drivers" role="search">
        <label class="field">
            <span class="field-label">Search by name</span>
            <input type="search" name="search" value="<?= $e($search) ?>" maxlength="160" placeholder="Driver name">
        </label>
        <button class="button button-secondary" type="submit"><?= Icon::svg('search') ?>Search</button>
<?php if ($search !== ''): ?>
        <a class="button button-ghost" href="/fleet/drivers">Clear search</a>
<?php endif; ?>
        <span class="toolbar-summary"><?= $e(Format::plural(count($drivers), 'driver')) ?></span>
    </form>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Name</th><th scope="col">Licence</th><th scope="col">Licence expiry</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Open</span></th></tr></thead>
            <tbody>
<?php foreach ($pager->rows as $driver): $href = '/fleet/drivers/detail?driver_id=' . (int) $driver['driver_id']; $expired = $driver['license_expiry'] < $today; ?>
                <tr data-href="<?= $e($href) ?>">
                    <td><a class="cell-strong" href="<?= $e($href) ?>"><?= $e($driver['full_name']) ?></a></td>
                    <td><span class="mono"><?= $e($driver['license_display']) ?></span></td>
                    <td class="nowrap"><?= $e(Format::date($driver['license_expiry'])) ?><?php if ($expired): ?> <span class="badge badge-danger">Expired</span><?php endif; ?></td>
                    <td><?= Status::badge('driver', $driver['status']) ?></td>
                    <td class="actions" data-label=""><a href="<?= $e($href) ?>" aria-label="Open <?= $e($driver['full_name']) ?>">Open</a></td>
                </tr>
<?php endforeach; ?>
<?php if (!$drivers): ?>
                <tr><td class="empty-state" colspan="5"><strong>No drivers found</strong><?= $search !== '' ? 'Try a different name.' : 'Drivers you add appear here.' ?></td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->render('driver') ?>
</section>

<section class="panel" aria-labelledby="eligible-drivers">
    <div class="panel-heading"><div><h2 id="eligible-drivers">Available for a new assignment</h2><p>Active drivers whose licence is valid through today (Manila time).</p></div><span class="badge badge-success"><?= count($eligibleDrivers) ?></span></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Name</th><th scope="col">Licence expiry</th></tr></thead>
            <tbody>
<?php foreach ($eligibleDrivers as $driver): ?>
                <tr><td class="cell-strong"><?= $e($driver['full_name']) ?></td><td><?= $e(Format::date($driver['license_expiry'])) ?></td></tr>
<?php endforeach; ?>
<?php if (!$eligibleDrivers): ?>
                <tr><td class="empty-state" colspan="2"><strong>No drivers available</strong>No driver currently meets the assignment rules.</td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php View::end(); ?>
