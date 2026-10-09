<?php
declare(strict_types=1);

use TripleR\Controllers\Fleet\DriverController;
use TripleR\Support\Format;
use TripleR\Support\Icon;
use TripleR\Support\Pager;
use TripleR\Support\View;

/*
 * The driver roster. It is built around one question: who can take a booking?
 * The tabs count where every driver stands today; "Free between" asks the same of other dates.
 */
$e = static fn (mixed $value): string => View::e($value);
// The controller fetched only this page; $total is the full count.
$pager = new Pager($drivers, 25, 'page', $total ?? null);
$unreadable = count(array_filter($drivers, static fn (array $row): bool => $row['license_display'] === DriverController::UNREADABLE));

// Every link on the page keeps the filters already chosen and changes one of them.
$chosen = ['search' => $search, 'state' => $state, 'show' => $removed ? 'removed' : '', 'free_from' => $freeFrom, 'free_to' => $freeTo, 'sort' => $sort === 'name' ? '' : $sort];
$link = static function (array $change) use ($chosen): string {
    $query = array_filter(array_merge($chosen, $change), static fn (mixed $value): bool => $value !== null && $value !== '');
    return '/fleet/drivers' . ($query === [] ? '' : '?' . http_build_query($query));
};
$tabs = ['' => 'All drivers', 'free' => 'Free', 'booked' => 'Booked ahead', 'on_trip' => 'On a trip', 'unavailable' => 'Not assignable'];
$current = array_sum($tally) - $tally['removed'];
$filtered = $search !== '' || $state !== '' || $freeFrom !== null;
$dates = $freeFrom === null ? '' : ($freeFrom === $freeTo ? 'on ' . Format::date($freeFrom) : 'from ' . Format::date($freeFrom) . ' to ' . Format::date($freeTo));
$days = static fn (string $date): int => (int) (new DateTimeImmutable($today))->diff(new DateTimeImmutable($date))->format('%r%a');

View::begin('staff', ['title' => 'Drivers', 'crumbs' => [['Fleet', null], ['Drivers', null]], 'scripts' => ['drivers.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Drivers</h1>
        <p class="page-lead">Who is free, who is out with a customer, and whose licence needs attention.</p>
    </div>
<?php if ($canManage): ?>
    <div class="page-header-actions">
        <a class="button button-primary" href="/fleet/drivers/new"><?= Icon::svg('plus') ?>Add driver</a>
    </div>
<?php endif; ?>
</header>

<?php if ($unreadable > 0): ?>
<p class="callout" role="status"><?= $e(Format::plural($unreadable, 'driver record')) ?> <?= $unreadable === 1 ? 'has' : 'have' ?> a licence number that can’t be read with the current encryption key. Open the driver, choose Edit, and enter the licence number again.</p>
<?php endif; ?>

<section class="panel" aria-labelledby="driver-records">
    <h2 class="visually-hidden" id="driver-records">Driver roster</h2>
    <nav class="filter-tabs" aria-label="Drivers by where they stand today">
<?php foreach ($tabs as $key => $label): $on = !$removed && $state === $key; ?>
        <a href="<?= $e($link(['state' => $key, 'show' => ''])) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= $e($label) ?><span class="tab-count"><?= $key === '' ? $current : $tally[$key] ?></span></a>
<?php endforeach; ?>
        <a class="tab-apart" href="<?= $e($link(['state' => '', 'show' => 'removed', 'free_from' => null, 'free_to' => null])) ?>"<?= $removed ? ' aria-current="true"' : '' ?>>Removed<span class="tab-count"><?= $tally['removed'] ?></span></a>
    </nav>
    <form class="toolbar" method="get" action="/fleet/drivers" role="search">
<?php foreach (['state' => $state, 'show' => $removed ? 'removed' : ''] as $name => $value): if ($value !== ''): ?>
        <input type="hidden" name="<?= $e($name) ?>" value="<?= $e($value) ?>">
<?php endif; endforeach; ?>
        <label class="field">
            <span class="field-label"><?= $canReveal ? 'Name or whole licence number' : 'Name' ?></span>
            <input type="search" name="search" value="<?= $e($search) ?>" maxlength="160">
        </label>
<?php if (!$removed): ?>
        <label class="field field--narrow">
            <span class="field-label">Free from</span>
            <input type="date" name="free_from" value="<?= $e($freeFrom ?? '') ?>">
        </label>
        <label class="field field--narrow">
            <span class="field-label">to</span>
            <input type="date" name="free_to" value="<?= $e($freeTo ?? '') ?>">
        </label>
<?php endif; ?>
        <label class="field field--narrow">
            <span class="field-label">Order by</span>
            <select name="sort" data-auto-submit>
                <option value="">Name</option>
                <option value="expiry"<?= $sort === 'expiry' ? ' selected' : '' ?>>Licence expiring first</option>
                <option value="trips"<?= $sort === 'trips' ? ' selected' : '' ?>>Fewest trips done</option>
            </select>
        </label>
        <button class="button button-secondary" type="submit"><?= Icon::svg('search') ?>Search</button>
<?php if ($filtered): ?>
        <a class="button button-ghost" href="<?= $e($link(['search' => '', 'state' => '', 'free_from' => null, 'free_to' => null])) ?>">Clear</a>
<?php endif; ?>
        <span class="toolbar-summary" role="status"><?= $e(Format::plural($pager->total, 'driver')) ?><?= $dates === '' ? '' : ' free ' . $e($dates) ?></span>
    </form>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Driver</th><th scope="col"><?= $freeFrom === null ? 'Availability' : 'Today' ?></th><th scope="col">Licence</th><th scope="col" class="num">Trips done</th></tr></thead>
            <tbody>
<?php foreach ($pager->rows as $driver):
    $id = (int) $driver['driver_id'];
    $href = '/fleet/drivers/detail?driver_id=' . $id;
    $booking = $driver['bookings'][0] ?? null;
    $bookingLink = $booking === null ? '' : '<a href="/rentals/detail?agreement_id=' . (int) $booking['agreement_id'] . '">Booking #' . (int) $booking['agreement_id'] . '</a>';
    $left = $days((string) $driver['license_expiry']);

    // [dot, word, the line under it]. Under it is the booking that explains the word, where there is one.
    if ($removed) {
        $stand = ['', 'Removed', $e(Format::date(substr((string) $driver['deleted_at'], 0, 10)))];
    } elseif ($driver['availability'] === 'on_trip') {
        $late = $booking['end_date'] < $today;
        $stand = ['on_trip', 'On a trip', $bookingLink . ', ' . ($late ? '<strong>was due back ' . $e(Format::date($booking['end_date'])) . '</strong>' : 'due back ' . ($booking['end_date'] === $today ? 'today' : $e(Format::date($booking['end_date']))))];
    } elseif ($driver['availability'] === 'unavailable') {
        $stand = $driver['status'] !== 'active'
            ? ['', 'Inactive', 'Switched off by staff']
            : ['stopped', 'Licence expired', 'Cannot be given a booking'];
    } elseif ($driver['availability'] === 'booked') {
        $stand = ['booked', 'Booked ahead', $bookingLink . ($booking['start_date'] <= $today ? ', due to start ' . ($booking['start_date'] === $today ? 'today' : $e(Format::date($booking['start_date']))) : ', starts ' . $e(Format::date($booking['start_date'])))];
    } else {
        $stand = ['free', 'Free', 'No bookings'];
    }
    $more = count($driver['bookings']) - 1;
?>
                <tr data-href="<?= $e($href) ?>">
                    <td data-label="">
                        <span class="cell-media">
                            <?= View::avatar($driver['has_photo'] ? '/fleet/drivers/photo?driver_id=' . $id : null, (string) $driver['full_name']) ?>

                            <span class="cell-media-text">
                                <a class="cell-strong" href="<?= $e($href) ?>"><?= $e($driver['full_name']) ?></a>
                                <span class="cell-sub"><?= $driver['phone_display'] === null ? 'No phone on file' : 'Phone <span class="mono">' . $e($driver['phone_display']) . '</span>' ?><?php if ($canAccount && (int) $driver['has_account'] === 1): ?>, has a sign-in<?php endif; ?></span>
                            </span>
                        </span>
                    </td>
                    <td><div class="cell-stack">
                        <span class="state<?= $stand[0] === '' ? '' : ' state--' . $stand[0] ?>"><?= $e($stand[1]) ?></span>
                        <span class="cell-sub"><?= $stand[2] ?><?= $more > 0 ? ', and ' . $e(Format::plural($more, 'more booking')) : '' ?></span>
                    </div></td>
                    <td><div class="cell-stack">
<?php if ($driver['license_display'] === DriverController::UNREADABLE): ?>
                        <span class="badge badge-warning">Unreadable</span>
<?php elseif ($driver['license_display'] !== null): ?>
                        <span class="mono"><?= $e($driver['license_display']) ?></span>
<?php endif; ?>
<?php if ($left < 0): ?>
                        <span class="badge badge-danger">Expired <?= $e(Format::date($driver['license_expiry'])) ?></span>
<?php elseif ($left <= 60): ?>
                        <span class="badge badge-warning"><?= $left === 0 ? 'Expires today' : 'Expires in ' . $e(Format::plural($left, 'day')) ?></span>
<?php else: ?>
                        <span class="cell-sub">Valid to <?= $e(Format::date($driver['license_expiry'])) ?></span>
<?php endif; ?>
                    </div></td>
                    <td class="num"><?= (int) $driver['trips_done'] ?></td>
                </tr>
<?php endforeach; ?>
<?php if (!$drivers): ?>
                <tr><td class="empty-state" colspan="4">
<?php if ($removed && !$filtered): ?>
                    <strong>No removed drivers</strong>A driver you remove is kept here and can be restored.
<?php elseif ($filtered): ?>
                    <strong>No driver matches</strong><?= $dates === '' ? '' : 'Nobody on this list is free ' . $e($dates) . '. ' ?><a href="<?= $e($link(['search' => '', 'state' => '', 'free_from' => null, 'free_to' => null])) ?>">Clear the search</a>
<?php else: ?>
                    <strong>No drivers yet</strong><?= $canManage ? 'Add the first driver to start assigning chauffeur bookings.' : 'A fleet manager adds drivers here.' ?>
<?php endif; ?>
                </td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->render('driver') ?>
</section>

<?php View::end(); ?>
