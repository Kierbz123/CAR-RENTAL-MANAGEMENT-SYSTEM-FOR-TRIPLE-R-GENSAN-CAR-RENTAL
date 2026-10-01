<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);

View::begin('staff', ['title' => 'Maintenance due report', 'crumbs' => [['Fleet', null], ['Maintenance', '/maintenance'], ['Due report', null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Due and due soon</h1>
        <p class="page-lead">Schedules that are due now or inside their warning window: <?= $e(Format::plural((int) $defaults['days'], 'day')) ?> or <?= $e(Format::km($defaults['kilometers'])) ?> by default. A schedule’s own setting replaces the matching default.</p>
    </div>
    <div class="page-header-actions">
        <button class="button button-secondary" type="button" data-print hidden>Print</button>
        <a class="button button-primary" href="/maintenance/due?format=csv">Download CSV</a>
    </div>
</header>

<section class="panel" aria-labelledby="report-title">
    <div class="panel-heading"><h2 id="report-title">Report</h2><span class="badge <?= $rows ? 'badge-warning' : 'badge-success' ?>"><?= $e(Format::plural(count($rows), 'schedule')) ?></span></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Plate</th><th scope="col">Vehicle</th><th scope="col">Schedule</th><th scope="col">State</th><th scope="col">Next date</th><th scope="col" class="num">Next mileage</th><th scope="col" class="num">Current mileage</th><th scope="col">Warning window</th></tr></thead>
            <tbody>
<?php foreach ($rows as $r): ?>
                <tr>
                    <td><a class="mono cell-strong" href="/maintenance/history?vehicle_id=<?= (int) $r['vehicle_id'] ?>"><?= $e($r['plate_number']) ?></a></td>
                    <td><?= $e($r['make'] . ' ' . $r['model']) ?></td>
                    <td><?= $e($r['schedule_name']) ?></td>
                    <td><?= Status::badge('due', $r['due_state']) ?></td>
                    <td class="nowrap"><?= $e(Format::date($r['next_due_date'])) ?></td>
                    <td class="num"><?= $e(Format::km($r['next_due_mileage'])) ?></td>
                    <td class="num"><?= $e(Format::km($r['current_mileage'])) ?></td>
                    <td><span class="cell-sub"><?= $e(Format::plural((int) $r['effective_due_soon_days'], 'day') . ' / ' . Format::km($r['effective_due_soon_mileage'])) ?></span></td>
                </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
                <tr><td class="empty-state" colspan="8"><strong>Nothing due</strong>No schedule is due or inside its warning window.</td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php View::end(); ?>
