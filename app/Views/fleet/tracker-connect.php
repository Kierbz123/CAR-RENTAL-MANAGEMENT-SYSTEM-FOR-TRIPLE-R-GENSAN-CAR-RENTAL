<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

/*
 * Connects a phone to one rental so it can report the vehicle's position.
 * $link is set only on the page shown straight after a link is created: it is displayed once,
 * as a QR code for the phone to scan, and is not stored in a form that can be shown again.
 */
$e = static fn (mixed $value): string => View::e($value);
$id = (int) $agreement['agreement_id'];
$vehicle = trim($agreement['plate_number'] . ' ' . $agreement['make'] . ' ' . $agreement['model']);

View::begin('staff', [
    'title' => 'Connect a tracker phone',
    'crumbs' => [['Agreements', '/rentals'], ['#' . $id, '/rentals/detail?agreement_id=' . $id], ['Tracker phone', null]],
    'scripts' => $link !== null ? ['vendor/qrcode-generator.js', 'qr-render.js'] : [],
]);
?>
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow">Agreement #<?= $id ?> · Reference <span class="mono"><?= $e($agreement['booking_reference']) ?></span></p>
        <h1>Connect a tracker phone</h1>
        <p class="page-lead">A phone that travels with the vehicle shares its GPS position, so the vehicle shows on the live map while it is out. Use the driver’s phone for a rental with a driver, or the customer’s phone with their agreement.</p>
        <div class="page-meta">
            <?= Status::badge('rental', $agreement['status']) ?>
            <span><span class="mono"><?= $e($agreement['plate_number']) ?></span> <?= $e($agreement['make'] . ' ' . $agreement['model']) ?></span>
            <span><?= $e($agreement['customer_name']) ?></span>
            <span><?= $e(Format::date($agreement['start_date'])) ?> to <?= $e(Format::date($agreement['end_date'])) ?></span>
        </div>
    </div>
</header>
<?php if ($notice): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>

<?php if ($link !== null): ?>
<section class="panel" aria-labelledby="scan-title">
    <div class="panel-heading"><div><h2 id="scan-title">Scan this with the phone</h2><p>The code is shown once. Leaving this page hides it; a new one can be made at any time, which disconnects the phone that used the old one.</p></div><span class="badge badge-success">Link ready</span></div>
    <div class="panel-body">
        <div class="qr-code" data-qr="<?= $e($link) ?>" role="img" aria-label="QR code that opens the tracker on a phone, for <?= $e($vehicle) ?>"></div>
        <p class="connect-link"><span class="mono"><?= $e($link) ?></span></p>
<?php if ($isLocalAddress): ?>
        <p class="callout" role="status">This address only works on this computer, and phones only share a location over https. Start the site with <span class="mono">bin\demo-online.ps1</span>, open this page from the public address it prints, and make the code again.</p>
<?php endif; ?>
        <ol class="tracker-steps">
            <li>Open the phone’s camera and point it at the code, then open the link it shows.</li>
            <li>On the phone, press <strong>Start sharing location</strong> and choose <strong>Allow</strong> when the browser asks.</li>
            <li>Keep that page open with the screen on. The vehicle appears on the live map once its pickup is recorded.</li>
        </ol>
    </div>
</section>
<?php endif; ?>

<section class="panel" aria-labelledby="state-title">
    <div class="panel-heading"><div><h2 id="state-title">Tracker phone</h2><p>One phone can be connected to a rental at a time. Its link stops working when the vehicle is returned.</p></div><span class="badge <?= $connected ? 'badge-success' : 'badge-neutral' ?>"><?= $connected ? 'A phone is connected' : 'No phone connected' ?></span></div>
    <div class="panel-body">
<?php if (!$canConnect): ?>
        <p class="muted"><?= in_array($agreement['status'], ['reserved'], true) ? 'A phone can be connected once this reservation is confirmed.' : 'This rental is over, so there is nothing to track.' ?></p>
<?php else: ?>
        <div class="button-row">
            <form method="post" action="/fleet/tracking/connect"<?= $connected ? ' data-confirm="Make a new tracker code? The phone connected now will stop reporting." data-confirm-action="Make a new code"' : '' ?>>
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="agreement_id" value="<?= $id ?>">
                <button class="button <?= $link === null ? 'button-primary' : 'button-secondary' ?>" type="submit"><?= $connected ? 'Make a new tracker code' : 'Make the tracker code' ?></button>
            </form>
<?php if ($connected): ?>
            <form method="post" action="/fleet/tracking/disconnect" data-confirm="Disconnect the phone? It will stop reporting this vehicle’s position." data-confirm-action="Disconnect phone">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="agreement_id" value="<?= $id ?>">
                <button class="button button-danger" type="submit">Disconnect the phone</button>
            </form>
<?php endif; ?>
        </div>
<?php if ($agreement['status'] === 'confirmed'): ?>
        <p class="muted">The phone can be connected now. Positions are taken from the moment the pickup is recorded.</p>
<?php endif; ?>
<?php endif; ?>
    </div>
</section>

<p class="muted"><a href="/rentals/detail?agreement_id=<?= $id ?>">Back to the agreement</a><?php if ($canWatch): ?> · <a href="/fleet/locations">Open the live map</a><?php endif; ?></p>
<?php View::end(); ?>
