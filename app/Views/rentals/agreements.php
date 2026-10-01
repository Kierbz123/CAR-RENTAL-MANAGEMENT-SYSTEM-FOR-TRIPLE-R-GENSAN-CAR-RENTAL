<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\Icon;
use TripleR\Support\Pager;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$pager = new Pager($rows);

View::begin('staff', ['title' => 'Agreements', 'crumbs' => [['Agreements', null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Agreements</h1>
        <p class="page-lead">Reservations, rentals on the road and closed agreements, in lifecycle order.</p>
    </div>
<?php if (in_array($user['role'], ['system_admin', 'front_desk'], true)): ?>
    <div class="page-header-actions">
        <a class="button button-primary" href="/rentals/new"><?= Icon::svg('plus') ?>New reservation</a>
    </div>
<?php endif; ?>
</header>

<section class="panel" aria-labelledby="agreement-records">
    <h2 class="visually-hidden" id="agreement-records">Rental agreements</h2>
    <form class="toolbar" method="get" action="/rentals">
        <label class="field">
            <span class="field-label">Status</span>
            <select name="status" data-auto-submit>
                <option value="">All statuses</option>
<?php foreach ($statuses as $s): ?>
                <option value="<?= $e($s) ?>"<?= $status === $s ? ' selected' : '' ?>><?= $e(Status::label($s)) ?></option>
<?php endforeach; ?>
            </select>
        </label>
        <button class="button button-secondary" type="submit" data-auto-apply>Apply</button>
<?php if ($status !== ''): ?>
        <a class="button button-ghost" href="/rentals">Clear filter</a>
<?php endif; ?>
        <span class="toolbar-summary"><?= $e(Format::plural(count($rows), 'agreement')) ?></span>
    </form>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Agreement</th><th scope="col">Customer</th><th scope="col">Vehicle</th><th scope="col">Dates</th><th scope="col" class="num">Days</th><th scope="col" class="num">Base amount</th><th scope="col">Status</th></tr></thead>
            <tbody>
<?php foreach ($pager->rows as $r): $href = '/rentals/detail?agreement_id=' . (int) $r['agreement_id']; ?>
                <tr data-href="<?= $e($href) ?>">
                    <td><a class="cell-strong" href="<?= $e($href) ?>">#<?= (int) $r['agreement_id'] ?></a><span class="cell-sub"><?= $e(Status::label($r['rental_type'])) ?></span></td>
                    <td><?= $e($r['customer_name']) ?></td>
                    <td><span class="mono"><?= $e($r['plate_number']) ?></span><span class="cell-sub"><?= $e($r['make'] . ' ' . $r['model']) ?></span></td>
                    <td class="nowrap"><?= $e(Format::date($r['start_date'])) ?><span class="cell-sub">to <?= $e(Format::date($r['end_date'])) ?></span></td>
                    <td class="num"><?= (int) $r['rental_days'] ?></td>
                    <td class="num"><?= $e(Format::money($r['base_amount'])) ?></td>
                    <td><?= Status::badge('rental', $r['status']) ?><?php if ($r['rental_type'] === 'chauffeur' && $r['status'] === 'reserved' && $r['driver_id'] === null): ?> <span class="badge badge-danger">Needs driver</span><?php endif; ?></td>
                </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
                <tr><td class="empty-state" colspan="7"><strong>No agreements found</strong><?php if ($status !== ''): ?><a href="/rentals">Show all agreements</a><?php else: ?>New reservations appear here.<?php endif; ?></td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->render('agreement') ?>
</section>
<?php View::end(); ?>
