<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\Icon;
use TripleR\Support\SiteProfile;
use TripleR\Support\View;

/*
 * Public booking page, in the public site's layout so it reads as the next page after the
 * fleet on the landing page. A fleet class opens it at /book?class=<slug> (config/site.php).
 * Step 1 asks for the dates; steps 2 to 4, on the same address, list the vehicles free for
 * those dates with their totals, take the customer's details and show the policy to accept.
 */
$e = static fn (mixed $value): string => View::e($value);
$manila = new DateTimeZone('Asia/Manila');
$today = (new DateTimeImmutable('today', $manila))->format('Y-m-d');
$noticeHours = intdiv(\TripleR\Services\OnlineBookingService::MIN_NOTICE_MINUTES, 60);
$latestStart = (new DateTimeImmutable('today +90 days', $manila))->format('Y-m-d');
$start = (string) ($values['start_date'] ?? '');
$end = (string) ($values['end_date'] ?? '');
$chosenTime = (string) ($values['pickup_time'] ?? '09:00');
$phone = (string) SiteProfile::get('contact.phone_display');
$phoneHref = (string) SiteProfile::get('contact.phone_href');
$currency = (string) SiteProfile::get('currency_symbol', '');
$fleet = (array) SiteProfile::get('fleet', []);

$classSlug = (string) ($class['slug'] ?? '');
$classTypes = (array) ($class['body_types'] ?? []);
// A class with no body types (the limousine) is arranged by phone, not booked here.
$bookable = $class === null || $classTypes !== [];
$article = static fn (string $name): string => preg_match('/^[aeiou]/i', $name) === 1 ? 'an' : 'a';
$heading = $class === null ? 'Reserve a vehicle' : ($bookable ? 'Reserve ' : 'Book ') . $article((string) $class['name']) . ' ' . $class['name'];

// Links on this page keep the dates already chosen.
$dates = $start !== '' && $end !== '' ? ['start_date' => $start, 'end_date' => $end] : [];
$bookUrl = static fn (string $slug) => '/book' . (($query = ($slug !== '' ? ['class' => $slug] : []) + $dates) ? '?' . http_build_query($query) : '');
$imageFor = static function (string $bodyType) use ($fleet): string {
    foreach ($fleet as $item) {
        if (in_array($bodyType, (array) ($item['body_types'] ?? []), true)) {
            return (string) $item['image'];
        }
    }
    return '';
};

$shown = $vehicles;
if ($vehicles !== null && $class !== null) {
    $shown = array_values(array_filter($vehicles, static fn (array $vehicle): bool => in_array($vehicle['body_type'], $classTypes, true)));
}
// The class card introduces the class; once the dates are in, the vehicle list takes its place.
$showCard = $class !== null && ($vehicles === null || !$bookable);
$chosenVehicle = (int) ($values['vehicle_id'] ?? 0);
if ($shown && !in_array($chosenVehicle, array_column($shown, 'vehicle_id'), true)) {
    $chosenVehicle = (int) $shown[0]['vehicle_id'];
}
$chosen = null;
foreach ($shown ?? [] as $vehicle) {
    if ((int) $vehicle['vehicle_id'] === $chosenVehicle) {
        $chosen = $vehicle;
    }
}

View::begin('public', [
    'title' => ($class === null ? 'Book a vehicle' : $heading) . ' | ' . SiteProfile::get('brand.full_name'),
    'description' => 'Choose your dates and a vehicle, and reserve it with a 30% downpayment.',
    'nav' => [['Fleet', '/#fleet'], ['Services', '/#services'], ['How it works', '/#how'], ['Requirements', '/#requirements'], ['Contact', '/#book']],
    'home' => '/',
    'actions' => [['Find my booking', '/book/find', 'quiet'], ['Call ' . $phone, $phoneHref, 'outline']],
    'scripts' => ['book.js'],
    'page' => 'page-book',
]);
?>
<section class="booking" aria-labelledby="booking-title">
    <div class="booking-inner">
        <header class="booking-heading">
            <p class="eyebrow" data-enter="100">Book online</p>
            <h1 id="booking-title" data-split="lines" data-split-delay="150" data-split-stagger="100"><?= $e($heading) ?></h1>
<?php if ($bookable): ?>
            <p class="booking-lead" data-enter="400">Choose your dates to see which vehicles are free. Online bookings are self-drive; for a rental with a driver, call <a href="<?= $e($phoneHref) ?>"><?= $e($phone) ?></a>.</p>
<?php else: ?>
            <p class="booking-lead" data-enter="400">The <?= $e(strtolower((string) $class['name'])) ?> comes with a chauffeur, so it is arranged by phone. Call <a href="<?= $e($phoneHref) ?>"><?= $e($phone) ?></a> with your date and we take it from there.</p>
<?php endif; ?>
        </header>
<?php if ($error !== null): ?>
        <p class="alert" role="alert"><?= $e($error) ?></p>
<?php endif; ?>

        <nav class="class-picker" aria-label="Vehicle class" data-enter="500">
            <a class="class-option" href="<?= $e($bookUrl('')) ?>"<?= $class === null ? ' aria-current="true"' : '' ?>>
                <span class="class-option-thumb"><?= Icon::svg('car') ?></span>
                <span class="class-option-text"><strong>All vehicles</strong><small>Every class</small></span>
            </a>
<?php foreach ($fleet as $item): ?>
            <a class="class-option" href="<?= $e($bookUrl((string) $item['slug'])) ?>"<?= $classSlug === $item['slug'] ? ' aria-current="true"' : '' ?>>
                <span class="class-option-thumb"><img src="/assets/img/landing/<?= $e($item['image']) ?>" alt="" width="96" height="64"></span>
                <span class="class-option-text"><strong><?= $e($item['name']) ?></strong><small>from <?= $e($currency . number_format((float) $item['from'])) ?> / <?= $e($item['unit']) ?></small></span>
            </a>
<?php endforeach; ?>
        </nav>

        <div class="booking-start<?= $showCard && $bookable ? ' booking-start--pair' : '' ?>">
<?php if ($showCard): ?>
        <div class="class-banner" data-enter="600">
            <img src="/assets/img/landing/<?= $e($class['image']) ?>" alt="" width="640" height="360">
            <div class="class-banner-body">
                <h2><?= $e($class['name']) ?></h2>
                <p><?= $e($class['blurb']) ?></p>
                <ul class="fleet-specs">
                    <li><?= (int) $class['seats'] ?> passengers</li>
                    <li><?= (int) $class['bags'] ?> bags</li>
                    <li>from <?= $e($currency . number_format((float) $class['from'])) ?> / <?= $e($class['unit']) ?></li>
                </ul>
<?php if (!$bookable): ?>
                <p class="class-banner-action"><a class="button button-primary button-large" href="<?= $e($phoneHref) ?>"><?= Icon::svg('phone') ?>Call <?= $e($phone) ?></a></p>
<?php else: ?>
                <p class="class-banner-note">The photo shows the class, not the exact vehicle. Rates here are starting prices; the list below shows each vehicle’s own rate.</p>
<?php endif; ?>
            </div>
        </div>
<?php endif; ?>

<?php if ($bookable): ?>
        <div class="booking-start-main">
        <form class="date-bar" method="get" action="/book" aria-labelledby="dates-title" data-date-form data-enter="<?= $showCard ? 700 : 600 ?>">
<?php if ($class !== null): ?>
            <input type="hidden" name="class" value="<?= $e($classSlug) ?>">
<?php endif; ?>
            <h2 class="booking-step-title" id="dates-title"><span class="booking-step-number" aria-hidden="true">1</span>Choose your dates</h2>
            <label class="field"><span class="field-label">Pickup date</span><input type="date" name="start_date" value="<?= $e($start) ?>" min="<?= $e($earliestDate) ?>" max="<?= $e($latestStart) ?>" required data-date-start></label>
            <label class="field"><span class="field-label">Return date</span><input type="date" name="end_date" value="<?= $e($end) ?>" min="<?= $e($earliestDate) ?>" required data-date-end></label>
            <button class="button <?= $vehicles === null ? 'button-primary' : 'button-outline' ?> button-large" type="submit"><?= $vehicles === null ? 'See available vehicles' : 'Change dates' ?></button>
            <p class="date-bar-note" data-date-note>Up to 30 days, starting within the next 90 days.</p>
        </form>
<?php if ($vehicles === null): ?>
        <ol class="booking-next" aria-label="What happens next">
            <li class="reveal"><h3>You pick a vehicle</h3><p>Every vehicle free on your dates is listed with its total, the 30% downpayment and the balance.</p></li>
            <li class="reveal" data-delay="80"><h3>We hold it for 24 hours</h3><p>Reserving charges nothing. You choose how to pay the downpayment on your booking page.</p></li>
            <li class="reveal" data-delay="160"><h3>You collect the keys</h3><p>Bring your ID and licence, pay the balance, and we inspect the vehicle together.</p></li>
        </ol>
<?php endif; ?>
        </div>
<?php endif; ?>
        </div>

<?php if ($bookable && $period !== null && !$vehicles): ?>
        <p class="callout" role="status"><strong>No vehicles are free for those dates.</strong> Try other dates, or call the rental office on <a href="<?= $e($phoneHref) ?>"><?= $e($phone) ?></a>.</p>
<?php elseif ($bookable && $period !== null && !$shown): ?>
        <p class="callout" role="status"><strong>No <?= $e(strtolower((string) $class['name'])) ?> is free for those dates.</strong> <?= $e(Format::plural(count($vehicles), 'vehicle')) ?> in other classes <?= count($vehicles) === 1 ? 'is' : 'are' ?> free: <a href="<?= $e($bookUrl('')) ?>">see all vehicles</a>, or try other dates.</p>
<?php elseif ($bookable && $period !== null && $policy !== null): ?>
        <form method="post" action="/book" class="booking-form" data-booking-form>
            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="start_date" value="<?= $e($period['start']) ?>">
            <input type="hidden" name="end_date" value="<?= $e($period['end']) ?>">
            <input type="hidden" name="policy_version_id" value="<?= (int) $policy['rules_version_id'] ?>">
<?php if ($class !== null): ?>
            <input type="hidden" name="class" value="<?= $e($classSlug) ?>">
<?php endif; ?>

            <div class="booking-main">
                <fieldset class="booking-step">
                    <legend class="booking-step-title"><span class="booking-step-number" aria-hidden="true">2</span>Choose a vehicle</legend>
                    <p class="booking-step-note"><?= $e(Format::plural(count($shown), 'vehicle')) ?> free for <?= $e(Format::plural($period['days'], 'day')) ?><?php if ($class !== null && count($vehicles) > count($shown)): ?>. <a href="<?= $e($bookUrl('')) ?>">See all <?= count($vehicles) ?> free vehicles</a><?php endif; ?></p>
<?php foreach ($shown as $vehicle): ?>
<?php $thumb = $imageFor((string) $vehicle['body_type']); ?>
                    <label class="choice">
                        <input type="radio" name="vehicle_id" value="<?= (int) $vehicle['vehicle_id'] ?>" required<?= $chosenVehicle === (int) $vehicle['vehicle_id'] ? ' checked' : '' ?> data-name="<?= $e($vehicle['name']) ?>" data-total="<?= $e(Format::money($vehicle['total'])) ?>" data-downpayment="<?= $e(Format::money($vehicle['downpayment'])) ?>" data-balance="<?= $e(Format::money($vehicle['balance'])) ?>">
<?php if ($thumb !== ''): ?>
                        <img class="choice-thumb" src="/assets/img/landing/<?= $e($thumb) ?>" alt="" width="96" height="64" loading="lazy">
<?php endif; ?>
                        <span class="choice-body">
                            <span class="choice-title"><?= $e($vehicle['name']) ?></span>
                            <span class="choice-sub"><?= $e($vehicle['details']) ?></span>
                            <span class="choice-sub"><?= $e(Format::money($vehicle['daily_rate'])) ?> a day</span>
                        </span>
                        <span class="choice-price">
                            <strong><?= $e(Format::money($vehicle['total'])) ?></strong>
                            <span class="choice-sub">Downpayment <?= $e(Format::money($vehicle['downpayment'])) ?></span>
                            <span class="choice-sub">At pickup <?= $e(Format::money($vehicle['balance'])) ?></span>
                        </span>
                    </label>
<?php endforeach; ?>
                </fieldset>

                <fieldset class="booking-step">
                    <legend class="booking-step-title"><span class="booking-step-number" aria-hidden="true">3</span>Your details</legend>
                    <div class="field-grid">
                        <label class="field"><span class="field-label">Full name</span><input name="full_name" value="<?= $e($values['full_name'] ?? '') ?>" maxlength="160" autocomplete="name" required></label>
                        <label class="field"><span class="field-label">Mobile number</span><input name="phone" type="tel" value="<?= $e($values['phone'] ?? '') ?>" maxlength="20" autocomplete="tel" placeholder="0917 123 4567" required><small class="field-hint">You use this number, with your booking reference, to open your booking again.</small></label>
                        <label class="field"><span class="field-label">Email <span class="optional">(optional)</span></span><input name="email" type="email" value="<?= $e($values['email'] ?? '') ?>" maxlength="254" autocomplete="email"></label>
                        <label class="field"><span class="field-label">Pickup time</span>
                            <select name="pickup_time" required>
<?php foreach ($pickupTimes as $time): ?>
                                <option value="<?= $e($time) ?>"<?= $chosenTime === $time ? ' selected' : '' ?>><?= $e(date('g:i A', strtotime($time))) ?></option>
<?php endforeach; ?>
                            </select>
                            <small class="field-hint">The vehicle is due back at the same time on the return date.<?php if ($period['start'] === $today): ?> For a pickup today, only times at least <?= $noticeHours ?> hours from now are offered.<?php endif; ?></small>
                        </label>
                    </div>
                </fieldset>

                <section class="booking-step policy" aria-labelledby="policy-title">
                    <h2 class="booking-step-title" id="policy-title"><span class="booking-step-number" aria-hidden="true">4</span><?= $e($policy['title']) ?> <span class="muted">(version <?= (int) $policy['version_number'] ?>)</span></h2>
                    <p><?= $e($policy['body']) ?></p>
                    <label class="check-field"><input type="checkbox" name="accept_policy" value="1" required<?= ($values['accept_policy'] ?? '') === '1' ? ' checked' : '' ?>> <span>I have read this policy and I accept it. I understand the downpayment is <strong>non-refundable</strong>.</span></label>
                </section>
            </div>

            <aside class="booking-summary" aria-labelledby="summary-title">
                <h2 id="summary-title">Your booking</h2>
                <dl>
                    <div><dt>Pickup</dt><dd><?= $e(Format::date($period['start'])) ?></dd></div>
                    <div><dt>Return</dt><dd><?= $e(Format::date($period['end'])) ?></dd></div>
                    <div><dt>Length</dt><dd><?= $e(Format::plural($period['days'], 'day')) ?></dd></div>
                    <div><dt>Vehicle</dt><dd data-summary="name"><?= $e($chosen['name'] ?? '') ?></dd></div>
                    <div class="booking-summary-total"><dt>Total</dt><dd data-summary="total"><?= $e(Format::money($chosen['total'] ?? null)) ?></dd></div>
                    <div class="booking-summary-due"><dt>Downpayment (30%)</dt><dd data-summary="downpayment"><?= $e(Format::money($chosen['downpayment'] ?? null)) ?></dd></div>
                    <div><dt>Balance at pickup</dt><dd data-summary="balance"><?= $e(Format::money($chosen['balance'] ?? null)) ?></dd></div>
                </dl>
                <button class="button button-primary button-large button-block" type="submit">Reserve and continue to payment</button>
                <p class="booking-summary-help">Reserving holds the vehicle for 24 hours. Nothing is charged on this page: you choose how to pay the downpayment on your booking page.</p>
            </aside>
        </form>
<?php endif; ?>

        <p class="booking-foot"><a class="link-arrow" href="/book/find">Already booked? Find my booking<?= Icon::svg('arrow-right') ?></a></p>
    </div>
</section>
<?php View::end(); ?>
