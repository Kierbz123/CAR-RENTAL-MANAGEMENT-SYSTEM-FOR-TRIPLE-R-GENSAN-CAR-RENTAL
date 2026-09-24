<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff sign in | Triple R Gensan</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="auth-page">
<main class="auth-card">
    <p class="eyebrow">Triple R Gensan Car Rental</p>
    <h1>Staff sign in</h1>
    <p>Use your staff account to open the management console.</p>
    <?php if ($error !== null): ?><p class="alert" role="alert"><?= $escape($error) ?></p><?php endif; ?>
    <form method="post" action="/staff/login">
        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" required maxlength="191">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
</main>
</body>
</html>
