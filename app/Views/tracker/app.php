<?php
declare(strict_types=1);

use TripleR\Support\SiteProfile;
use TripleR\Support\View;

/*
 * The tracker: a one-screen web app for the phone that travels with a rented vehicle.
 * Staff create a link for the rental and show it as a QR code; scanning it opens this page
 * with the link's token after the "#". The page keeps the token on the phone, shows which
 * vehicle it is for, and, once the person presses Start and allows it, sends the phone's GPS
 * position every few seconds until they press Stop or the vehicle is returned.
 *
 * Everything here is filled in by tracker-app.js; the markup holds every state so the script
 * only shows and hides.
 */
$e = static fn (mixed $value): string => View::e($value);
$office = (string) SiteProfile::get('brand.full_name', 'the rental office');
$phone = (string) SiteProfile::get('contact.phone_display');
$phoneHref = (string) SiteProfile::get('contact.phone_href');

View::begin('entry', ['title' => 'Vehicle tracker', 'variant' => 'solo', 'scripts' => ['tracker-app.js'], 'manifest' => '/track/manifest.json']);
?>
<div class="tracker" data-tracker data-session-url="/api/tracking/session" data-report-url="/api/tracking/report">
    <p class="eyebrow">Vehicle tracker</p>
    <h1>Share this phone’s location</h1>

    <section data-tracker-view="loading">
        <p class="entry-lead" role="status">Checking your tracker link…</p>
    </section>

    <section class="stack" data-tracker-view="unlinked" hidden>
        <p class="callout" role="status" data-tracker-unlinked-reason>This phone is not connected to a rental yet.</p>
        <p class="entry-lead">Ask the rental office to show you the tracker QR code for your vehicle, and scan it with this phone’s camera.</p>
        <p class="entry-help">Rental office: <a href="<?= $e($phoneHref) ?>"><?= $e($phone) ?></a> (<?= $e(SiteProfile::get('contact.hours')) ?>).</p>
    </section>

    <section class="stack" data-tracker-view="ready" hidden>
        <p class="tracker-status" data-tracker-status data-state="idle" role="status"><span class="tracker-dot" aria-hidden="true"></span><span data-tracker-status-text>Not sharing</span></p>
        <dl class="facts facts--list">
            <div><dt>Vehicle</dt><dd data-tracker-field="vehicle"></dd></div>
            <div><dt>Booking</dt><dd class="mono" data-tracker-field="reference"></dd></div>
            <div><dt>Due back</dt><dd data-tracker-field="due_back"></dd></div>
        </dl>
        <p class="alert" role="alert" data-tracker-problem hidden></p>
        <button class="button button-primary button-block tracker-button" type="button" data-tracker-start>Start sharing location</button>
        <button class="button button-secondary button-block tracker-button" type="button" data-tracker-stop hidden>Stop sharing</button>
        <dl class="facts facts--list" data-tracker-readout hidden>
            <div><dt>Last sent</dt><dd data-tracker-field="sent_at">—</dd></div>
            <div><dt>Accuracy</dt><dd data-tracker-field="accuracy">—</dd></div>
            <div><dt>Speed</dt><dd data-tracker-field="speed">—</dd></div>
            <div><dt>Updates sent</dt><dd data-tracker-field="count">0</dd></div>
        </dl>
        <section class="policy" aria-labelledby="tracker-terms">
            <h2 id="tracker-terms">What is shared</h2>
            <p>While sharing is on, this phone sends its position to <?= $e($office) ?> every few seconds, so the office can see where the vehicle is. Only the latest position is kept; no trail of where you have been is stored.</p>
            <p>Sharing stops when you press Stop, close this page, or the vehicle is returned. Keep this page open and the screen on while driving. Your browser asks for permission first.</p>
        </section>
    </section>

    <section class="stack" data-tracker-view="ended" hidden>
        <p class="notice" role="status">This rental has ended. Location sharing has stopped and this phone is no longer connected.</p>
        <p class="entry-lead">Thank you for renting with <?= $e($office) ?>. You can close this page.</p>
    </section>

    <noscript><p class="alert">The tracker needs JavaScript. Turn it on in your browser, then reload this page.</p></noscript>
</div>
<?php View::end(); ?>
