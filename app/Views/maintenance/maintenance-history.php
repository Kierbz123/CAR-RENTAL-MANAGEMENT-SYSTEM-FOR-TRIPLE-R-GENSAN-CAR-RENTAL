<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\Icon;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$canOperate = in_array($user['role'], ['mechanic', 'fleet_manager', 'system_admin'], true);
$heading = $vehicle ? $vehicle['plate_number'] . ' · ' . $vehicle['make'] . ' ' . $vehicle['model'] : 'Vehicle unavailable';

View::begin('staff', ['title' => 'Maintenance history', 'crumbs' => [['Fleet', null], ['Maintenance', '/maintenance'], [$vehicle ? $vehicle['plate_number'] : 'History', null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow">Maintenance history</p>
        <h1><?= $e($heading) ?></h1>
        <p class="page-lead">The next due date and mileage are worked out from when each service was actually completed.</p>
    </div>
    <div class="page-header-actions">
<?php if ($vehicle && in_array($user['role'], ['system_admin', 'fleet_manager'], true)): ?>
        <a class="button button-secondary" href="/fleet/vehicles/detail?vehicle_id=<?= (int) $vehicleId ?>">Vehicle record</a>
<?php endif; ?>
<?php if ($canOperate): ?>
        <a class="button button-primary" href="/maintenance/service/new?vehicle_id=<?= (int) $vehicleId ?>"><?= Icon::svg('plus') ?>Start a service</a>
<?php endif; ?>
    </div>
</header>
<?php if (!empty($notice)): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>

<section class="panel" aria-labelledby="schedules-title">
    <div class="panel-heading"><h2 id="schedules-title">Schedules for this vehicle</h2></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Name</th><th scope="col">Interval</th><th scope="col">Next due</th><th scope="col">Warning window</th><th scope="col">State</th></tr></thead>
            <tbody>
<?php foreach ($schedules as $s): ?>
                <tr>
                    <td class="cell-strong"><?= $e($s['schedule_name']) ?></td>
                    <td><?= $e(implode(' or ', array_filter([$s['interval_time_days'] === null ? null : Format::plural((int) $s['interval_time_days'], 'day'), $s['interval_mileage'] === null ? null : Format::km($s['interval_mileage'])])) ?: '—') ?></td>
                    <td class="nowrap"><?= $e(Format::date($s['next_due_date'])) ?><span class="cell-sub"><?= $e(Format::km($s['next_due_mileage'])) ?></span></td>
                    <td><span class="cell-sub"><?= $e(($s['due_soon_days_override'] === null ? 'default days' : Format::plural((int) $s['due_soon_days_override'], 'day')) . ' / ' . ($s['due_soon_mileage_override'] === null ? 'default km' : Format::km($s['due_soon_mileage_override']))) ?></span></td>
                    <td><span class="badge <?= (int) $s['is_active'] === 1 ? 'badge-success' : 'badge-neutral' ?>"><?= (int) $s['is_active'] === 1 ? 'Active' : 'Retired' ?></span></td>
                </tr>
<?php endforeach; ?>
<?php if (!$schedules): ?>
                <tr><td class="empty-state" colspan="5"><strong>No schedules</strong>This vehicle has no maintenance schedule yet.</td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="panel" aria-labelledby="log-title">
    <div class="panel-heading"><h2 id="log-title">Service log</h2></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Service</th><th scope="col">Schedule</th><th scope="col">Mechanic</th><th scope="col">Started</th><th scope="col">Status</th><th scope="col" class="num">Cost</th></tr></thead>
            <tbody>
<?php foreach ($services as $s): $href = '/maintenance/service?service_id=' . (int) $s['service_id']; ?>
                <tr data-href="<?= $e($href) ?>">
                    <td><a class="cell-strong" href="<?= $e($href) ?>"><?= $e($s['title']) ?></a><span class="cell-sub">#<?= (int) $s['service_id'] ?></span></td>
                    <td><?= $e($s['schedule_name'] ?? 'Unscheduled repair') ?></td>
                    <td><?= $e($s['mechanic_name']) ?></td>
                    <td class="nowrap"><?= $e(Format::datetime($s['started_at'])) ?></td>
                    <td><?= Status::badge('service', $s['status']) ?><?php if ((int) $s['needs_review'] === 1): ?> <span class="badge badge-warning">Needs review</span><?php endif; ?></td>
                    <td class="num"><?= $e(Format::money($s['total_cost'])) ?></td>
                </tr>
<?php endforeach; ?>
<?php if (!$services): ?>
                <tr><td class="empty-state" colspan="6"><strong>No services recorded</strong>Completed and open work for this vehicle appears here.</td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php View::end(); ?>
