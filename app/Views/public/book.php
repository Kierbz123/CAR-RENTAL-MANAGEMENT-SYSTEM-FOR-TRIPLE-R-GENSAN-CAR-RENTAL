<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\SiteProfile;
use TripleR\Support\View;

/*
 * Public booking page. Step 1 asks for the dates; step 2, on the same address, lists the
 * vehicles free for those dates with their totals and takes the customer's details.
 */
$e = static fn (mixed $value): string => View::e($value);
$manila = new DateTimeZone('Asia/Manila');
$today = (new DateTimeImmutable('today', $manila))->format('Y-m-d');
$latestStart = (new DateTimeImmutable('today +90 days', $manila))->format('Y-m-d');
$start = (string) ($values['start_date'] ?? '');
$end = (string) ($values['end_date'] ?? '');
$chosenVehicle = (int) ($values['vehicle_id'] ?? 0);
$chosenTime = (string) ($values['pickup_time'] ?? '09:00');
$phone = (string) SiteProfile::get('contact.phone_display');
$phoneHref = (string) SiteProfile::get('contact.phone_href');

View::begin('entry', [
    'title' => 'Book a vehicle',
    'description' => 'Choose your dates and a vehicle, and reserve it with a 30% downpayment.',
    'variant' => 'solo',
    'wide' => true,
    'back' => ['/book/find', 'Already booked? Find my booking'],
]);
?>
<p class="eyebrow">Book online</p>
<h1>Reserve a vehicle</h1>
<p class="entry-lead">Choose your dates to see which vehicles are free. Online bookings are self-drive; for a rental with a driver, call <a href="<?= $e($phoneHref) ?>"><?= $e($phone) ?></a>.</p>
<?php if ($error !== null): ?>
<p class="alert" role="alert"><?= $e($error) ?></p>
<?php endif; ?>

<form class="toolbar" method="get" action="/book" aria-label="Rental dates">
    <label class="field"><span class="field-label">Pickup date</span><input type="date" name="start_date" value="<?= $e($start) ?>" min="<?= $e($today) ?>" max="<?= $e($latestStart) ?>" required></label>
    <label class="field"><span class="field-label">Return date</span><input type="date" name="end_date" value="<?= $e($end) ?>" min="<?= $e($today) ?>" required></label>
    <button class="button <?= $vehicles === null ? 'button-primary' : 'button-secondary' ?>" type="submit"><?= $vehicles === null ? 'See available vehicles' : 'Change dates' ?></button>
</form>

<?php if ($vehicles !== null && $period !== null && !$vehicles): ?>
<p class="callout" role="status"><strong>No vehicles are free for those dates.</strong> Try other dates, or call the rental office on <a href="<?= $e($phoneHref) ?>"><?= $e($phone) ?></a>.</p>
<?php elseif ($vehicles !== null && $period !== null && $policy !== null): ?>
<form method="post" action="/book" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <input type="hidden" name="start_date" value="<?= $e($period['start']) ?>">
    <input type="hidden" name="end_date" value="<?= $e($period['end']) ?>">
    <input type="hidden" name="policy_version_id" value="<?= (int) $policy['rules_version_id'] ?>">

    <fieldset class="choice-group">
        <legend><?= $e(Format::date($period['start'])) ?> to <?= $e(Format::date($period['end'])) ?> · <?= $e(Format::plural($period['days'], 'day')) ?> · <?= $e(Format::plural(count($vehicles), 'vehicle')) ?> free</legend>
<?php foreach ($vehicles as $index => $vehicle): ?>
        <label class="choice">
            <input type="radio" name="vehicle_id" value="<?= (int) $vehicle['vehicle_id'] ?>" required<?= $chosenVehicle === (int) $vehicle['vehicle_id'] || ($chosenVehicle === 0 && $index === 0) ? ' checked' : '' ?>>
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

    <fieldset class="choice-group">
        <legend>Your details</legend>
        <label class="field"><span class="field-label">Full name</span><input name="full_name" value="<?= $e($values['full_name'] ?? '') ?>" maxlength="160" autocomplete="name" required></label>
        <label class="field"><span class="field-label">Mobile number</span><input name="phone" type="tel" value="<?= $e($values['phone'] ?? '') ?>" maxlength="20" autocomplete="tel" placeholder="0917 123 4567" required><small class="field-hint">You use this number, with your booking reference, to open your booking again.</small></label>
        <label class="field"><span class="field-label">Email <span class="optional">(optional)</span></span><input name="email" type="email" value="<?= $e($values['email'] ?? '') ?>" maxlength="254" autocomplete="email"></label>
        <label class="field"><span class="field-label">Pickup time</span>
            <select name="pickup_time" required>
<?php foreach ($pickupTimes as $time): ?>
                <option value="<?= $e($time) ?>"<?= $chosenTime === $time ? ' selected' : '' ?>><?= $e(date('g:i A', strtotime($time))) ?></option>
<?php endforeach; ?>
            </select>
            <small class="field-hint">The vehicle is due back at the same time on the return date.</small>
        </label>
    </fieldset>

    <section class="policy" aria-labelledby="policy-title">
        <h2 id="policy-title"><?= $e($policy['title']) ?> <span class="muted">(version <?= (int) $policy['version_number'] ?>)</span></h2>
        <p><?= $e($policy['body']) ?></p>
        <label class="check-field"><input type="checkbox" name="accept_policy" value="1" required<?= ($values['accept_policy'] ?? '') === '1' ? ' checked' : '' ?>> I have read this policy and I accept it. I understand the downpayment is <strong>non-refundable</strong>.</label>
    </section>

    <button class="button button-primary button-block" type="submit">Reserve and continue to payment</button>
    <p class="entry-help">Reserving holds the vehicle for 24 hours. Nothing is charged on this page: you choose how to pay the downpayment on your booking page.</p>
</form>
<?php endif; ?>
<?php View::end(); ?>
