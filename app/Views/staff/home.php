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

// "Needs attention" lists only what this role is the one to act on, matching who may perform each step.
// Pickups, returns and assigning a driver are open to every staff role, so those are listed for all.
$attention = [];
if ($rentalCounts !== null) {
    if ($rentalCounts['overdue'] > 0) {
        $attention[] = ['count' => $rentalCounts['overdue'], 'danger' => true, 'title' => 'Overdue returns', 'sub' => 'Active rentals past their return date', 'href' => '/rentals?status=active'];
    }
    if ($rentalCounts['late_pickups'] > 0) {
        $attention[] = ['count' => $rentalCounts['late_pickups'], 'danger' => true, 'title' => 'Pickups overdue', 'sub' => 'Booked for an earlier day and not picked up: record the pickup, or cancel', 'href' => '/rentals'];
    }
    if ($rentalCounts['needs_driver'] > 0) {
        $attention[] = ['count' => $rentalCounts['needs_driver'], 'danger' => true, 'title' => 'Chauffeur bookings without a driver', 'sub' => 'A driver is required before confirmation', 'href' => '/rentals?status=reserved'];
    }
    if ($rentalCounts['awaiting_confirmation'] > 0 && $canCreateRentals) {
        $attention[] = ['count' => $rentalCounts['awaiting_confirmation'], 'danger' => false, 'title' => 'Reservations to confirm', 'sub' => 'Held reservations expire if not confirmed', 'href' => '/rentals?status=reserved'];
    }
    if ($rentalCounts['awaiting_completion'] > 0 && $canTakePayments) {
        $attention[] = ['count' => $rentalCounts['awaiting_completion'], 'danger' => false, 'title' => 'Returned, awaiting completion', 'sub' => 'Reconcile charges and deposit to close', 'href' => '/rentals?status=returned'];
    }
}
if ($proofsToCheck > 0) {
    $attention[] = ['count' => $proofsToCheck, 'danger' => false, 'title' => 'Payments to check', 'sub' => 'GCash proofs of downpayment sent by customers', 'href' => '/payments'];
}

View::begin('staff', ['title' => 'Workspace']);
?>
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow"><?= $e($now->format('l, F j')) ?></p>
        <h1><?= View::rise($greeting . ', ' . $name) ?></h1>
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

<?php if ($vehicleCounts !== null || $rentalCounts !== null): ?>
<section aria-labelledby="today-heading">
    <h2 class="visually-hidden" id="today-heading">Today at a glance</h2>
    <div class="stat-grid">
<?php if ($rentalCounts !== null): ?>
        <a class="stat-card<?= $rentalCounts['late_pickups'] > 0 ? ' stat-card--alert' : '' ?>" href="/rentals">
            <span class="stat-label">Pickups today</span>
            <span class="stat-value" data-count-up><?= (int) $rentalCounts['pickups_today'] ?></span>
            <span class="stat-hint"><?= $rentalCounts['late_pickups'] > 0 ? $e($rentalCounts['late_pickups'] . ' overdue from earlier days') : 'Reserved or confirmed to start today' ?></span>
        </a>
        <a class="stat-card<?= $rentalCounts['overdue'] > 0 ? ' stat-card--alert' : '' ?>" href="/rentals?status=active">
            <span class="stat-label">Returns today</span>
            <span class="stat-value" data-count-up><?= (int) $rentalCounts['returns_today'] ?></span>
            <span class="stat-hint"><?= $rentalCounts['overdue'] > 0 ? $e(Format::plural($rentalCounts['overdue'], 'rental') . ' overdue') : 'None overdue' ?></span>
        </a>
        <a class="stat-card" href="/rentals?status=active">
            <span class="stat-label">Active rentals</span>
            <span class="stat-value" data-count-up><?= (int) $rentalCounts['active'] ?></span>
            <span class="stat-hint">Vehicles currently with customers</span>
        </a>
<?php endif; ?>
<?php if ($vehicleCounts !== null): ?>
        <a class="stat-card" href="/fleet/vehicles?status=available">
            <span class="stat-label">Vehicles available</span>
            <span class="stat-value" data-count-up><?= (int) $available ?></span>
            <span class="stat-hint">of <?= $e(Format::plural($inFleet, 'vehicle')) ?> in service</span>
        </a>

<?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($rentalCounts !== null): ?>
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
        <div class="panel-heading"><div><h2 id="schedule-heading">Today’s pickups and returns</h2><p>Times in Manila time. Anything overdue is listed first.</p></div></div>
<?php if ($schedule === []): ?>
        <p class="empty-state"><strong>No movements today</strong>No vehicles are due to leave or come back today.</p>
<?php else: ?>
        <ul class="item-list">
<?php foreach ($schedule as $row):
    $isReturn = $row['movement'] === 'return';
    $dueDate = $isReturn ? $row['end_date'] : $row['start_date'];
    $overdue = $dueDate < Format::today();
    $time = $isReturn ? $row['scheduled_return_at'] : $row['scheduled_pickup_at'];
?>
            <li>
                <span class="badge <?= $overdue ? 'badge-danger' : ($isReturn ? 'badge-warning' : 'badge-info') ?>"><?= $overdue ? ($isReturn ? 'Return overdue' : 'Pickup overdue') : ($isReturn ? 'Return' : 'Pickup') ?></span>
                <span class="item-main">
                    <a class="item-link item-title" href="/rentals/detail?agreement_id=<?= (int) $row['agreement_id'] ?>"><?= $e($row['customer_name']) ?></a>
                    <span class="item-sub"><span class="mono"><?= $e($row['plate_number']) ?></span> · <?= $e($row['make'] . ' ' . $row['model']) ?> · #<?= (int) $row['agreement_id'] ?></span>
                </span>
                <span class="item-aside"><?= $overdue ? 'Due ' . $e(Format::date($dueDate)) : $e($time ? Format::time($time) : 'Time not set') ?></span>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </section>
<?php endif; ?>
</div>
<?php endif; ?>

<?php View::end(); ?>
