<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$roles = ['system_admin', 'fleet_manager', 'front_desk', 'driver_coordinator', 'mechanic', 'finance_staff', 'auditor', 'support_staff'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit staff user | Triple R Gensan</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/auth.js" defer></script>
</head>
<body class="auth-page">
<main class="auth-card">
    <p class="eyebrow">System administration</p>
    <h1>Edit user</h1>
    <form method="post" action="/admin/users/update" data-auth-form>
        <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
        <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
        <label for="email">Email</label><input id="email" name="email" type="email" maxlength="191" value="<?= $escape((string) $user['email']) ?>" required>
        <label for="role">Role</label><select id="role" name="role" required><?php foreach ($roles as $role): ?><option value="<?= $escape($role) ?>"<?= $user['role'] === $role ? ' selected' : '' ?>><?= $escape(str_replace('_', ' ', $role)) ?></option><?php endforeach; ?></select>
        <button type="submit">Save user</button>
    </form>
    <p><a href="/admin/users">Back to users</a></p>
</main>
</body>
</html>
