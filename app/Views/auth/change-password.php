<?php
declare(strict_types=1);

use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);

View::begin('entry', [
    'title' => 'Set your password',
    'description' => 'Set your staff password for the Triple R Gensan Car Rental workspace.',
    'variant' => 'split',
    'scripts' => ['auth.js'],
    'headline' => 'One last step before you start.',
    'blurb' => 'Replace your temporary password with one only you know. You’ll use it every time you sign in.',
]);
?>
<div>
    <p class="eyebrow">Staff workspace</p>
    <h1>Set your password</h1>
</div>
<p class="entry-lead">Your account has a temporary password. Choose a new one to continue to the workspace.</p>
<?php if ($error !== null): ?>
<p class="alert" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form class="entry-form" method="post" action="/auth/change-password" data-auth-form>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <div class="field">
        <label class="field-label" for="current-password">Temporary or current password</label>
        <div class="password-field">
            <input id="current-password" name="current_password" type="password" autocomplete="current-password" required autofocus data-caps-hint="caps-hint-current">
            <button class="password-toggle" type="button" data-password-toggle data-password-name="current password" aria-controls="current-password" aria-label="Show current password" aria-pressed="false">Show</button>
        </div>
        <p class="caps-hint" id="caps-hint-current" hidden>Caps Lock is on.</p>
    </div>
    <div class="field">
        <label class="field-label" for="new-password">New password</label>
        <div class="password-field">
            <input id="new-password" name="new_password" type="password" autocomplete="new-password" minlength="14" maxlength="200" required aria-describedby="password-rules" data-password-rules="password-rules" data-password-confirm="confirm-password" data-password-current="current-password" data-caps-hint="caps-hint-new">
            <button class="password-toggle" type="button" data-password-toggle data-password-name="new password" aria-controls="new-password" aria-label="Show new password" aria-pressed="false">Show</button>
        </div>
        <p class="caps-hint" id="caps-hint-new" hidden>Caps Lock is on.</p>
        <ul class="rule-list" id="password-rules">
            <li data-rule="length">14 to 200 characters</li>
            <li data-rule="different">Different from your current password</li>
            <li data-rule="match">Both new passwords match</li>
        </ul>
    </div>
    <div class="field">
        <label class="field-label" for="confirm-password">Confirm new password</label>
        <div class="password-field">
            <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="14" maxlength="200" required>
            <button class="password-toggle" type="button" data-password-toggle data-password-name="password confirmation" aria-controls="confirm-password" aria-label="Show password confirmation" aria-pressed="false">Show</button>
        </div>
    </div>
    <button class="button button-primary" type="submit" data-busy-text="Saving…">Save password and continue</button>
</form>
<form method="post" action="/staff/logout">
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <p class="entry-help">Not you, or want to do this later? <button class="button button-ghost button-small" type="submit">Sign out</button></p>
</form>
<?php View::end(); ?>
