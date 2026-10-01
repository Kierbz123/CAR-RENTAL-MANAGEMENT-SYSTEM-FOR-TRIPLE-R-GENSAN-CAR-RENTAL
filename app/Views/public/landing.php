<?php
declare(strict_types=1);

use TripleR\Support\Icon;
use TripleR\Support\SiteProfile;
use TripleR\Support\View;

/*
 * Public landing page. Business details (phone, address, hours, fleet list and
 * rates) are read from config/site.php — edit them there, not here.
 */
$e = static fn (mixed $value): string => View::e($value);
$fleet = (array) SiteProfile::get('fleet', []);
$currency = (string) SiteProfile::get('currency_symbol', '');
$phone = (string) SiteProfile::get('contact.phone_display');
$phoneHref = (string) SiteProfile::get('contact.phone_href');
$hours = (string) SiteProfile::get('contact.hours');
$address = (array) SiteProfile::get('contact.address_lines', []);
$city = (string) SiteProfile::get('contact.city', '');
$mapUrl = (string) SiteProfile::get('contact.map_url', '');

View::begin('public', [
    'title' => SiteProfile::get('brand.full_name') . ' — self-drive and chauffeur service',
    'description' => 'Self-drive rentals and chauffeur service' . ($city !== '' ? ' in ' . $city : '') . '. Choose a vehicle, pick your dates, and get a secure booking link by SMS.',
    'nav' => [['Fleet', '#fleet'], ['Services', '#services'], ['How it works', '#how'], ['Requirements', '#requirements'], ['Contact', '#book']],
]);
?>
<section class="hero" id="top" aria-labelledby="hero-title" data-hero>
    <div class="hero-backdrop" aria-hidden="true">
        <svg class="hero-fallback" viewBox="0 0 400 400" focusable="false">
            <g fill="none" stroke="currentColor">
                <circle cx="200" cy="200" r="182" stroke-width="26" opacity=".5"/>
                <circle cx="200" cy="200" r="146" stroke-width="6"/>
                <circle cx="200" cy="200" r="56" stroke-width="5"/>
                <circle cx="200" cy="200" r="24" stroke-width="10"/>
                <g stroke-width="7" stroke-linecap="round">
                    <path d="M200 144V58M200 256v86M144 200H58M256 200h86"/>
                    <path d="m160 160-61-61M240 240l61 61M160 240l-61 61M240 160l61-61"/>
                </g>
            </g>
        </svg>
        <canvas class="hero-scene" id="hero-scene"></canvas>
    </div>
    <div class="hero-content">
        <p class="eyebrow">Car rental<?= $city !== '' ? ' · ' . $e($city) : '' ?></p>
        <h1 id="hero-title">Your road,<br>your rules.</h1>
        <p class="hero-lead">Sedans, SUVs, vans and limousines, ready when you are. Drive yourself or sit back with a vetted chauffeur. Pick a vehicle, pick your dates, and go.</p>
        <div class="hero-actions">
            <a class="button button-primary button-large" href="#book">Book now</a>
            <a class="button button-outline button-large" href="#fleet">See the fleet</a>
        </div>
        <ul class="hero-points" aria-label="At a glance">
            <li>Self-drive and chauffeur</li>
            <li><?= $e($hours) ?></li>
            <li>Booking link by SMS</li>
        </ul>
    </div>
</section>

<section class="section" id="fleet" aria-labelledby="fleet-title">
    <div class="section-inner">
        <header class="section-heading reveal">
            <p class="eyebrow">The fleet</p>
            <h2 id="fleet-title">A vehicle for every kind of trip</h2>
            <p>Four classes, each cleaned and checked before it goes out. Rates shown are starting prices.</p>
        </header>
        <ul class="fleet-grid">
<?php foreach ($fleet as $vehicle): ?>
            <li class="fleet-card reveal">
                <img src="/assets/img/landing/<?= $e($vehicle['image']) ?>" alt="" width="640" height="360" loading="lazy">
                <div class="fleet-card-body">
                    <h3><?= $e($vehicle['name']) ?></h3>
                    <p><?= $e($vehicle['blurb']) ?></p>
                    <ul class="fleet-specs">
                        <li><?= (int) $vehicle['seats'] ?> passengers</li>
                        <li><?= (int) $vehicle['bags'] ?> bags</li>
                    </ul>
                    <p class="fleet-price"><span>from</span> <strong><?= $e($currency . number_format((float) $vehicle['from'])) ?></strong> <span>/ <?= $e($vehicle['unit']) ?></span></p>
                </div>
            </li>
<?php endforeach; ?>
        </ul>
        <p class="section-note reveal">Photos show the vehicle class, not the exact vehicle. <a href="#book">Ask us</a> which vehicles are free on your dates.</p>
    </div>
</section>

<section class="section section--raised" id="services" aria-labelledby="services-title">
    <div class="section-inner services">
        <div class="services-copy">
            <header class="section-heading reveal">
                <p class="eyebrow">Two ways to travel</p>
                <h2 id="services-title">Take the wheel, or take the back seat</h2>
            </header>
            <div class="service-list">
                <article class="service reveal">
                    <h3>Self-drive</h3>
                    <p>The vehicle is yours for the dates you choose. Collect it, go where you like, and bring it back.</p>
                    <ul class="tick-list">
                        <li>Walk-around inspection with you at pickup and return</li>
                        <li>Odometer recorded both times, so nothing is guessed</li>
                        <li>Deposit released when the vehicle comes back as it left</li>
                    </ul>
                </article>
                <article class="service reveal">
                    <h3>Chauffeur</h3>
                    <p>A licensed driver is assigned to your booking before it is confirmed, so you know who is coming.</p>
                    <ul class="tick-list">
                        <li>Drivers with a valid licence on the day of your trip</li>
                        <li>One driver for the whole booking</li>
                        <li>Door-to-door for meetings, events and airport runs</li>
                    </ul>
                </article>
            </div>
        </div>
        <figure class="services-figure reveal">
            <img src="/assets/img/landing/chauffeur.jpg" alt="" width="896" height="1190" loading="lazy">
            <figcaption>Demo photo</figcaption>
        </figure>
    </div>
</section>

<section class="section" id="how" aria-labelledby="how-title">
    <div class="section-inner">
        <header class="section-heading reveal">
            <p class="eyebrow">How it works</p>
            <h2 id="how-title">Booked in three steps</h2>
        </header>
        <ol class="steps">
            <li class="step reveal">
                <span class="step-number" aria-hidden="true">1</span>
                <h3>Tell us what you need</h3>
                <p>Call with your dates, the kind of vehicle, and whether you want a driver. We check what is free.</p>
            </li>
            <li class="step reveal">
                <span class="step-number" aria-hidden="true">2</span>
                <h3>Get your booking link</h3>
                <p>We reserve the vehicle and send a secure link by SMS. Open it any time to see your booking details.</p>
            </li>
            <li class="step reveal">
                <span class="step-number" aria-hidden="true">3</span>
                <h3>Pick up and go</h3>
                <p>Bring your ID. We inspect the vehicle together, record the mileage, and hand over the keys.</p>
            </li>
        </ol>
    </div>
</section>

<section class="section section--raised" id="requirements" aria-labelledby="requirements-title">
    <div class="section-inner split-columns">
        <header class="section-heading reveal">
            <p class="eyebrow">Before you book</p>
            <h2 id="requirements-title">What you need</h2>
            <p>Have these ready and the booking takes a few minutes.</p>
        </header>
        <ul class="requirement-list">
            <li class="reveal"><h3>A valid ID</h3><p>One government-issued ID for the person making the booking.</p></li>
            <li class="reveal"><h3>A driver’s licence</h3><p>For self-drive only, valid for the whole rental. Not needed with a chauffeur.</p></li>
            <li class="reveal"><h3>A mobile number</h3><p>Your booking link and updates are sent by SMS.</p></li>
            <li class="reveal"><h3>A security deposit</h3><p>Held at pickup where one applies, and released when the vehicle is returned in the same condition.</p></li>
        </ul>
    </div>
</section>

<section class="section" id="why" aria-labelledby="why-title">
    <div class="section-inner">
        <header class="section-heading reveal">
            <p class="eyebrow">Why Triple R</p>
            <h2 id="why-title">Every mile, covered</h2>
        </header>
        <ul class="reason-grid">
            <li class="reveal"><h3>Vehicles you can rely on</h3><p>Each vehicle is on a maintenance schedule and comes off the booking list while it is being serviced.</p></li>
            <li class="reveal"><h3>Prices without surprises</h3><p>Starting rates are published here, and your booking summary shows your dates and amount.</p></li>
            <li class="reveal"><h3>A team that answers</h3><p>Real people on the phone: <?= $e($hours) ?>.</p></li>
        </ul>
    </div>
</section>

<section class="section section--cta" id="book" aria-labelledby="book-title">
    <div class="section-inner book">
        <div class="book-copy reveal">
            <p class="eyebrow">Book or ask a question</p>
            <h2 id="book-title">Ready when you are</h2>
            <p>Call us with your dates and we will hold a vehicle while you decide. Reservations are confirmed by a secure link sent to your phone.</p>
            <a class="button button-primary button-large" href="<?= $e($phoneHref) ?>"><?= Icon::svg('phone') ?>Call <?= $e($phone) ?></a>
        </div>
        <address class="contact-card reveal">
            <h3>Contact</h3>
            <dl>
                <div><dt>Phone</dt><dd><a href="<?= $e($phoneHref) ?>"><?= $e($phone) ?></a></dd></div>
                <div><dt>Hours</dt><dd><?= $e($hours) ?></dd></div>
                <div><dt>Address</dt><dd><?php foreach ($address as $line): ?><span><?= $e($line) ?></span><?php endforeach; ?></dd></div>
            </dl>
<?php if ($mapUrl !== ''): ?>
            <p class="contact-card-action"><a class="button button-outline" href="<?= $e($mapUrl) ?>" target="_blank" rel="noopener"><?= Icon::svg('pin') ?>Get directions</a></p>
<?php endif; ?>
        </address>
    </div>
</section>
<?php View::end(); ?>
