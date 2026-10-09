<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$today = Format::today();
// Trips in the order a driver needs them: the one under way, what is coming, then what is done.
$groups = ['On the road now' => [], 'Coming up' => [], 'Finished' => []];
foreach ($trips as $trip) {
    $groups[match ($trip['status']) { 'active' => 'On the road now', 'reserved', 'confirmed' => 'Coming up', default => 'Finished' }][] = $trip;
}
// Upcoming trips read soonest first; the query returns newest first.
$groups['Coming up'] = array_reverse($groups['Coming up']);
$empty = ['On the road now' => 'No trip is under way.', 'Coming up' => 'No trips are booked for you yet.', 'Finished' => 'No finished trips yet.'];

View::begin('staff', ['title' => 'My trips']);
?>
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow">Driver</p>
        <h1>My trips</h1>
        <p class="page-lead">The chauffeur bookings you are assigned to. Times are in Manila time.</p>
    </div>
</header>
<?php if ($notice): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?php if ($driver === null): ?>
<p class="callout" role="status"><strong>Your driver record is not active.</strong> No trips are shown while it is switched off. Ask the fleet manager if this is a mistake.</p>
<?php else: ?>
<?php foreach ($groups as $heading => $rows): ?>
        <section class="panel">
            <div class="panel-heading"><h2><?= $e($heading) ?></h2><span class="badge badge-neutral"><?= count($rows) ?></span></div>
<?php if (!$rows): ?>
            <p class="empty-state"><?= $e($empty[$heading]) ?></p>
<?php else: ?>
            <div class="table-wrap">
                <table class="data-table" data-stack>
                    <thead><tr><th scope="col">Trip</th><th scope="col">Pickup</th><th scope="col">Return</th><th scope="col">Vehicle</th><th scope="col">Customer</th><th scope="col">Status</th><th scope="col" class="actions">Location</th></tr></thead>
                    <tbody>
<?php foreach ($rows as $trip): ?>
                        <tr>
                            <td class="cell-strong mono"><?= $e($trip['booking_reference']) ?></td>
                            <td><?= $e(Format::date($trip['start_date'])) ?><span class="cell-sub"><?= $e($trip['scheduled_pickup_at'] ? Format::time($trip['scheduled_pickup_at']) : 'Time not set') ?></span></td>
                            <td><?= $e(Format::date($trip['end_date'])) ?><span class="cell-sub"><?= $e($trip['scheduled_return_at'] ? Format::time($trip['scheduled_return_at']) : 'Time not set') ?></span></td>
                            <td><span class="mono"><?= $e($trip['plate_number']) ?></span><span class="cell-sub"><?= $e(trim($trip['make'] . ' ' . $trip['model'] . ($trip['color'] ? ', ' . $trip['color'] : ''))) ?></span></td>
                            <td><?= $e($trip['customer_name']) ?></td>
                            <td><?= Status::badge('rental', $trip['status']) ?></td>
                            <td class="actions">
<?php if ($trip['can_share']): ?>
                                <div class="cell-actions">
<?php if ($trip['sharing']): ?>
                                    <span class="badge badge-success">Sharing location</span>
<?php endif; ?>
                                    <form method="post" action="/driver/trips/track"<?= $trip['sharing'] ? ' data-confirm="Move location sharing to this phone? The phone that is sharing now will stop." data-confirm-action="Move sharing" data-confirm-tone="neutral"' : '' ?>>
                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                        <input type="hidden" name="agreement_id" value="<?= (int) $trip['agreement_id'] ?>">
                                        <button class="button <?= $trip['sharing'] ? 'button-secondary' : 'button-primary' ?> button-small" type="submit"><?= $trip['sharing'] ? 'Move sharing to this phone' : 'Share my location' ?></button>
                                    </form>
                                </div>
<?php else: ?>
                                <span class="muted"><?= $trip['status'] === 'reserved' ? 'After it is confirmed' : '—' ?></span>
<?php endif; ?>
                            </td>
                        </tr>
<?php endforeach; ?>
                    </tbody>
                </table>
            </div>
<?php endif; ?>
        </section>
<?php endforeach; ?>
        <section class="panel" aria-labelledby="record-title">
            <div class="panel-heading"><div><h2 id="record-title">My record</h2><p>Kept by the fleet manager. Ask them to correct anything that is wrong.</p></div></div>
            <div class="panel-body profile-photo">
                <?= View::avatar($hasPhoto ? '/driver/photo' : null, (string) $driver['full_name'], 'avatar avatar--large') ?>
                <dl class="facts">
                    <div><dt>Name</dt><dd><?= $e($driver['full_name']) ?></dd></div>
                    <div><dt>Status</dt><dd><?= Status::badge('driver', $driver['status']) ?></dd></div>
                    <div><dt>Licence valid to</dt><dd><?= $e(Format::date($driver['license_expiry'])) ?><?php if ($driver['license_expiry'] < $today): ?> <span class="badge badge-danger">Expired</span><?php elseif ($driver['license_expiry'] <= (new DateTimeImmutable($today))->modify('+30 days')->format('Y-m-d')): ?> <span class="badge badge-warning">Renew soon</span><?php endif; ?></dd></div>
                </dl>
            </div>
        </section>
<?php endif; ?>
<?php View::end(); ?>
