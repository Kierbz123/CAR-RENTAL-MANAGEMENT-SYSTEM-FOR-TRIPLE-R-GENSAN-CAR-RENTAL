<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\Icon;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$hour = (int) $now->format('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$name = ucfirst(explode('@', (string) $user['email'])[0]);

$available = $vehicleCounts['available'] ?? 0;
$inFleet = $vehicleCounts !== null ? array_sum($vehicleCounts) : 0;
$dueNow = $maintenanceDue !== null ? count(array_filter($maintenanceDue, static fn (array $row): bool => $row['due_state'] === 'due')) : 0;
$dueSoon = $maintenanceDue !== null ? count($maintenanceDue) - $dueNow : 0;

// "Needs attention" lists only what this role is the one to act on, matching who may perform each step.
$mine = static fn (string ...$roles): bool => in_array($user['role'], $roles, true);
$attention = [];
if ($rentalCounts !== null) {
    if ($rentalCounts['overdue'] > 0 && $mine('system_admin', 'front_desk', 'fleet_manager')) {
        $attention[] = ['count' => $rentalCounts['overdue'], 'danger' => true, 'title' => 'Overdue returns', 'sub' => 'Active rentals past their return date', 'href' => '/rentals?status=active'];
    }
    if ($rentalCounts['needs_driver'] > 0 && $mine('system_admin', 'front_desk', 'driver_coordinator')) {
        $attention[] = ['count' => $rentalCounts['needs_driver'], 'danger' => true, 'title' => 'Chauffeur bookings without a driver', 'sub' => 'A driver is required before confirmation', 'href' => '/rentals?status=reserved'];
    }
    if ($rentalCounts['awaiting_confirmation'] > 0 && $mine('system_admin', 'front_desk')) {
        $attention[] = ['count' => $rentalCounts['awaiting_confirmation'], 'danger' => false, 'title' => 'Reservations to confirm', 'sub' => 'Held reservations expire if not confirmed', 'href' => '/rentals?status=reserved'];
    }
    if ($rentalCounts['awaiting_completion'] > 0 && $mine('system_admin', 'finance_staff')) {
        $attention[] = ['count' => $rentalCounts['awaiting_completion'], 'danger' => false, 'title' => 'Returned, awaiting completion', 'sub' => 'Reconcile charges and deposit to close', 'href' => '/rentals?status=returned'];
    }
}
if ($proofsToCheck > 0) {
    $attention[] = ['count' => $proofsToCheck, 'danger' => false, 'title' => 'Payments to check', 'sub' => 'GCash proofs of downpayment sent by customers', 'href' => '/payments'];
}
if ($maintenanceDue !== null && $dueNow > 0 && $mine('system_admin', 'fleet_manager', 'mechanic')) {
    $attention[] = ['count' => $dueNow, 'danger' => true, 'title' => 'Maintenance due now', 'sub' => 'Schedules past their date or mileage', 'href' => '/maintenance/due'];
}

View::begin('staff', ['title' => 'Workspace']);
?>
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow"><?= $e($now->format('l, F j')) ?></p>
        <h1><?= $e($greeting) ?>, <?= $e($name) ?></h1>
        <p class="page-lead">Signed in as <?= $e(Status::label($user['role'])) ?>. Here is what needs you today.</p>
    </div>
<?php if ($canCreateRentals || $canManageCustomers || $canManageFleet): ?>
    <div class="page-header-actions">
<?php if ($canManageCustomers): ?>
        <a class="button button-secondary" href="/customers/new"><?= Icon::svg('plus') ?>Add customer</a>
<?php endif; ?>
<?php if ($canManageFleet && !$canCreateRentals): ?>
        <a class="button button-secondary" href="/fleet/vehicles/new"><?= Icon::svg('plus') ?>Register vehicle</a>
<?php endif; ?>
<?php if ($canCreateRentals): ?>
        <a class="button button-secondary" href="/staff/booking-qr">Online booking QR</a>
        <a class="button button-primary" href="/rentals/new"><?= Icon::svg('plus') ?>New reservation</a>
<?php endif; ?>
    </div>
<?php endif; ?>
</header>

<?php if ($vehicleCounts !== null || $rentalCounts !== null || $maintenanceDue !== null): ?>
<section aria-labelledby="today-heading">
    <h2 class="visually-hidden" id="today-heading">Today at a glance</h2>
    <div class="stat-grid">
<?php if ($rentalCounts !== null): ?>
        <a class="stat-card" href="/rentals?status=confirmed">
            <span class="stat-label">Pickups today</span>
            <span class="stat-value"><?= (int) $rentalCounts['pickups_today'] ?></span>
            <span class="stat-hint">Reserved or confirmed to start today</span>
        </a>
        <a class="stat-card<?= $rentalCounts['overdue'] > 0 ? ' stat-card--alert' : '' ?>" href="/rentals?status=active">
            <span class="stat-label">Returns today</span>
            <span class="stat-value"><?= (int) $rentalCounts['returns_today'] ?></span>
            <span class="stat-hint"><?= $rentalCounts['overdue'] > 0 ? $e(Format::plural($rentalCounts['overdue'], 'rental') . ' overdue') : 'None overdue' ?></span>
        </a>
        <a class="stat-card" href="/rentals?status=active">
            <span class="stat-label">Active rentals</span>
            <span class="stat-value"><?= (int) $rentalCounts['active'] ?></span>
            <span class="stat-hint">Vehicles currently with customers</span>
        </a>
<?php endif; ?>
<?php if ($vehicleCounts !== null): ?>
<?php if ($canManageFleet): ?>
        <a class="stat-card" href="/fleet/vehicles?status=available">
<?php else: ?>
        <div class="stat-card">
<?php endif; ?>
            <span class="stat-label">Vehicles available</span>
            <span class="stat-value"><?= (int) $available ?></span>
            <span class="stat-hint">of <?= $e(Format::plural($inFleet, 'vehicle')) ?> in service</span>
<?= $canManageFleet ? '        </a>' : '        </div>' ?>

<?php endif; ?>
<?php if ($maintenanceDue !== null): ?>
        <a class="stat-card<?= $dueNow > 0 ? ' stat-card--warn' : '' ?>" href="/maintenance/due">
            <span class="stat-label">Maintenance due</span>
            <span class="stat-value"><?= (int) $dueNow ?></span>
            <span class="stat-hint"><?= $e(Format::plural($dueSoon, 'schedule') . ' due soon') ?></span>
        </a>
<?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($rentalCounts !== null || $maintenanceDue !== null): ?>
<div class="dashboard-grid">
    <section class="panel" aria-labelledby="attention-heading">
        <div class="panel-heading"><div><h2 id="attention-heading">Needs attention</h2><p>Items waiting on someone in your role.</p></div></div>
<?php if ($attention === []): ?>
        <p class="empty-state"><strong>All clear</strong>Nothing is waiting on you right now.</p>
<?php else: ?>
        <ul class="item-list">
<?php foreach ($attention as $item): ?>
            <li>
                <span class="item-count<?= $item['danger'] ? ' is-danger' : '' ?>"><?= (int) $item['count'] ?></span>
                <span class="item-main"><a class="item-link item-title" href="<?= $e($item['href']) ?>"><?= $e($item['title']) ?></a><span class="item-sub"><?= $e($item['sub']) ?></span></span>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </section>

<?php if ($rentalCounts !== null): ?>
    <section class="panel" aria-labelledby="schedule-heading">
        <div class="panel-heading"><div><h2 id="schedule-heading">Today’s pickups and returns</h2><p>Times in Manila time. Overdue returns are listed first.</p></div></div>
<?php if ($schedule === []): ?>
        <p class="empty-state"><strong>No movements today</strong>No vehicles are due to leave or come back today.</p>
<?php else: ?>
        <ul class="item-list">
<?php foreach ($schedule as $row):
    $isReturn = $row['movement'] === 'return';
    $overdue = $isReturn && $row['end_date'] < Format::today();
    $time = $isReturn ? $row['scheduled_return_at'] : $row['scheduled_pickup_at'];
?>
            <li>
                <span class="badge <?= $overdue ? 'badge-danger' : ($isReturn ? 'badge-warning' : 'badge-info') ?>"><?= $overdue ? 'Overdue' : ($isReturn ? 'Return' : 'Pickup') ?></span>
                <span class="item-main">
                    <a class="item-link item-title" href="/rentals/detail?agreement_id=<?= (int) $row['agreement_id'] ?>"><?= $e($row['customer_name']) ?></a>
                    <span class="item-sub"><span class="mono"><?= $e($row['plate_number']) ?></span> · <?= $e($row['make'] . ' ' . $row['model']) ?> · #<?= (int) $row['agreement_id'] ?></span>
                </span>
                <span class="item-aside"><?= $overdue ? 'Due ' . $e(Format::date($row['end_date'])) : $e($time ? Format::time($time) : 'Time not set') ?></span>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </section>
<?php endif; ?>
</div>
<?php endif; ?>

<section class="panel" aria-labelledby="tools-heading">
    <div class="panel-heading"><div><h2 id="tools-heading">Your tools</h2><p>Everything your role can open. The same links are in the menu.</p></div></div>
    <div class="panel-body">
        <div class="quick-actions">
<?php if ($canViewRentals): ?><a class="button button-secondary" href="/rentals"><?= Icon::svg('document') ?>Agreements</a><?php endif; ?>
<?php if ($canManageCustomers): ?><a class="button button-secondary" href="/customers"><?= Icon::svg('users') ?>Customers</a><?php endif; ?>
<?php if ($canManageFleet): ?><a class="button button-secondary" href="/fleet/vehicles"><?= Icon::svg('car') ?>Vehicles</a><?php endif; ?>
<?php if ($canManageFleet): ?><a class="button button-secondary" href="/fleet/locations"><?= Icon::svg('pin') ?>Locations</a><?php endif; ?>
<?php if ($canReadDrivers): ?><a class="button button-secondary" href="/fleet/drivers"><?= Icon::svg('id') ?>Drivers</a><?php endif; ?>
<?php if ($canViewMaintenance): ?><a class="button button-secondary" href="/maintenance"><?= Icon::svg('wrench') ?>Maintenance</a><?php endif; ?>
<?php if ($canViewNotifications): ?><a class="button button-secondary" href="/staff/notifications"><?= Icon::svg('bell') ?>SMS notifications</a><?php endif; ?>
<?php if ($canManageUsers): ?><a class="button button-secondary" href="/admin/users"><?= Icon::svg('shield') ?>Staff accounts</a><?php endif; ?>
        </div>
<?php if (!$canViewRentals && !$canManageFleet && !$canReadDrivers && !$canViewMaintenance && !$canViewNotifications && !$canManageUsers && !$canManageCustomers): ?>
        <p class="muted">Your account is active. Tools for your role will appear here as they are released.</p>
<?php endif; ?>
    </div>
</section>
<?php View::end(); ?>
