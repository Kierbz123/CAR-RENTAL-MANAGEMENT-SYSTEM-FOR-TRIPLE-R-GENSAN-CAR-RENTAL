<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\SiteProfile;
use TripleR\Support\View;

/*
 * The simulated gateway's checkout. NO REAL MONEY MOVES, and the page says so first.
 *
 * It stands where a payment gateway's own page would be. It deliberately looks like neither a
 * wallet nor a bank, carries no logos, and never asks for a PIN, a one-time code or a
 * password. The buttons choose what the "gateway" reports back, so each outcome can be shown.
 *
 * $payment is the pending payment; $cardToVerify is "Visa ending 4242" once a test card was
 * accepted and the bank's verification step is next.
 */
$e = static fn (mixed $value): string => View::e($value);
$amount = Format::money($payment['amount']);
$kind = $payment['method'] === 'card' ? 'card' : ($payment['method'] === 'online_banking' ? 'bank' : 'wallet');
$hidden = '<input type="hidden" name="_csrf" value="' . $e($csrfToken) . '"><input type="hidden" name="receipt" value="' . $e($payment['receipt_number']) . '">';

View::begin('entry', ['title' => 'Demo Pay checkout', 'variant' => 'solo', 'wide' => $kind === 'card' && $cardToVerify === null, 'back' => ['/customer/booking', 'Back to your booking']]);
?>
<p class="demo-banner" role="note"><strong>Demonstration checkout.</strong> No real money is moved, and nothing entered here reaches a bank or a wallet.</p>
<p class="eyebrow">Demo Pay · simulated payment gateway</p>
<h1>Pay <?= $e($amount) ?></h1>
<?php if ($problem): ?>
<p class="alert" role="alert"><?= $e($problem) ?></p>
<?php endif; ?>
<dl class="facts facts--list">
    <div><dt>Paying</dt><dd><?= $e(SiteProfile::get('brand.full_name')) ?></dd></div>
    <div><dt>For</dt><dd>Downpayment, booking <span class="mono"><?= $e($payment['booking_reference']) ?></span></dd></div>
    <div><dt>Method</dt><dd><?= $e($method['label']) ?></dd></div>
    <div><dt>Checkout open until</dt><dd><?= $e(Format::time($payment['expires_at'])) ?></dd></div>
</dl>

<?php if ($kind === 'card' && $cardToVerify === null): ?>
<form method="post" action="/pay/demo" class="stack" autocomplete="off">
    <?= $hidden ?>
    <fieldset class="choice-group">
        <legend>Card details</legend>
        <label class="field"><span class="field-label">Card number</span><input name="card_number" inputmode="numeric" maxlength="23" autocomplete="off" placeholder="4242 4242 4242 4242" required><small class="field-hint">Test numbers only. See the list below.</small></label>
        <div class="form-grid">
            <label class="field"><span class="field-label">Expiry (MM/YY)</span><input name="card_expiry" inputmode="numeric" maxlength="7" autocomplete="off" placeholder="12/30" required></label>
            <label class="field"><span class="field-label">Security code</span><input name="card_cvv" inputmode="numeric" maxlength="3" autocomplete="off" placeholder="123" required></label>
        </div>
    </fieldset>
    <div class="button-row">
        <button class="button button-primary" type="submit" name="outcome" value="card">Pay <?= $e($amount) ?></button>
        <button class="button button-secondary" type="submit" name="outcome" value="cancelled" formnovalidate>Cancel</button>
        <button class="button button-ghost" type="submit" name="outcome" value="timed_out" formnovalidate>Let it time out</button>
    </div>
</form>
<section class="policy" aria-labelledby="cards-title">
    <h2 id="cards-title">Test cards</h2>
    <p>Each number below produces one outcome. Any expiry date and security code will do. A number that is not on this list is refused, so a real card cannot be used here, and the number itself is never stored: only the brand and last four digits are kept.</p>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th scope="col">Card number</th><th scope="col">Brand</th><th scope="col">What it shows</th></tr></thead>
            <tbody>
<?php foreach ($testCards as $number => $card): ?>
                <tr><td class="mono nowrap"><?= $e(trim(chunk_split((string) $number, 4, ' '))) ?></td><td><?= $e($card['brand']) ?></td><td><?= $e($card['shows']) ?></td></tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php elseif ($kind === 'card'): ?>
<section class="policy" aria-labelledby="verify-title">
    <h2 id="verify-title">Bank verification</h2>
    <p>The card <strong><?= $e($cardToVerify) ?></strong> was accepted. A real bank would now ask the cardholder to confirm the payment, usually with a code sent to their phone. This checkout asks for no code: choose how that step ends.</p>
</section>
<form method="post" action="/pay/demo" class="button-row">
    <?= $hidden ?>
    <button class="button button-primary" type="submit" name="outcome" value="approved">Verification passed</button>
    <button class="button button-danger" type="submit" name="outcome" value="verification_failed">Verification failed</button>
    <button class="button button-secondary" type="submit" name="outcome" value="cancelled">Cancel</button>
</form>

<?php elseif ($kind === 'bank'): ?>
<form method="post" action="/pay/demo" class="stack">
    <?= $hidden ?>
    <label class="field"><span class="field-label">Your bank</span>
        <select name="bank" required>
<?php foreach ($banks as $bank): ?>
            <option value="<?= $e($bank) ?>"><?= $e($bank) ?></option>
<?php endforeach; ?>
        </select>
        <small class="field-hint">A real checkout would send you to your bank to sign in. This one never asks for a bank password.</small>
    </label>
    <p class="muted">Choose what happens next.</p>
    <div class="button-row">
        <button class="button button-primary" type="submit" name="outcome" value="approved">Approve payment</button>
        <button class="button button-secondary" type="submit" name="outcome" value="cancelled">Cancel</button>
        <button class="button button-ghost" type="submit" name="outcome" value="timed_out">Let it time out</button>
    </div>
</form>

<?php else: ?>
<section class="policy" aria-labelledby="wallet-title">
    <h2 id="wallet-title"><?= $e($method['label']) ?> account (made up)</h2>
    <dl class="facts facts--list">
        <div><dt>Account name</dt><dd>Juan Dela Cruz</dd></div>
        <div><dt>Mobile number</dt><dd class="mono">09•• ••• 1234</dd></div>
    </dl>
    <p class="muted">A real checkout would ask you to sign in to your wallet here. This one never asks for a PIN or a one-time code.</p>
</section>
<p class="muted">Choose what happens next.</p>
<form method="post" action="/pay/demo" class="button-row">
    <?= $hidden ?>
    <button class="button button-primary" type="submit" name="outcome" value="approved">Approve payment</button>
    <button class="button button-danger" type="submit" name="outcome" value="insufficient">Not enough balance</button>
    <button class="button button-secondary" type="submit" name="outcome" value="cancelled">Cancel</button>
    <button class="button button-ghost" type="submit" name="outcome" value="timed_out">Let it time out</button>
</form>
<?php endif; ?>
<?php View::end(); ?>
