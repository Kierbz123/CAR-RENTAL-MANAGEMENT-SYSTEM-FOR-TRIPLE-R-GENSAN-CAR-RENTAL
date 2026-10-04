<?php
declare(strict_types=1);

use TripleR\Config;
use TripleR\Support\Format;
use TripleR\Support\Pager;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$roles = ['system_admin', 'fleet_manager', 'front_desk', 'driver_coordinator', 'finance_staff'];
// The controller fetched only this page; $total is the full count.
$pager = new Pager($users, 25, 'page', $total ?? null);
// When a lock lifts by itself (AUTH_LOCKOUT_MINUTES; 0 means only an administrator can lift it).
$lockMinutes = Config::int('AUTH_LOCKOUT_MINUTES', 15);
$lockLabel = static function (string $lockedAt) use ($lockMinutes): ?string {
    if ($lockMinutes === 0) {
        return 'Locked';
    }
    $until = (new DateTimeImmutable($lockedAt, new DateTimeZone('UTC')))->modify('+' . $lockMinutes . ' minutes');
    return $until > new DateTimeImmutable('now', new DateTimeZone('UTC')) ? 'Locked until ' . Format::time($until->format('Y-m-d H:i:s')) : null;
};

View::begin('staff', ['title' => 'Staff accounts', 'crumbs' => [['Administration', null], ['Staff accounts', null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Staff accounts</h1>
        <p class="page-lead">Create accounts, set roles and manage access to the workspace.</p>
    </div>
</header>
<?php if ($notice !== null): ?>
<p class="notice" role="status"><?= $e((string) $notice) ?></p>
<?php endif; ?>
<?php if ($oneTimePassword !== null): ?>
<section class="panel" aria-labelledby="temporary-password-title">
    <div class="panel-heading"><div><h2 id="temporary-password-title">Temporary password — copy it now</h2><p>Shown once and never stored in plain text.</p></div></div>
    <div class="panel-body">
        <code class="temporary-password"><?= $e((string) $oneTimePassword) ?></code>
        <p class="muted">Give it to the staff member in person or by phone, not by email. They must change it the first time they sign in.</p>
    </div>
</section>
<?php endif; ?>

<section class="panel" aria-labelledby="create-user-title">
    <div class="panel-heading"><div><h2 id="create-user-title">Create an account</h2><p>A temporary password is generated for you to pass on.</p></div></div>
    <form class="toolbar" method="post" action="/admin/users/create">
        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
        <label class="field">
            <span class="field-label">Email</span>
            <input name="email" type="email" maxlength="191" required autocomplete="off">
        </label>
        <label class="field">
            <span class="field-label">Role</span>
            <select name="role" required>
<?php foreach ($roles as $role): ?>
                <option value="<?= $e($role) ?>"><?= $e(Status::label($role)) ?></option>
<?php endforeach; ?>
            </select>
        </label>
        <button class="button button-primary" type="submit">Create account</button>
    </form>
</section>

<section class="panel" aria-labelledby="users-title">
    <div class="panel-heading"><h2 id="users-title">All accounts</h2><span class="badge badge-neutral"><?= $pager->total ?></span></div>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col" class="num">Failed sign-ins</th><th scope="col" class="actions">Actions</th></tr></thead>
            <tbody>
<?php foreach ($pager->rows as $staffUser):
    $active = $staffUser['is_active'] && $staffUser['deleted_at'] === null;
    $email = (string) $staffUser['email'];
?>
                <tr>
                    <td class="cell-strong"><?= $e($email) ?></td>
                    <td>
                        <form method="post" action="/admin/users/role" class="inline-form">
                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                            <input type="hidden" name="user_id" value="<?= (int) $staffUser['id'] ?>">
                            <select name="role" aria-label="Role for <?= $e($email) ?>">
<?php foreach ($roles as $role): ?>
                                <option value="<?= $e($role) ?>"<?= $staffUser['role'] === $role ? ' selected' : '' ?>><?= $e(Status::label($role)) ?></option>
<?php endforeach; ?>
                            </select>
                            <button class="button button-secondary button-small" type="submit">Save role</button>
                        </form>
                    </td>
                    <td>
                        <span class="badge <?= $active ? 'badge-success' : 'badge-neutral' ?>"><?= $active ? 'Active' : 'Deactivated' ?></span>
<?php if ($staffUser['locked_at'] !== null && ($label = $lockLabel((string) $staffUser['locked_at'])) !== null): ?> <span class="badge badge-danger"><?= $e($label) ?></span><?php endif; ?>
<?php if ($staffUser['must_change_password']): ?> <span class="badge badge-warning">Must change password</span><?php endif; ?>
                    </td>
                    <td class="num"><?= (int) $staffUser['failed_login_count'] ?></td>
                    <td class="actions">
                        <div class="cell-actions">
                            <a class="button button-secondary button-small" href="/admin/users/edit?user_id=<?= (int) $staffUser['id'] ?>">Edit</a>
                            <a class="button button-secondary button-small" href="/admin/sessions?user_id=<?= (int) $staffUser['id'] ?>">Sessions</a>
<?php if ($staffUser['locked_at'] !== null): ?>
                            <form method="post" action="/admin/users/unlock">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $staffUser['id'] ?>">
                                <button class="button button-secondary button-small" type="submit">Unlock</button>
                            </form>
<?php endif; ?>
<?php if ($active): ?>
                            <form method="post" action="/admin/users/reset-password" data-confirm="Reset the password for <?= $e($email) ?>? A new temporary password will be generated and their current password stops working." data-confirm-action="Reset password">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $staffUser['id'] ?>">
                                <button class="button button-secondary button-small" type="submit">Reset password</button>
                            </form>
                            <form method="post" action="/admin/users/deactivate" data-confirm="Deactivate <?= $e($email) ?>? They lose access immediately. You can reactivate the account later." data-confirm-action="Deactivate">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $staffUser['id'] ?>">
                                <button class="button button-danger button-small" type="submit">Deactivate</button>
                            </form>
<?php else: ?>
                            <form method="post" action="/admin/users/reactivate">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $staffUser['id'] ?>">
                                <button class="button button-secondary button-small" type="submit">Reactivate</button>
                            </form>
<?php endif; ?>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
<?php if ($users === []): ?>
                <tr><td class="empty-state" colspan="5"><strong>No accounts yet</strong></td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->render('account') ?>
</section>
<?php View::end(); ?>
