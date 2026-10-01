<?php
declare(strict_types=1);

use TripleR\Support\SiteProfile;
use TripleR\Support\View;

$phone = (string) SiteProfile::get('contact.phone_display');
$phoneHref = (string) SiteProfile::get('contact.phone_href');

View::begin('entry', [
    'title' => 'Your booking',
    'variant' => 'solo',
    'scripts' => ['rental-booking.js'],
]);
?>
<div data-booking-url="/api/rentals/booking-context">
    <p class="eyebrow">Your reservation</p>
    <h1>Your rental booking</h1>
</div>
<p class="entry-lead" id="booking-context-status" role="status" aria-live="polite">Checking your secure booking details…</p>
<button class="button button-secondary" id="booking-context-retry" type="button" hidden>Try again</button>
<dl class="facts facts--list" id="booking-context" hidden>
    <div><dt>Status</dt><dd data-field="status"></dd></div>
    <div><dt>Agreement</dt><dd>#<span data-field="agreement_id"></span></dd></div>
    <div><dt>Vehicle</dt><dd data-field="vehicle"></dd></div>
    <div><dt>Rental dates</dt><dd><span data-field="start_date"></span> to <span data-field="end_date"></span></dd></div>
    <div><dt>Duration</dt><dd><span data-field="rental_days"></span> day(s)</dd></div>
    <div class="facts-total"><dt>Base amount</dt><dd>₱<span data-field="base_amount"></span></dd></div>
</dl>
<p class="entry-help">Need to change something? Call the rental office on <a href="<?= View::e($phoneHref) ?>"><?= View::e($phone) ?></a> (<?= View::e(SiteProfile::get('contact.hours')) ?>) and quote your agreement number.</p>
<?php View::end(); ?>
