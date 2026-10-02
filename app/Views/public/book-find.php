<?php
declare(strict_types=1);

use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);

View::begin('entry', [
    'title' => 'Find my booking',
    'variant' => 'solo',
    'back' => ['/book', 'Book a vehicle'],
]);
?>
<p class="eyebrow">Your reservation</p>
<h1>Find my booking</h1>
<p class="entry-lead">Enter the booking reference you were given and the mobile number you booked with.</p>
<?php if ($error !== null): ?>
<p class="alert" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form method="post" action="/book/find" class="stack">
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <label class="field"><span class="field-label">Booking reference</span><input name="reference" value="<?= $e($values['reference'] ?? '') ?>" maxlength="12" autocomplete="off" autocapitalize="characters" placeholder="8 letters and numbers" required></label>
    <label class="field"><span class="field-label">Mobile number</span><input name="phone" type="tel" value="<?= $e($values['phone'] ?? '') ?>" maxlength="20" autocomplete="tel" placeholder="0917 123 4567" required></label>
    <button class="button button-primary button-block" type="submit">Open my booking</button>
</form>
<?php View::end(); ?>
