<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\PaymentMethods;
use TripleR\Support\SiteProfile;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

/*
 * The customer's booking page. $booking is null when this browser has no booking open
 * (no secure link, and nothing booked or found here).
 */
$e = static fn (mixed $value): string => View::e($value);
$phone = (string) SiteProfile::get('contact.phone_display');
$phoneHref = (string) SiteProfile::get('contact.phone_href');
$gcashNumber = trim((string) SiteProfile::get('payments.gcash_number', ''));
$gcashName = trim((string) SiteProfile::get('payments.gcash_account_name', ''));
$statusLabels = ['reserved' => 'Reserved, waiting for the downpayment', 'confirmed' => 'Confirmed', 'active' => 'In progress', 'returned' => 'Returned', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'Not picked up'];

View::begin('entry', ['title' => 'Your booking', 'variant' => 'solo']);

if ($booking === null):
?>
<p class="eyebrow">Your reservation</p>
<h1>Your rental booking</h1>
<p class="entry-lead" role="status">No booking is open in this browser. A secure link may have expired, or the page was open for a long time.</p>
<p><a class="button button-primary button-block" href="/book/find">Find my booking</a></p>
<p class="entry-help">You need your booking reference and the mobile number you booked with. To make a new booking, <a href="/book">choose your dates</a>.</p>
<?php
else:
    $downpayment = $booking['downpayment_status'];
    $waitingForPayment = $booking['status'] === 'reserved' && $downpayment === 'due';
    // Only the newest proof matters to the customer: is it waiting, or was it turned down?
    $latest = $proofs ? $proofs[count($proofs) - 1] : null;
    $pending = $latest !== null && $latest['proof_status'] === 'submitted' ? $latest : null;
    $lastRejected = $latest !== null && $latest['proof_status'] === 'rejected' ? $latest : null;
    // The same for online payments: the newest attempt, if it did not go through, and the one that paid.
    $lastPayment = $payments ? $payments[count($payments) - 1] : null;
    $lastUnpaid = $lastPayment !== null && in_array($lastPayment['payment_status'], ['failed', 'cancelled', 'expired'], true) ? $lastPayment : null;
    $paidDownpayment = null;
    foreach ($payments as $payment) {
        if ($payment['purpose'] === 'downpayment' && $payment['payment_status'] === 'paid') {
            $paidDownpayment = $payment;
        }
    }
    $unpaidWhy = ['failed' => 'did not go through', 'cancelled' => 'was cancelled', 'expired' => 'ran out of time'];
    $addressLines = (array) SiteProfile::get('contact.address_lines', []);
    $statusText = $booking['status'] === 'reserved' && $downpayment !== 'due' ? 'Reserved' : ($statusLabels[$booking['status']] ?? Status::label($booking['status']));
?>
<p class="eyebrow">Your reservation</p>
<h1>Booking <span class="mono"><?= $e($booking['booking_reference']) ?></span></h1>
<?php if ($notice): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?php if ($problem): ?>
<p class="alert" role="alert"><?= $e($problem) ?></p>
<?php endif; ?>
<dl class="facts facts--list" id="booking-context">
    <div><dt>Status</dt><dd><?= $e($statusText) ?></dd></div>
    <div><dt>Booking reference</dt><dd class="mono"><?= $e($booking['booking_reference']) ?></dd></div>
    <div><dt>Vehicle</dt><dd><?= $e($booking['vehicle']) ?></dd></div>
    <div><dt>Rental dates</dt><dd><?= $e(Format::date($booking['start_date'])) ?> to <?= $e(Format::date($booking['end_date'])) ?></dd></div>
    <div><dt>Duration</dt><dd><?= $e(Format::plural((int) $booking['rental_days'], 'day')) ?></dd></div>
    <div class="facts-total"><dt>Total</dt><dd><?= $e(Format::money($booking['total_amount'])) ?></dd></div>
<?php if ($downpayment !== 'not_required'): ?>
    <div><dt>Downpayment (30%)</dt><dd><?= $e(Format::money($booking['downpayment_amount'])) ?> · <?= $downpayment === 'received' ? 'received' : 'not yet received' ?></dd></div>
    <div><dt>Balance due at pickup</dt><dd><?= $e(Format::money($booking['balance_at_pickup'])) ?></dd></div>
<?php endif; ?>
</dl>

<?php if ($waitingForPayment && $pending !== null): ?>
<section class="policy" aria-labelledby="pay-title">
    <h2 id="pay-title">Your proof of payment is being checked</h2>
    <p>We received your proof with GCash reference <span class="mono"><?= $e($pending['reference_number']) ?></span> on <?= $e(Format::datetime($pending['submitted_at'])) ?>. The rental office will check it against their GCash account and then confirm your reservation. Your vehicle stays held while they do.</p>
</section>
<?php elseif ($waitingForPayment && $paymentInProgress !== null): ?>
<section class="policy" aria-labelledby="pay-title">
    <h2 id="pay-title">You have a payment in progress</h2>
    <p>You started paying <strong><?= $e(Format::money($paymentInProgress['amount'])) ?></strong> by <?= $e(PaymentMethods::label($paymentInProgress['method'])) ?>. It stays open until <?= $e(Format::time($paymentInProgress['expires_at'])) ?>. To pay another way, open the checkout and cancel it first.</p>
    <p><a class="button button-primary button-block" href="<?= $e($checkoutUrl) ?>">Continue to the checkout</a></p>
</section>
<?php elseif ($waitingForPayment): ?>
<h2 id="pay-title">Pay the downpayment</h2>
<p>Pay <strong><?= $e(Format::money($booking['downpayment_amount'])) ?></strong> by <strong><?= $e(Format::datetime($booking['hold_expires_at'])) ?></strong> to keep your vehicle. After that an unpaid reservation is released. The downpayment is non-refundable.</p>
<?php if ($lastUnpaid !== null): ?>
<p class="callout" role="status"><strong>Your last payment <?= $e($unpaidWhy[$lastUnpaid['payment_status']]) ?>.</strong> <?= $lastUnpaid['failure_reason'] ? $e($lastUnpaid['failure_reason']) . '. ' : '' ?>Nothing was charged. You can try again, or pay another way.</p>
<?php endif; ?>
<?php if ($payOnline): ?>
<section class="policy" aria-labelledby="pay-online-title">
    <h2 id="pay-online-title">Pay online</h2>
    <form method="post" action="/customer/booking/pay" class="stack">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <fieldset class="choice-group">
            <legend>Choose how to pay</legend>
<?php $first = true; foreach ($onlineMethods as $key => $method): ?>
            <label class="choice">
                <input type="radio" name="method" value="<?= $e($key) ?>" required<?= $first ? ' checked' : '' ?>>
                <span class="choice-body"><span class="choice-title"><?= $e($method['label']) ?></span><span class="choice-sub"><?= $e($method['kind']) ?></span></span>
            </label>
<?php $first = false; endforeach; ?>
        </fieldset>
<?php if ($policyToAccept !== null): ?>
        <div>
            <p class="muted"><strong><?= $e($policyToAccept['title']) ?> (version <?= (int) $policyToAccept['version_number'] ?>).</strong> <?= $e($policyToAccept['body']) ?></p>
            <label class="check-field"><input type="checkbox" name="accept_policy" value="1" required> I have read this policy and I accept it. I understand the downpayment is non-refundable.</label>
        </div>
<?php endif; ?>
        <button class="button button-primary button-block" type="submit">Continue to payment</button>
<?php if ($payOnlineIsDemo): ?>
        <p class="muted">Paying online on this site is a demonstration. The checkout shows what happens when a payment is approved or refused, and no real money is taken.</p>
<?php endif; ?>
    </form>
</section>
<?php endif; ?>
<section class="policy" aria-labelledby="pay-proof-title">
    <h2 id="pay-proof-title"><?= $payOnline ? 'Or send a GCash transfer' : 'Send a GCash transfer' ?></h2>
<?php if ($lastRejected !== null): ?>
    <p class="callout" role="status"><strong>Your last proof was not accepted.</strong> <?= $e($lastRejected['review_note']) ?> Please send a correct one.</p>
<?php endif; ?>
    <ol class="steps">
        <li>Send <strong><?= $e(Format::money($booking['downpayment_amount'])) ?></strong> by GCash to <?php if ($gcashNumber !== ''): ?><strong class="mono"><?= $e($gcashNumber) ?></strong><?= $gcashName !== '' ? ' (' . $e($gcashName) . ')' : '' ?><?php else: ?>the rental office’s GCash number. Call <a href="<?= $e($phoneHref) ?>"><?= $e($phone) ?></a> to get it<?php endif; ?>.</li>
        <li>Enter the reference number from your GCash receipt and attach a screenshot of it below.</li>
        <li>The office checks the payment and confirms your reservation.</li>
    </ol>
    <form method="post" action="/customer/booking/proof" enctype="multipart/form-data" class="stack">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <label class="field"><span class="field-label">GCash reference number</span><input name="reference" maxlength="40" autocomplete="off" required pattern="[A-Za-z0-9 \-]{6,40}"></label>
        <label class="field"><span class="field-label">Screenshot of the receipt</span><input type="file" name="screenshot" accept="image/jpeg,image/png,image/webp" required><small class="field-hint">JPEG, PNG or WebP, up to 8 MB.</small></label>
        <button class="button <?= $payOnline ? 'button-secondary' : 'button-primary' ?> button-block" type="submit">Send proof of payment</button>
    </form>
</section>
<section class="policy" aria-labelledby="pay-cash-title">
    <h2 id="pay-cash-title">Or pay in cash at the office</h2>
    <p>Bring <strong><?= $e(Format::money($booking['downpayment_amount'])) ?></strong> and your booking reference <span class="mono"><?= $e($booking['booking_reference']) ?></span> to the rental office before <?= $e(Format::datetime($booking['hold_expires_at'])) ?>. The office records the payment and gives you a receipt.</p>
    <p class="muted"><?= $e(implode(', ', $addressLines)) ?><?= $addressLines ? ' · ' : '' ?><?= $e(SiteProfile::get('contact.hours')) ?></p>
</section>
<?php elseif ($downpayment === 'received'): ?>
<p class="notice" role="status">Your downpayment of <?= $e(Format::money($booking['downpayment_amount'])) ?> was received<?= $booking['status'] === 'reserved' ? ', and the rental office is confirming your reservation' : '' ?>. <?= (float) $booking['balance_at_pickup'] > 0 ? 'The balance of ' . $e(Format::money($booking['balance_at_pickup'])) . ' is paid in person at pickup.' : 'Nothing more is owed.' ?> Please bring a valid ID.</p>
<?php if ($paidDownpayment !== null): ?>
<p><a href="/customer/booking/payment?receipt=<?= $e($paidDownpayment['receipt_number']) ?>">View your receipt</a> <span class="muted">(<?= $e($paidDownpayment['receipt_number']) ?>)</span></p>
<?php endif; ?>
<?php elseif ($booking['status'] === 'cancelled'): ?>
<p class="callout" role="status">This booking was cancelled. To rent a vehicle, <a href="/book">make a new booking</a> or call the rental office.</p>
<?php endif; ?>

<?php if ($acceptance !== null): ?>
<details class="disclosure">
    <summary>You accepted the <?= $e($acceptance['title']) ?> (version <?= (int) $acceptance['version_number'] ?>) on <?= $e(Format::datetime($acceptance['recorded_at'])) ?></summary>
    <div class="disclosure-body"><p><?= $e($acceptance['body']) ?></p></div>
</details>
<?php endif; ?>
<p class="entry-help">Need to change something? Call the rental office on <a href="<?= $e($phoneHref) ?>"><?= $e($phone) ?></a> (<?= $e(SiteProfile::get('contact.hours')) ?>) and quote your booking reference.</p>
<?php
endif;
View::end();
?>
