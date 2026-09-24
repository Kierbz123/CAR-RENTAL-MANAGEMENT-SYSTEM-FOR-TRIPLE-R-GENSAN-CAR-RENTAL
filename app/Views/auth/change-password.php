<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change password | Triple R Gensan</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/auth.js" defer></script>
</head>
<body class="auth-page">
<main class="auth-card">
    <p class="eyebrow">Triple R Gensan Car Rental</p>
    <h1>Set your password</h1>
    <p>Change your temporary password before continuing to the management console.</p>
    <?php if ($error !== null): ?><p class="alert" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <form method="post" action="/auth/change-password" data-auth-form>
        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
        <label for="current-password">Current password</label>
        <input id="current-password" name="current_password" type="password" autocomplete="current-password" required>
        <label for="new-password">New password</label>
        <input id="new-password" name="new_password" type="password" autocomplete="new-password" minlength="14" maxlength="200" required>
        <label for="confirm-password">Confirm new password</label>
        <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="14" maxlength="200" required>
        <button type="submit">Change password</button>
    </form>
    <form method="post" action="/staff/logout" data-auth-form>
        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
        <button class="button-secondary" type="submit">Sign out</button>
    </form>
</main>
</body>
</html>
