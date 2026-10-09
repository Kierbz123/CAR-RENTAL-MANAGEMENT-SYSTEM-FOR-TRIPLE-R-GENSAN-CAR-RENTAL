<?php
declare(strict_types=1);

use TripleR\Security\Access;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

// $user here is the account being edited (set by UserController::edit), not the signed-in admin.
$e = static fn (mixed $value): string => View::e($value);
// A driver's account belongs to a driver record, so its role is not one that can be picked or changed here.
$isDriver = $user['role'] === 'driver';
$roles = Access::STAFF_ROLES;

View::begin('staff', ['title' => 'Edit staff account', 'crumbs' => [['Administration', null], ['Staff accounts', '/admin/users'], ['Edit', null]]]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1>Edit staff account</h1>
        <p class="page-lead">Change the sign-in email or the role. A role change takes effect on the person’s next page load.</p>
    </div>
</header>
<form class="panel" method="post" action="/admin/users/update">
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
    <div class="panel-body">
        <div class="form-grid">
            <label class="field"><span class="field-label">Email</span><input name="email" type="email" maxlength="191" value="<?= $e((string) $user['email']) ?>" required autocomplete="off"></label>
<?php if ($isDriver): ?>
            <div class="field"><span class="field-label">Role</span><input type="hidden" name="role" value="driver"><p>Driver. This account belongs to a driver record, so its role cannot be changed.</p></div>
<?php else: ?>
            <label class="field"><span class="field-label">Role</span>
                <select name="role" required>
<?php foreach ($roles as $role): ?>
                    <option value="<?= $e($role) ?>"<?= $user['role'] === $role ? ' selected' : '' ?>><?= $e(Status::label($role)) ?></option>
<?php endforeach; ?>
                </select>
            </label>
<?php endif; ?>
        </div>
    </div>
    <div class="form-actions">
        <button class="button button-primary" type="submit">Save account</button>
        <a class="button button-ghost" href="/admin/users">Cancel</a>
    </div>
</form>
<?php View::end(); ?>
