<?php
declare(strict_types=1);

use TripleR\Config;
use TripleR\Repositories\StaffUserRepository;
use TripleR\Security\Access;
use TripleR\Support\Format;
use TripleR\Support\Pager;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$roles = Access::ROLES;
// The controller fetched only this page; $total is the full count.
$pager = new Pager($users, 25, 'page', $total ?? null);
$selfId = (int) (View::user()['id'] ?? 0);
// Accounts are listed under their role; ones that can no longer sign in are kept apart at the end.
$groupOf = static fn (array $row): string => $row['email'] === StaffUserRepository::SYSTEM_EMAIL
    ? 'System'
    : ($row['is_active'] && $row['deleted_at'] === null ? Status::label((string) $row['role']) : 'Deactivated');
$groupSizes = array_count_values(array_map($groupOf, $pager->rows));
$lastGroup = null;
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
                <option value="<?= $e($role) ?>"<?= $role === 'driver' && $pickedDriver ? ' selected' : '' ?>><?= $e(Status::label($role)) ?></option>
<?php endforeach; ?>
            </select>
        </label>
        <label class="field">
            <span class="field-label">Driver <span class="optional">(Driver role only)</span></span>
            <select name="driver_id">
                <option value="">Not a driver’s account</option>
<?php foreach ($driversWithoutAccount as $driver): ?>
                <option value="<?= (int) $driver['driver_id'] ?>"<?= (int) $driver['driver_id'] === $pickedDriver ? ' selected' : '' ?>><?= $e($driver['full_name']) ?></option>
<?php endforeach; ?>
            </select>
        </label>
        <button class="button button-primary" type="submit">Create account</button>
        <small class="field-hint">A driver’s account shows that driver their own trips and nothing else. Each driver has one account: active drivers without one are listed.</small>
    </form>
</section>

<section class="panel" aria-labelledby="users-title">
    <div class="panel-heading"><div><h2 id="users-title"><?= $showDeactivated ? 'Deactivated accounts' : 'Accounts' ?></h2><p><?= $showDeactivated ? 'These accounts cannot sign in. They are kept, not deleted, because the sign-in history and audit log refer to them.' : 'Grouped by role. Choose Edit to change an email or a role.' ?></p></div><span class="badge badge-neutral"><?= $pager->total ?></span></div>
    <form class="toolbar" method="get" action="/admin/users">
        <label class="field">
            <span class="field-label">Show</span>
            <select name="show" data-auto-submit>
                <option value="">Current accounts</option>
                <option value="deactivated"<?= $showDeactivated ? ' selected' : '' ?>>Deactivated accounts (<?= (int) $deactivatedCount ?>)</option>
            </select>
        </label>
        <button class="button button-secondary" type="submit" data-auto-apply>Apply</button>
    </form>
    <div class="table-wrap">
        <table class="data-table" data-stack>
            <thead><tr><th scope="col">Email</th><th scope="col">Status</th><th scope="col" class="num">Failed sign-ins</th><th scope="col" class="actions">Actions</th></tr></thead>
            <tbody>
<?php foreach ($pager->rows as $staffUser):
    $active = $staffUser['is_active'] && $staffUser['deleted_at'] === null;
    $email = (string) $staffUser['email'];
    $system = $email === StaffUserRepository::SYSTEM_EMAIL; // Shown, never changed.
    $group = $groupOf($staffUser);
?>
<?php if ($group !== $lastGroup): $lastGroup = $group; ?>
                <tr class="group-row"><th colspan="4" scope="colgroup"><?= $e($group) ?><span class="group-count"><?= $e(Format::plural($groupSizes[$group], 'account')) ?></span></th></tr>
<?php endif; ?>
                <tr>
                    <td class="cell-strong"><?= $e($email) ?><?php if ($system): ?> <span class="badge badge-info">System account</span><small class="field-hint">Online bookings and expired holds are recorded under it. It cannot sign in or be changed.</small><?php endif; ?><?php if ($staffUser['driver_id'] !== null): ?><span class="cell-sub">Signs in as driver <a href="/fleet/drivers/detail?driver_id=<?= (int) $staffUser['driver_id'] ?>"><?= $e($staffUser['driver_name'] ?? 'unknown') ?></a></span><?php endif; ?></td>
                    <td>
<?php if ($system): ?>
                        <span class="badge badge-neutral">Cannot sign in</span>
<?php else: ?>
                        <span class="badge <?= $active ? 'badge-success' : 'badge-neutral' ?>"><?= $active ? 'Active' : 'Deactivated' ?></span>
<?php if (!$active): ?><span class="cell-sub">Would return as <?= $e(Status::label((string) $staffUser['role'])) ?></span><?php endif; ?>
<?php if ($staffUser['locked_at'] !== null && ($label = $lockLabel((string) $staffUser['locked_at'])) !== null): ?> <span class="badge badge-danger"><?= $e($label) ?></span><?php endif; ?>
<?php if ($staffUser['must_change_password']): ?> <span class="badge badge-warning">Must change password</span><?php endif; ?>
<?php endif; ?>
                    </td>
                    <td class="num"><?= (int) $staffUser['failed_login_count'] ?></td>
                    <td class="actions">
<?php if ($system): ?>
                        <span class="field-hint">No actions</span>
<?php else: ?>
                        <div class="cell-actions cell-actions--inline">
                            <a class="button button-ghost button-small" href="/admin/users/edit?user_id=<?= (int) $staffUser['id'] ?>" aria-label="Edit <?= $e($email) ?>">Edit</a>
                            <a class="button button-ghost button-small" href="/admin/sessions?user_id=<?= (int) $staffUser['id'] ?>" aria-label="Sessions of <?= $e($email) ?>">Sessions</a>
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
<?php if ((int) $staffUser['id'] !== $selfId): // The server refuses this for your own account, so the button is not offered. ?>
                            <form method="post" action="/admin/users/deactivate" data-confirm="Deactivate <?= $e($email) ?>? They lose access immediately. You can reactivate the account later." data-confirm-action="Deactivate">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $staffUser['id'] ?>">
                                <button class="button button-danger-quiet button-small" type="submit">Deactivate</button>
                            </form>
<?php else: ?>
                            <span class="badge badge-info">You</span>
<?php endif; ?>
<?php else: ?>
                            <form method="post" action="/admin/users/reactivate">
                                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $staffUser['id'] ?>">
                                <button class="button button-secondary button-small" type="submit">Reactivate</button>
                            </form>
<?php endif; ?>
                        </div>
<?php endif; ?>
                    </td>
                </tr>
<?php endforeach; ?>
<?php if ($users === []): ?>
                <tr><td class="empty-state" colspan="4"><strong><?= $showDeactivated ? 'No deactivated accounts' : 'No accounts yet' ?></strong></td></tr>
<?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= $pager->render('account') ?>
</section>
<?php View::end(); ?>
