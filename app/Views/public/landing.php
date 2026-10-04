<?php
declare(strict_types=1);

use TripleR\Support\Icon;
use TripleR\Support\SiteProfile;
use TripleR\Support\View;

/*
 * Public landing page. Business details (phone, address, hours, fleet list and
 * rates) are read from config/site.php — edit them there, not here.
 *
 * Motion is declared in the markup and run by landing.js: data-enter="<ms>" plays after
 * the loading screen, data-split reveals a heading line by line (or word by word),
 * .reveal fades a block up as it scrolls into view (data-delay staggers a group), and
 * data-count counts a number up as its panel scrolls in. Without scripts, or with
 * reduced motion, everything is simply visible.
 */
$e = static fn (mixed $value): string => View::e($value);
$fleet = (array) SiteProfile::get('fleet', []);
$currency = (string) SiteProfile::get('currency_symbol', '');
$phone = (string) SiteProfile::get('contact.phone_display');
$phoneHref = (string) SiteProfile::get('contact.phone_href');
$hours = (string) SiteProfile::get('contact.hours');
$openHour = (int) SiteProfile::get('contact.open_hour', 0);
$closeHour = (int) SiteProfile::get('contact.close_hour', 0);
$address = (array) SiteProfile::get('contact.address_lines', []);
$city = (string) SiteProfile::get('contact.city', '');
$mapUrl = (string) SiteProfile::get('contact.map_url', '');

View::begin('public', [
    'title' => SiteProfile::get('brand.full_name') . ' — self-drive and chauffeur service',
    'description' => 'Self-drive rentals and chauffeur service' . ($city !== '' ? ' in ' . $city : '') . '. Choose your dates and a vehicle online, and reserve it with a 30% GCash downpayment.',
    'nav' => [['Fleet', '#fleet'], ['Services', '#services'], ['How it works', '#how'], ['Requirements', '#requirements'], ['Contact', '#book']],
    'intro' => true,
]);
?>
<section class="hero" id="top" aria-labelledby="hero-title" data-hero>
    <div class="hero-backdrop" aria-hidden="true">
        <canvas class="hero-scene" id="hero-scene"></canvas>
    </div>
    <div class="hero-content">
        <p class="eyebrow" data-enter="200">Car rental<?= $city !== '' ? ' · ' . $e($city) : '' ?></p>
        <h1 id="hero-title" data-split="lines" data-split-delay="250" data-split-stagger="120">Your road,<br>your rules.</h1>
        <p class="hero-lead" data-enter="600">Sedans, SUVs, vans and limousines, ready when you are. Drive yourself or sit back with a vetted chauffeur. Pick a vehicle, pick your dates, and go.</p>
        <div class="hero-actions" data-enter="750">
            <a class="button button-primary button-large button-arrow" href="/book">Book now<span class="button-badge" aria-hidden="true"><?= Icon::svg('arrow-right') ?></span></a>
            <a class="button button-outline button-large" href="#fleet">See the fleet</a>
        </div>
    </div>
    <div class="hero-status" data-enter="900">
        <p data-clock data-open-hour="<?= $openHour ?>" data-close-hour="<?= $closeHour ?>"><?= $e($hours) ?></p>
        <p class="hero-status-middle">Self-drive and chauffeur</p>
        <p><a href="#fleet">Scroll to see the fleet<?= Icon::svg('arrow-down') ?></a></p>
    </div>
</section>

<section class="section" id="fleet" aria-labelledby="fleet-title">
    <div class="section-inner">
        <header class="section-heading">
            <p class="eyebrow reveal">The fleet</p>
            <h2 id="fleet-title" data-split="lines">A vehicle for every kind of trip</h2>
            <p class="reveal" data-delay="150">Four classes, each cleaned and checked before it goes out. Pick one to check your dates and book it online.</p>
        </header>
        <ul class="fleet-grid">
<?php foreach ($fleet as $index => $vehicle): ?>
<?php $online = ($vehicle['body_types'] ?? []) !== []; ?>
            <li class="reveal reveal--far" data-delay="<?= ($index % 2) * 90 ?>">
                <article class="fleet-card">
                <div class="fleet-card-media">
                    <img src="/assets/img/landing/<?= $e($vehicle['image']) ?>" alt="" width="640" height="360" loading="lazy">
                    <span class="fleet-card-badge" aria-hidden="true"><?= Icon::svg('arrow-up-right') ?></span>
                </div>
                <div class="fleet-card-body">
                    <h3><a class="fleet-card-link" href="/book?class=<?= $e($vehicle['slug']) ?>"><?= $e($vehicle['name']) ?><span class="visually-hidden">: <?= $online ? 'check your dates and book' : 'how to book' ?></span></a></h3>
                    <p><?= $e($vehicle['blurb']) ?></p>
                    <ul class="fleet-specs">
                        <li><?= (int) $vehicle['seats'] ?> passengers</li>
                        <li><?= (int) $vehicle['bags'] ?> bags</li>
                        <li><?= $online ? 'Self-drive or chauffeur' : 'With a chauffeur' ?></li>
                    </ul>
                    <div class="fleet-card-foot">
                        <p class="fleet-price"><span>from</span> <strong><?= $e($currency . number_format((float) $vehicle['from'])) ?></strong> <span>/ <?= $e($vehicle['unit']) ?></span></p>
                        <span class="fleet-card-cta" aria-hidden="true"><?= $online ? 'Check dates' : 'How to book' ?></span>
                    </div>
                </div>
                </article>
            </li>
<?php endforeach; ?>
        </ul>
        <p class="section-note reveal">Photos show the vehicle class, not the exact vehicle. The booking page lists the vehicles that are free on your dates.</p>
    </div>
</section>

<section class="section section--raised" id="services" aria-labelledby="services-title">
    <div class="section-inner services">
        <div class="services-copy">
            <header class="section-heading">
                <p class="eyebrow reveal">Two ways to travel</p>
                <h2 id="services-title" data-split="lines">Take the wheel, or take the back seat</h2>
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
                    <a class="link-arrow" href="/book">Book a self-drive vehicle<?= Icon::svg('arrow-right') ?></a>
                </article>
                <article class="service reveal" data-delay="120">
                    <h3>Chauffeur</h3>
                    <p>A licensed driver is assigned to your booking before it is confirmed, so you know who is coming.</p>
                    <ul class="tick-list">
                        <li>Drivers with a valid licence on the day of your trip</li>
                        <li>One driver for the whole booking</li>
                        <li>Door-to-door for meetings, events and airport runs</li>
                    </ul>
                    <a class="link-arrow" href="<?= $e($phoneHref) ?>">Call <?= $e($phone) ?> to arrange a driver<?= Icon::svg('arrow-right') ?></a>
                </article>
            </div>
        </div>
        <figure class="services-figure reveal reveal--far">
            <img src="/assets/img/landing/chauffeur.jpg" alt="" width="896" height="1190" loading="lazy">
            <figcaption>Demo photo</figcaption>
        </figure>
    </div>
</section>

<section class="section" id="how" aria-labelledby="how-title">
    <div class="section-inner">
        <header class="section-heading">
            <p class="eyebrow reveal">How it works</p>
            <h2 id="how-title" data-split="lines">Booked in three steps</h2>
        </header>
        <ol class="steps">
            <li class="step reveal">
                <span class="step-number" aria-hidden="true">1</span>
                <h3>Choose dates and a vehicle</h3>
                <p>Pick your dates online to see which vehicles are free and what each costs. Want a driver? Call us and we arrange it.</p>
            </li>
            <li class="step reveal" data-delay="80">
                <span class="step-number" aria-hidden="true">2</span>
                <h3>Pay the 30% downpayment</h3>
                <p>The vehicle is held for 24 hours. Pay 30% by GCash and send the receipt from your booking page. We check it and confirm your reservation.</p>
            </li>
            <li class="step reveal" data-delay="160">
                <span class="step-number" aria-hidden="true">3</span>
                <h3>Pick up and go</h3>
                <p>Bring your ID and pay the balance. We inspect the vehicle together, record the mileage, and hand over the keys.</p>
            </li>
        </ol>
    </div>
</section>

<section class="section section--raised" id="requirements" aria-labelledby="requirements-title">
    <div class="section-inner split-columns">
        <header class="section-heading">
            <p class="eyebrow reveal">Before you book</p>
            <h2 id="requirements-title" data-split="lines">What you need</h2>
            <p class="reveal" data-delay="150">Have these ready and the booking takes a few minutes.</p>
        </header>
        <ul class="requirement-list">
            <li class="reveal"><h3>A valid ID</h3><p>One government-issued ID for the person making the booking.</p></li>
            <li class="reveal" data-delay="80"><h3>A driver’s licence</h3><p>For self-drive only, valid for the whole rental. Not needed with a chauffeur.</p></li>
            <li class="reveal" data-delay="160"><h3>A mobile number</h3><p>Your booking link and updates are sent by SMS.</p></li>
            <li class="reveal" data-delay="240"><h3>A security deposit</h3><p>Held at pickup where one applies, and released when the vehicle is returned in the same condition.</p></li>
        </ul>
    </div>
</section>

<section class="section" id="why" aria-labelledby="why-title">
    <div class="section-inner">
        <p class="eyebrow reveal">Why Triple R</p>
        <h2 class="statement" id="why-title" data-split="words">Vehicles on a maintenance schedule, rates published up front, <span class="statement-quiet">and real people on the phone, <?= $e($hours) ?>.</span></h2>
        <div class="stats reveal reveal--panel">
            <p class="stats-title">The booking, in numbers</p>
            <ul class="stats-grid">
                <li class="reveal"><p class="stat-figure"><span data-count="<?= count($fleet) ?>"><?= count($fleet) ?></span></p><p>vehicle classes, from sedans to a stretch limousine</p></li>
                <li class="reveal" data-delay="90"><p class="stat-figure"><span data-count="30">30</span>%</p><p>downpayment reserves the vehicle; the rest is paid at pickup</p></li>
                <li class="reveal" data-delay="180"><p class="stat-figure"><span data-count="24">24</span></p><p>hours your vehicle is held while you pay</p></li>
                <li class="reveal" data-delay="270"><p class="stat-figure"><span data-count="7">7</span></p><p>days a week the office answers, <?= $e(preg_replace('/^[^,]*,\s*/', '', $hours) ?? $hours) ?></p></li>
            </ul>
        </div>
    </div>
</section>

<section class="section section--cta" id="book" aria-labelledby="book-title">
    <div class="section-inner book">
        <div class="book-copy">
            <p class="eyebrow reveal">Book or ask a question</p>
            <h2 id="book-title" data-split="lines" data-split-stagger="100">Ready when you are</h2>
            <p class="reveal" data-delay="150">Book online and reserve your vehicle with a 30% downpayment, or call us with your dates. Already booked? <a href="/book/find">Find my booking</a>.</p>
            <div class="book-actions reveal" data-delay="250">
                <a class="button button-primary button-large button-arrow" href="/book">Book online<span class="button-badge" aria-hidden="true"><?= Icon::svg('arrow-right') ?></span></a>
                <a class="button button-outline button-large" href="<?= $e($phoneHref) ?>"><?= Icon::svg('phone') ?>Call <?= $e($phone) ?></a>
            </div>
        </div>
        <address class="contact-card reveal reveal--far">
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
