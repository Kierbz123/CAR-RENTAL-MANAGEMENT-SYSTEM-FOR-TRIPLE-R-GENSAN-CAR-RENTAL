<?php
declare(strict_types=1);

use TripleR\Support\Format;
use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$deleted = $driver['deleted_at'] !== null;
$canEdit = $canManage && !$deleted;
$expired = $driver['license_expiry'] < Format::today();
$licenceUnreadable = $pii['license'] === 'Unreadable';

View::begin('staff', ['title' => (string) $driver['full_name'], 'crumbs' => [['Fleet', null], ['Drivers', '/fleet/drivers'], [(string) $driver['full_name'], null]], 'scripts' => ['drivers.js']]);
?>
<div data-driver-reveal-url="/fleet/drivers/reveal" data-csrf="<?= $e($csrfToken) ?>" data-driver-id="<?= (int) $driver['driver_id'] ?>">
<header class="page-header">
    <div class="page-header-text">
        <p class="eyebrow">Driver</p>
        <h1><?= $e($driver['full_name']) ?></h1>
        <div class="page-meta">
            <?= Status::badge('driver', $driver['status']) ?>
<?php if ($expired): ?>
            <span class="badge badge-danger">Licence expired</span>
<?php endif; ?>
            <span>Licence valid to <?= $e(Format::date($driver['license_expiry'])) ?></span>
<?php if ($deleted): ?>
            <span>Removed <?= $e(Format::datetime($driver['deleted_at'])) ?></span>
<?php endif; ?>
        </div>
    </div>
<?php if ($canEdit): ?>
    <div class="page-header-actions">
        <a class="button button-primary" href="/fleet/drivers/edit?driver_id=<?= (int) $driver['driver_id'] ?>">Edit driver</a>
    </div>
<?php endif; ?>
</header>
</div>
<?php if ($notice): ?>
<p class="notice" role="status"><?= $e($notice) ?></p>
<?php endif; ?>
<?php if ($licenceUnreadable): ?>
<p class="callout" role="status">The saved licence number can’t be read with the current encryption key.<?php if ($canEdit): ?> <a href="/fleet/drivers/edit?driver_id=<?= (int) $driver['driver_id'] ?>">Edit this driver</a> and enter the licence number again.<?php endif; ?></p>
<?php endif; ?>

<div class="split">
    <div class="split-main">
        <section class="panel" aria-labelledby="info-title">
            <div class="panel-heading"><div><h2 id="info-title">Personal details</h2><p><?= $canReveal ? 'Stored encrypted and hidden until you choose Reveal.' : 'These details are restricted for your role.' ?></p></div></div>
            <div class="panel-body">
                <dl class="facts">
                    <div><dt>Licence number</dt><dd><?php if ($licenceUnreadable): ?><span class="badge badge-warning">Unreadable</span><?php else: ?><span data-pii-value><?= $e($pii['license']) ?></span><?php if ($canReveal): ?> <button type="button" class="button button-secondary button-small" data-reveal-kind="license">Reveal</button><?php endif; ?><?php endif; ?></dd></div>
                    <div><dt>Address</dt><dd><span data-pii-value><?= $e($pii['address']) ?></span><?php if ($canReveal && $driver['address_ciphertext'] !== null): ?> <button type="button" class="button button-secondary button-small" data-reveal-kind="address">Reveal</button><?php endif; ?></dd></div>
                    <div><dt>Emergency contact name</dt><dd><span data-pii-value><?= $e($pii['emergency_name']) ?></span><?php if ($canReveal && $driver['emergency_contact_name_ciphertext'] !== null): ?> <button type="button" class="button button-secondary button-small" data-reveal-kind="emergency_name">Reveal</button><?php endif; ?></dd></div>
                    <div><dt>Emergency contact phone</dt><dd><span data-pii-value><?= $e($pii['emergency_phone']) ?></span><?php if ($canReveal && $driver['emergency_contact_phone_ciphertext'] !== null): ?> <button type="button" class="button button-secondary button-small" data-reveal-kind="emergency_phone">Reveal</button><?php endif; ?></dd></div>
                </dl>
<?php if (!empty($driver['notes'])): ?>
                <div><h3>Staff notes</h3><p class="timeline-note"><?= nl2br($e($driver['notes'])) ?></p></div>
<?php endif; ?>
            </div>
        </section>

        <section class="panel" aria-labelledby="contacts-title">
            <div class="panel-heading"><h2 id="contacts-title">Phone and email</h2></div>
<?php if ($canEdit): ?>
            <form class="toolbar" method="post" action="/fleet/drivers/contacts/add">
                <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                <input type="hidden" name="driver_id" value="<?= (int) $driver['driver_id'] ?>">
                <label class="field"><span class="field-label">Type</span><select name="contact_type"><option value="phone">Phone</option><option value="email">Email</option></select></label>
                <label class="field"><span class="field-label">Number or address</span><input name="contact_value" required maxlength="254"></label>
                <label class="check-field"><input type="checkbox" name="is_primary" value="1"> Make primary</label>
                <button class="button button-secondary" type="submit">Add contact</button>
            </form>
<?php endif; ?>
            <div class="table-wrap">
                <table class="data-table" data-stack>
                    <thead><tr><th scope="col">Type</th><th scope="col">Value</th><th scope="col">Primary</th><th scope="col" class="actions">Actions</th></tr></thead>
                    <tbody>
<?php foreach ($contacts as $contact): ?>
                        <tr>
                            <td><?= $e(Status::label($contact['contact_type'])) ?></td>
                            <td><span data-pii-value><?= $e($contact['display']) ?></span><?php if ($contact['can_reveal']): ?> <button type="button" class="button button-secondary button-small" data-reveal-kind="contact" data-record-id="<?= (int) $contact['contact_id'] ?>">Reveal</button><?php endif; ?></td>
                            <td><?= (int) $contact['is_primary'] === 1 ? '<span class="badge badge-info">Primary</span>' : '—' ?></td>
                            <td class="actions">
<?php if ($canEdit): ?>
                                <div class="cell-actions">
                                    <details class="disclosure">
                                        <summary>Edit</summary>
                                        <form class="disclosure-body" method="post" action="/fleet/drivers/contacts/update">
                                            <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="driver_id" value="<?= (int) $driver['driver_id'] ?>">
                                            <input type="hidden" name="contact_id" value="<?= (int) $contact['contact_id'] ?>">
                                            <label class="field"><span class="field-label">Type</span><select name="contact_type"><option value="phone"<?= $contact['contact_type'] === 'phone' ? ' selected' : '' ?>>Phone</option><option value="email"<?= $contact['contact_type'] === 'email' ? ' selected' : '' ?>>Email</option></select></label>
                                            <label class="field"><span class="field-label">New value</span><input name="contact_value" required maxlength="254"></label>
                                            <label class="check-field"><input type="checkbox" name="is_primary" value="1"<?= $contact['is_primary'] ? ' checked' : '' ?>> Primary</label>
                                            <div><button class="button button-primary button-small" type="submit">Save</button></div>
                                        </form>
                                    </details>
                                    <form method="post" action="/fleet/drivers/contacts/remove" data-confirm="Remove this contact from the driver’s record?" data-confirm-action="Remove contact">
                                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                                        <input type="hidden" name="driver_id" value="<?= (int) $driver['driver_id'] ?>">
                                        <input type="hidden" name="contact_id" value="<?= (int) $contact['contact_id'] ?>">
                                        <button class="button button-danger button-small" type="submit">Remove</button>
                                    </form>
                                </div>
<?php endif; ?>
                            </td>
                        </tr>
<?php endforeach; ?>
<?php if (!$contacts): ?>
                        <tr><td class="empty-state" colspan="4">No phone or email recorded.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel" aria-labelledby="assignments-title">
            <div class="panel-heading"><h2 id="assignments-title">Assignments</h2></div>
            <div class="table-wrap">
                <table class="data-table" data-stack>
                    <thead><tr><th scope="col">Agreement</th><th scope="col">Status</th><th scope="col">Start</th><th scope="col">End</th></tr></thead>
                    <tbody>
<?php foreach ($assignments as $assignment): ?>
                        <tr>
                            <td class="cell-strong">#<?= (int) $assignment['agreement_id'] ?></td>
                            <td><?= Status::badge('rental', $assignment['status']) ?></td>
                            <td class="nowrap"><?= $e(Format::date($assignment['start_date'])) ?></td>
                            <td class="nowrap"><?= $e(Format::date($assignment['end_date'])) ?></td>
                        </tr>
<?php endforeach; ?>
<?php if (!$assignments): ?>
                        <tr><td class="empty-state" colspan="4">No assignments yet.</td></tr>
<?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <aside class="split-side" aria-label="Status and record">
        <section class="panel" aria-labelledby="photo-title">
            <div class="panel-heading"><div><h2 id="photo-title">Photo</h2><p>JPEG, PNG or WebP, up to 8 MB.</p></div></div>
            <div class="panel-body profile-photo">
                <?= View::avatar($hasPhoto ? '/fleet/drivers/photo?driver_id=' . (int) $driver['driver_id'] : null, (string) $driver['full_name'], 'avatar avatar--large') ?>

<?php if ($canEdit): ?>
                <div class="profile-photo-actions">
                    <form method="post" action="/fleet/drivers/photo/upload" enctype="multipart/form-data" class="cell-actions cell-actions--start">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="driver_id" value="<?= (int) $driver['driver_id'] ?>">
                        <label class="button button-secondary"><?= $hasPhoto ? 'Replace photo' : 'Choose a photo' ?><input class="visually-hidden" type="file" name="photo" accept="image/jpeg,image/png,image/webp" required data-auto-submit></label>
                        <button class="button button-secondary" type="button" data-take-photo hidden>Take photo</button>
                        <noscript><button class="button button-primary" type="submit">Upload</button></noscript>
                    </form>
<?php if ($hasPhoto): ?>
                    <form method="post" action="/fleet/drivers/photo/remove" data-confirm="Remove the photo of <?= $e($driver['full_name']) ?>?" data-confirm-action="Remove photo">
                        <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                        <input type="hidden" name="driver_id" value="<?= (int) $driver['driver_id'] ?>">
                        <button class="button button-danger-quiet button-small" type="submit">Remove photo</button>
                    </form>
<?php endif; ?>
                </div>
<?php elseif (!$hasPhoto): ?>
                <p class="muted">No photo on file.</p>
<?php endif; ?>
            </div>
        </section>
<?php if ($canAccount && !$deleted): ?>
        <section class="panel" aria-labelledby="account-title">
            <div class="panel-heading"><div><h2 id="account-title">Sign-in account</h2><p>Lets the driver sign in to see their own trips and share their location on them.</p></div></div>
            <div class="panel-body">
<?php if ($account === null): ?>
                <p class="muted">This driver has no account yet.</p>
<?php if ($driver['status'] === 'active'): ?>
                <a class="button button-secondary" href="/admin/users?driver_id=<?= (int) $driver['driver_id'] ?>#create-user-title">Create sign-in</a>
<?php else: ?>
                <p class="muted">Make the driver active to create one.</p>
<?php endif; ?>
<?php else: $accountOn = $account['is_active'] && $account['deleted_at'] === null; ?>
                <p><span class="mono"><?= $e($account['email']) ?></span> <span class="badge <?= $accountOn ? 'badge-success' : 'badge-neutral' ?>"><?= $accountOn ? 'Active' : 'Deactivated' ?></span></p>
                <a class="button button-secondary" href="/admin/users<?= $accountOn ? '' : '?show=deactivated' ?>"><?= $accountOn ? 'Manage in Staff accounts' : 'Reactivate in Staff accounts' ?></a>
<?php endif; ?>
            </div>
        </section>
<?php endif; ?>
<?php if ($canEdit): ?>
        <section class="panel">
            <div class="panel-heading"><h2>Availability</h2></div>
            <div class="panel-body">
                <form method="post" action="/fleet/drivers/status" class="stack">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="driver_id" value="<?= (int) $driver['driver_id'] ?>">
                    <label class="field"><span class="field-label">Status</span>
                        <select name="status">
                            <option value="active"<?= $driver['status'] === 'active' ? ' selected' : '' ?>>Active</option>
                            <option value="inactive"<?= $driver['status'] === 'inactive' ? ' selected' : '' ?>>Inactive</option>
                        </select>
                        <small class="field-hint">Inactive drivers can’t be given new assignments.</small>
                    </label>
                    <button class="button button-secondary" type="submit">Save status</button>
                </form>
            </div>
        </section>
<?php endif; ?>
        <section class="panel">
            <div class="panel-heading"><h2>Status history</h2></div>
            <div class="panel-body">
<?php if (!$statusHistory): ?>
                <p class="muted">No changes recorded.</p>
<?php else: ?>
                <ol class="timeline">
<?php foreach ($statusHistory as $entry): ?>
                    <li>
                        <div class="timeline-title"><?= $e($entry['old_status'] === null ? 'Added' : Status::label($entry['old_status'])) ?> → <?= $e(Status::label($entry['new_status'])) ?></div>
                        <div class="timeline-meta"><?= $e(Format::datetime($entry['created_at'])) ?> · <?= $e($entry['actor_email']) ?></div>
                    </li>
<?php endforeach; ?>
                </ol>
<?php endif; ?>
            </div>
        </section>
<?php if ($canEdit): ?>
        <section class="panel">
            <div class="panel-heading"><div><h2>Remove driver</h2><p>The record is kept for history but leaves every list. A driver with an open assignment can’t be removed.</p></div></div>
            <div class="panel-body">
                <form method="post" action="/fleet/drivers/delete" class="stack" data-confirm="Remove <?= $e($driver['full_name']) ?> from the driver list? Their past assignments stay on record." data-confirm-action="Remove driver">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="driver_id" value="<?= (int) $driver['driver_id'] ?>">
                    <label class="field"><span class="field-label">Reason <span class="optional">(optional)</span></span><textarea name="reason" maxlength="500" rows="2"></textarea></label>
                    <button class="button button-danger" type="submit">Remove driver</button>
                </form>
            </div>
        </section>
<?php endif; ?>
<?php if ($canManage && $deleted): ?>
        <section class="panel" aria-labelledby="restore-title">
            <div class="panel-heading"><div><h2 id="restore-title">Restore driver</h2><p>Puts this driver back in every list, with their history.</p></div></div>
            <div class="panel-body">
                <form method="post" action="/fleet/drivers/restore" class="stack">
                    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
                    <input type="hidden" name="driver_id" value="<?= (int) $driver['driver_id'] ?>">
                    <label class="field"><span class="field-label">Reason <span class="optional">(optional)</span></span><textarea name="reason" maxlength="500" rows="2"></textarea></label>
                    <button class="button button-primary" type="submit">Restore driver</button>
                </form>
            </div>
        </section>
<?php endif; ?>
<?php if (!empty($lifecycle)): ?>
        <section class="panel" aria-labelledby="lifecycle-title">
            <div class="panel-heading"><div><h2 id="lifecycle-title">Removed and restored</h2></div></div>
            <div class="panel-body">
                <ol class="timeline">
<?php foreach ($lifecycle as $event): ?>
                    <li>
                        <div class="timeline-title"><?= $event['action'] === 'removed' ? 'Removed' : 'Restored' ?></div>
                        <div class="timeline-meta"><?= $e(Format::datetime($event['created_at'])) ?> · <?= $e($event['actor_email']) ?></div>
<?php if ($event['reason'] !== null): ?>
                        <div class="timeline-note"><?= $e($event['reason']) ?></div>
<?php endif; ?>
                    </li>
<?php endforeach; ?>
                </ol>
            </div>
        </section>
<?php endif; ?>
    </aside>
</div>
<?php View::end(); ?>
