<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\Icon;
use TripleR\Support\Pager;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
// The controller fetched only this page; $total is the full count.
$pager = new Pager($rows, 25, 'page', $total ?? null);
// Driver coordinators schedule drivers; amounts are not part of their view.
$showAmounts = true;
$today = Format::today();

View::begin('staff', ['title' => 'Agreements', 'crumbs' => [['Agreements', null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Agreements</h1>
        <p class="page-lead">Reservations, rentals on the road and closed agreements, in lifecycle order.</p>
    </div>
<?php if (in_array($user['role'], \TripleR\Security\Access::CUSTOMERS, true)): ?>
    <div class="page-header-actions">
        <a class="button button-primary" href="/rentals/new"><?= Icon::svg('plus') ?>New reservation</a>
    </div>
<?php endif; ?>
</header>

<section class="panel" aria-labelledby="agreement-records">
    <h2 class="visually-hidden" id="agreement-records">Rental agreements</h2>
    <form class="toolbar" method="get" action="/rentals" role="search">
        <label class="field">
            <span class="field-label">Search customer, plate or reference</span>
            <input type="search" name="search" value="<?= $e($search) ?>" maxlength="160" placeholder="Customer, plate or booking reference">
        </label>
        <label class="field">
            <span class="field-label">Status</span>
            <select name="status" data-auto-submit>
                <option value="">Current (cancelled hidden)</option>
                <option value="all"<?= $status === 'all' ? ' selected' : '' ?>>All statuses</option>
<?php foreach ($statuses as $s): ?>
                <option value="<?= $e($s) ?>"<?= $status === $s ? ' selected' : '' ?>><?= $e(Status::label($s)) ?></option>
<?php endforeach; ?>
            </select>
        </label>
        <button class="button button-secondary" type="submit"><?= Icon::svg('search') ?>Search</button>
<?php if ($status !== '' || $search !== ''): ?>
        <a class="button button-ghost" href="/rentals">Clear</a>
<?php endif; ?>
        <span class="toolbar-summary"><?= $e(Format::plural($pager->total, 'agreement')) ?></span>
    </form>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Agreement</th><th scope="col">Customer</th><th scope="col">Vehicle</th><th scope="col">Dates</th><th scope="col" class="num">Days</th><?php if ($showAmounts): ?><th scope="col" class="num">Base amount</th><th scope="col" class="num">Still owed</th><?php endif; ?><th scope="col">Status</th></tr></thead>
            <tbody>
<?php foreach ($pager->rows as $r):
    $href = '/rentals/detail?agreement_id=' . (int) $r['agreement_id'];
    $latePickup = in_array($r['status'], ['reserved', 'confirmed'], true) && $r['start_date'] < $today;
    $lateReturn = $r['status'] === 'active' && $r['end_date'] < $today;
    $sameDay = $r['start_date'] === $r['end_date'];
?>
                <tr data-href="<?= $e($href) ?>">
                    <td><a class="cell-strong" href="<?= $e($href) ?>">#<?= (int) $r['agreement_id'] ?></a><span class="cell-sub"><?= $e(Status::label($r['rental_type'])) ?></span></td>
                    <td class="nowrap"><?= $e($r['customer_name']) ?></td>
                    <td class="nowrap"><span class="mono"><?= $e($r['plate_number']) ?></span><span class="cell-sub"><?= $e($r['make'] . ' ' . $r['model']) ?></span></td>
                    <td class="nowrap"><?= $e(Format::date($r['start_date'])) ?><span class="cell-sub"><?= $sameDay ? $e($r['scheduled_pickup_at'] ? 'Pickup ' . Format::time($r['scheduled_pickup_at']) : 'Same day') : 'to ' . $e(Format::date($r['end_date'])) ?></span></td>
                    <td class="num"><?= (int) $r['rental_days'] ?></td>
<?php if ($showAmounts): ?>
                    <td class="num"><?= $e(Format::money($r['base_amount'])) ?></td>
                    <td class="num"><?= $r['outstanding'] === null ? '—' : ($r['outstanding'] > 0 ? $e(Format::money($r['outstanding'])) : '<span class="badge badge-success">Paid</span>') ?></td>
<?php endif; ?>
                    <td><span class="badge-row"><?= Status::badge('rental', $r['status']) ?><?php if ($latePickup): ?> <span class="badge badge-danger">Pickup overdue</span><?php elseif ($lateReturn): ?> <span class="badge badge-danger">Return overdue</span><?php endif; ?><?php if ($r['rental_type'] === 'chauffeur' && $r['status'] === 'reserved' && $r['driver_id'] === null): ?> <span class="badge badge-danger">Needs driver</span><?php endif; ?><?php if ($showAmounts && $r['status'] === 'reserved' && $r['downpayment_status'] === 'due'): ?> <span class="badge badge-warning">Downpayment due</span><?php elseif ($showAmounts && $r['status'] === 'reserved' && $r['downpayment_status'] === 'received'): ?> <span class="badge badge-success">Paid, to confirm</span><?php endif; ?></span></td>
                </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
                <tr><td class="empty-state" colspan="<?= $showAmounts ? 8 : 6 ?>"><strong>No agreements found</strong><?php if ($status !== '' || $search !== ''): ?><a href="/rentals">Clear the search</a><?php else: ?><a href="/rentals?status=all">Show cancelled bookings too</a><?php endif; ?></td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->render('agreement') ?>
</section>
<?php View::end(); ?>
