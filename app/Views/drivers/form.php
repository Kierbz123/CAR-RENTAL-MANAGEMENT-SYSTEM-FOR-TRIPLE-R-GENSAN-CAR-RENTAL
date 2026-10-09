<?php
declare(strict_types=1);

use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
$editing = $driver !== null;
$d = $driver ?? [];
$backHref = $editing ? '/fleet/drivers/detail?driver_id=' . (int) $d['driver_id'] : '/fleet/drivers';
$crumbs = [['Fleet', null], ['Drivers', '/fleet/drivers']];
if ($editing) {
    $crumbs[] = [(string) $d['full_name'], $backHref];
}
$crumbs[] = [$editing ? 'Edit' : 'Add', null];
$hasAddress = $editing && $d['address_ciphertext'] !== null;
$hasEmergency = $editing && $d['emergency_contact_name_ciphertext'] !== null;

View::begin('staff', ['title' => $editing ? 'Edit driver' : 'Add driver', 'crumbs' => $crumbs, 'scripts' => ['drivers.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1><?= $editing ? 'Edit driver' : 'Add a driver' ?></h1>
        <p class="page-lead">Licence, contact, address and emergency details are encrypted when saved.</p>
    </div>
</header>
<?php if ($error): ?>
<p class="alert" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form class="panel" method="post" action="<?= $editing ? '/fleet/drivers/update' : '/fleet/drivers/create' ?>" data-driver-form>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<?php if ($editing): ?>
    <input type="hidden" name="driver_id" value="<?= (int) $d['driver_id'] ?>">
<?php endif; ?>
    <div class="panel-body form-section">
        <div class="form-section-heading"><h2>Driver and licence</h2></div>
        <div class="form-grid">
            <label class="field field--wide"><span class="field-label">Full name</span><input name="full_name" maxlength="160" value="<?= $e($d['full_name'] ?? '') ?>" required></label>
            <label class="field"><span class="field-label">Licence number</span><input name="license_number" maxlength="100" minlength="5" <?= $editing ? '' : 'required ' ?>autocomplete="off"><?php if ($editing): ?><small class="field-hint">Leave blank to keep the number already on file.</small><?php endif; ?></label>
            <label class="field"><span class="field-label">Licence expiry</span><input type="date" name="license_expiry" value="<?= $e($d['license_expiry'] ?? '') ?>" min="2000-01-01" required><small class="field-hint">A driver whose licence has expired can be saved, but not given a booking.</small></label>
        </div>
    </div>
<?php if (!$editing): ?>
    <div class="panel-body form-section">
        <div class="form-section-heading"><h2>Contact</h2><p>Optional now. More can be added on the driver’s page.</p></div>
        <div class="form-grid">
            <label class="field"><span class="field-label">Phone</span><input type="tel" name="phone" maxlength="40" autocomplete="off"></label>
            <label class="field"><span class="field-label">Email</span><input type="email" name="email" maxlength="254" autocomplete="off"></label>
        </div>
    </div>
<?php endif; ?>
    <div class="panel-body form-section">
        <div class="form-section-heading"><h2>Address and emergency contact</h2><p>Optional.</p></div>
        <div class="form-grid">
            <label class="field field--wide"><span class="field-label">Address</span><textarea name="address" rows="2" maxlength="1000"></textarea><?php if ($hasAddress): ?><small class="field-hint">Leave blank to keep the address already on file.</small><?php endif; ?></label>
<?php if ($hasAddress): ?>
            <label class="check-field field--wide"><input type="checkbox" name="clear_address" value="1"> Remove the saved address</label>
<?php endif; ?>
            <label class="field"><span class="field-label">Emergency contact name</span><input name="emergency_contact_name" maxlength="160"><?php if ($hasEmergency): ?><small class="field-hint">Leave both emergency fields blank to keep the details on file.</small><?php endif; ?></label>
            <label class="field"><span class="field-label">Emergency contact phone</span><input type="tel" name="emergency_contact_phone" maxlength="40"></label>
<?php if ($hasEmergency): ?>
            <label class="check-field field--wide"><input type="checkbox" name="clear_emergency_contact" value="1"> Remove the saved emergency contact</label>
<?php endif; ?>
        </div>
    </div>
    <div class="panel-body form-section">
        <div class="form-section-heading"><h2>Staff notes</h2><p>Notes are not encrypted and can be searched. Don’t put licence or contact details here.</p></div>
        <label class="field"><span class="field-label visually-hidden">Staff notes</span><textarea name="notes" rows="3" maxlength="5000"><?= $e($d['notes'] ?? '') ?></textarea></label>
    </div>
    <div class="form-actions">
        <button class="button button-primary" type="submit"><?= $editing ? 'Save driver' : 'Create driver' ?></button>
        <a class="button button-ghost" href="<?= $e($backHref) ?>">Cancel</a>
    </div>
</form>
<?php View::end(); ?>
