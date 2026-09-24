<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff workspace | Triple R Gensan</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<header class="topbar">
    <a class="brand" href="/staff">Triple R Gensan</a>
    <div class="staff-actions"><span><?= $escape((string) $user['email']) ?></span><form method="post" action="/staff/logout"><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><button class="button-secondary" type="submit">Sign out</button></form></div>
</header>
<main class="page-shell">
    <section class="page-heading"><div><p class="eyebrow">Staff workspace</p><h1>Welcome</h1><p>Your account role is <strong><?= $escape(str_replace('_', ' ', (string) $user['role'])) ?></strong>.</p></div></section>
    <section class="panel admin-panel"><div class="panel-heading"><h2>Available tools</h2></div><div class="panel-body">
        <?php if ($canManageUsers): ?><p><a href="/admin/users">Manage staff accounts and sessions</a></p><?php endif; ?>
        <?php if ($canViewNotifications): ?><p><a href="/staff/notifications">View SMS notification history</a></p><?php endif; ?>
        <?php if (!$canManageUsers && !$canViewNotifications): ?><p>This account is active. Its rental-management workspace will be available as the corresponding modules are released.</p><?php endif; ?>
    </div></section>
</main>
</body>
</html>
