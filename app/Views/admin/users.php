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
    <title>Staff users | Triple R Gensan</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/auth.js" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="/staff/notifications">Triple R Gensan</a>
    <div class="staff-actions"><a href="/admin/users">Users</a><a href="/staff/notifications">Notifications</a></div>
</header>
<main class="page-shell">
    <section class="page-heading"><div><p class="eyebrow">System administration</p><h1>Staff users</h1><p>Create accounts, assign roles, and manage access.</p></div></section>
    <?php if ($notice !== null): ?><p class="alert" role="status"><?= $escape((string) $notice) ?></p><?php endif; ?>
    <?php if ($oneTimePassword !== null): ?>
        <section class="panel credential-panel" aria-labelledby="temporary-password-title">
            <div class="panel-heading"><h2 id="temporary-password-title">Temporary credential — copy now</h2></div>
            <div class="panel-body"><p>Shown once and not stored in plaintext. Deliver it to the user out of band. They must change it before continuing.</p><code class="temporary-password"><?= $escape((string) $oneTimePassword) ?></code></div>
        </section>
    <?php endif; ?>
    <section class="panel admin-panel" aria-labelledby="create-user-title">
        <div class="panel-heading"><h2 id="create-user-title">Create user</h2></div>
        <div class="panel-body">
            <form method="post" action="/admin/users/create" data-auth-form>
                <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>">
                <label for="new-user-email">Email</label><input id="new-user-email" name="email" type="email" maxlength="191" required>
                <label for="new-user-role">Role</label><select id="new-user-role" name="role" required><?php foreach ($roles as $role): ?><option value="<?= $escape($role) ?>"><?= $escape(str_replace('_', ' ', $role)) ?></option><?php endforeach; ?></select>
                <button type="submit">Create and generate temporary password</button>
            </form>
        </div>
    </section>
    <section class="panel admin-panel" aria-labelledby="users-title">
        <div class="panel-heading"><h2 id="users-title">All accounts</h2></div>
        <div class="table-wrap"><table>
            <thead><tr><th>Email</th><th>Role</th><th>Status</th><th>Login failures</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($users as $staffUser): ?>
                <tr>
                    <td><?= $escape((string) $staffUser['email']) ?></td>
                    <td>
                        <form method="post" action="/admin/users/role" class="inline-form" data-auth-form>
                            <input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="user_id" value="<?= (int) $staffUser['id'] ?>">
                            <select name="role" aria-label="Role for <?= $escape((string) $staffUser['email']) ?>"><?php foreach ($roles as $role): ?><option value="<?= $escape($role) ?>"<?= $staffUser['role'] === $role ? ' selected' : '' ?>><?= $escape(str_replace('_', ' ', $role)) ?></option><?php endforeach; ?></select>
                            <button class="button-small" type="submit">Save role</button>
                        </form>
                    </td>
                    <td><?= $staffUser['is_active'] && $staffUser['deleted_at'] === null ? 'Active' : 'Deactivated' ?><?= $staffUser['locked_at'] !== null ? ' · Locked' : '' ?><?= $staffUser['must_change_password'] ? ' · Must change password' : '' ?></td>
                    <td><?= (int) $staffUser['failed_login_count'] ?></td>
                    <td class="action-cell">
                        <a href="/admin/users/edit?user_id=<?= (int) $staffUser['id'] ?>">Edit</a>
                        <a href="/admin/sessions?user_id=<?= (int) $staffUser['id'] ?>">Sessions</a>
                        <?php if ($staffUser['locked_at'] !== null): ?><form method="post" action="/admin/users/unlock" data-auth-form><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="user_id" value="<?= (int) $staffUser['id'] ?>"><button class="button-small" type="submit">Unlock</button></form><?php endif; ?>
                        <?php if ($staffUser['is_active'] && $staffUser['deleted_at'] === null): ?>
                            <form method="post" action="/admin/users/reset-password" data-auth-form><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="user_id" value="<?= (int) $staffUser['id'] ?>"><button class="button-small" type="submit">Reset password</button></form>
                            <form method="post" action="/admin/users/deactivate" data-auth-form><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="user_id" value="<?= (int) $staffUser['id'] ?>"><button class="button-small button-danger" type="submit">Deactivate</button></form>
                        <?php else: ?>
                            <form method="post" action="/admin/users/reactivate" data-auth-form><input type="hidden" name="_csrf" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="user_id" value="<?= (int) $staffUser['id'] ?>"><button class="button-small" type="submit">Reactivate</button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if ($users === []): ?><tr><td colspan="5">No users found.</td></tr><?php endif; ?>
            </tbody>
        </table></div>
    </section>
</main>
</body>
</html>
