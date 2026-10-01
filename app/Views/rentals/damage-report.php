<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$reportId = (int) $report['report_id'];
$agreementId = (int) $report['agreement_id'];
$hasDamage = (int) $report['has_damage'] === 1;
$phases = ['pre' => 'Pre-rental', 'during' => 'During rental', 'post' => 'Post-rental'];
$phaseKeys = array_keys($phases);
$currentIndex = array_search($report['phase'], $phaseKeys, true);

View::begin('staff', ['title' => 'Damage report #' . $reportId, 'crumbs' => [['Agreements', '/rentals'], ['#' . $agreementId, '/rentals/detail?agreement_id=' . $agreementId], ['Damage report #' . $reportId, null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow">Damage report #<?= $reportId ?></p>
        <h1><?= $e(Status::label($report['phase'])) ?> inspection</h1>
        <div class="page-meta">
            <span class="badge <?= $hasDamage ? 'badge-danger' : 'badge-success' ?>"><?= $hasDamage ? 'Damage recorded' : 'No damage found' ?></span>
            <span>Recorded <?= $e(Format::datetime($report['created_at'])) ?></span>
            <span><?= $e($report['recorded_by_email']) ?></span>
        </div>
    </div>
    <div class="page-header-actions">
        <button class="button button-secondary" type="button" data-print hidden>Print</button>
        <a class="button button-secondary" href="/rentals/detail?agreement_id=<?= $agreementId ?>#damage">Back to agreement #<?= $agreementId ?></a>
    </div>
</header>

<ol class="stepper" aria-label="Inspection stage">
<?php foreach ($phases as $key => $label):
    $index = array_search($key, $phaseKeys, true);
    $stepClass = $key === $report['phase'] ? ' is-active' : ($index < $currentIndex ? ' is-complete' : '');
?>
    <li class="stepper-step<?= $stepClass ?>"<?= $key === $report['phase'] ? ' aria-current="step"' : '' ?>><span class="stepper-number"><?= $index + 1 ?></span><span class="stepper-label"><?= $e($label) ?></span></li>
<?php endforeach; ?>
</ol>

<section class="panel" aria-labelledby="finding-title">
    <div class="panel-heading"><h2 id="finding-title">What was found</h2></div>
    <div class="panel-body">
<?php if ($hasDamage): ?>
        <dl class="facts">
            <div><dt>Where on the vehicle</dt><dd><?= $e($report['location']) ?></dd></div>
            <div><dt>Kind of damage</dt><dd><?= $e($report['damage_type']) ?></dd></div>
            <div><dt>Severity</dt><dd><?= Status::badge('severity', $report['severity']) ?></dd></div>
            <div><dt>Estimated repair cost</dt><dd><?= $report['repair_cost_suggestion'] === null ? 'Not entered' : $e(Format::money($report['repair_cost_suggestion'])) ?></dd></div>
        </dl>
<?php else: ?>
        <p>The vehicle was inspected and no damage was found.</p>
<?php endif; ?>
<?php if ($report['notes']): ?>
        <div><h3>Notes</h3><p class="timeline-note"><?= $e($report['notes']) ?></p></div>
<?php endif; ?>
        <div>
            <h3>Photos</h3>
<?php if (!$report['photos']): ?>
            <p class="muted">No photos were attached.</p>
<?php else: ?>
            <div class="photo-grid">
<?php foreach ($report['photos'] as $photo): $src = '/rentals/damage/photo?photo_id=' . (int) $photo['photo_id']; ?>
                <figure>
                    <a href="<?= $e($src) ?>" target="_blank" rel="noopener"><img src="<?= $e($src) ?>" alt="<?= $e($photo['original_filename']) ?>" loading="lazy"></a>
                    <figcaption><?= $e($photo['original_filename']) ?> · <?= number_format(((int) $photo['size_bytes']) / 1024, 1) ?> KB</figcaption>
                </figure>
<?php endforeach; ?>
            </div>
<?php endif; ?>
        </div>
    </div>
</section>

<section class="panel" aria-labelledby="liability-title">
    <div class="panel-heading"><div><h2 id="liability-title">Liability decision history</h2><p>A decision is never edited. A new one replaces it and both stay on record.</p></div></div>
<?php if (!$report['decisions']): ?>
    <p class="empty-state">No liability decision recorded.</p>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">When</th><th scope="col">Finding</th><th scope="col" class="num">Amount</th><th scope="col">Reason</th><th scope="col">Replaces</th></tr></thead>
            <tbody>
<?php foreach ($report['decisions'] as $decision): $liable = (int) $decision['customer_liable'] === 1; ?>
                <tr>
                    <td><span class="nowrap"><?= $e(Format::datetime($decision['created_at'])) ?></span><span class="cell-sub"><?= $e($decision['actor_email']) ?></span></td>
                    <td><span class="badge <?= $liable ? 'badge-danger' : 'badge-success' ?>"><?= $liable ? 'Customer liable' : 'Customer not liable' ?></span></td>
                    <td class="num"><?= $e(Format::money($decision['liable_amount'])) ?></td>
                    <td><?= $e($decision['reason']) ?></td>
                    <td><?= $decision['supersedes_decision_id'] === null ? 'First decision' : 'Decision #' . (int) $decision['supersedes_decision_id'] ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</section>

<section class="panel" aria-labelledby="charge-title">
    <div class="panel-heading"><h2 id="charge-title">Damage charge</h2></div>
<?php if (!$report['postings']): ?>
    <p class="empty-state">No damage charge has been posted.</p>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">When</th><th scope="col" class="num">Approved amount</th><th scope="col">Adjustment reason</th><th scope="col">Charge on the agreement</th></tr></thead>
            <tbody>
<?php foreach ($report['postings'] as $posting): ?>
                <tr>
                    <td><span class="nowrap"><?= $e(Format::datetime($posting['created_at'])) ?></span><span class="cell-sub"><?= $e($posting['actor_email']) ?></span></td>
                    <td class="num"><?= $e(Format::money($posting['approved_amount'])) ?></td>
                    <td><?= $e($posting['adjustment_reason'] ?? 'No adjustment') ?></td>
                    <td><?= $e($posting['charge_description']) ?> · <?= $e(Format::money($posting['charge_amount'])) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</section>
<?php View::end(); ?>
