<?php
declare(strict_types=1);

use TripleR\Security\BookingPhoneVerification;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$digits = preg_replace('/\D+/', '', $phone) ?? '';

View::begin('entry', [
    'title' => 'Confirm your mobile number',
    'variant' => 'solo',
    'back' => ['/book', 'Change booking details'],
]);
?>
<p class="eyebrow">One more step</p>
<h1>Confirm your mobile number</h1>
<p class="entry-lead">We texted a 6-digit code to the number ending in <strong><?= $e(substr($digits, -4)) ?></strong>. Enter it to finish your booking. It expires in <?= (int) (BookingPhoneVerification::CODE_SECONDS / 60) ?> minutes.</p>
<?php if ($notice !== null): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?php if ($error !== null): ?>
<p class="alert" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form method="post" action="/book/verify" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <label class="field"><span class="field-label">Code</span><input name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" placeholder="6 digits" required autofocus></label>
    <button class="button button-primary button-block" type="submit">Confirm and book</button>
</form>
<form method="post" action="/book/verify" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <input type="hidden" name="resend" value="1">
    <button class="button button-secondary button-block" type="submit">Send a new code</button>
</form>
<?php View::end(); ?>
