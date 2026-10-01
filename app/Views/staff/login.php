<?php
declare(strict_types=1);

use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$isLockout = $error !== null && (http_response_code() === 429 || str_starts_with($error, 'Too many'));
// Keep the typed email after a failed attempt so only the password needs re-entering.
$email = is_string($_POST['email'] ?? null) ? mb_substr(trim($_POST['email']), 0, 191) : '';

View::begin('entry', [
    'title' => 'Staff sign in',
    'description' => 'Staff sign-in for the Triple R Gensan Car Rental workspace.',
    'variant' => 'split',
    'scripts' => ['auth.js'],
    'back' => ['/', '← Back to the Triple R website'],
]);
?>
<div>
    <p class="eyebrow">Staff workspace</p>
    <h1>Sign in</h1>
</div>
<p class="entry-lead">Use the email and password from your staff account.</p>
<?php if ($error !== null && $isLockout): ?>
<p class="auth-lockout" role="alert"><?= $e($error) ?></p>
<?php elseif ($error !== null): ?>
<p class="alert" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form class="entry-form" method="post" action="/staff/login" data-auth-form>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <div class="field">
        <label class="field-label" for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" required maxlength="191" value="<?= $e($email) ?>"<?= $email === '' ? ' autofocus' : '' ?>>
    </div>
    <div class="field">
        <label class="field-label" for="password">Password</label>
        <div class="password-field">
            <input id="password" name="password" type="password" autocomplete="current-password" required data-caps-hint="caps-hint"<?= $email !== '' ? ' autofocus' : '' ?>>
            <button class="password-toggle" type="button" data-password-toggle data-password-name="password" aria-controls="password" aria-label="Show password" aria-pressed="false">Show</button>
        </div>
        <p class="caps-hint" id="caps-hint" hidden>Caps Lock is on.</p>
    </div>
    <button class="button button-primary" type="submit" data-busy-text="Signing in…">Sign in</button>
</form>
<p class="entry-help">Forgot your password? Ask a system administrator to reset it. You’ll get a temporary password to change on your next sign-in.</p>
<?php View::end(); ?>
