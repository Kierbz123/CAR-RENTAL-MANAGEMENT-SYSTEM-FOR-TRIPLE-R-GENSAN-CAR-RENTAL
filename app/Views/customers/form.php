<?php
declare(strict_types=1);

use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
// $old holds what was typed when a new customer could not be saved; strings only.
$old = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $old ?? []);
$c = $customer ?? $old;
$editing = $customer !== null;
$backHref = $editing ? '/customers/detail?customer_id=' . (int) $c['customer_id'] : '/customers';
$crumbs = [['Customers', '/customers']];
if ($editing) {
    $crumbs[] = [(string) $c['full_name'], $backHref];
}
$crumbs[] = [$editing ? 'Edit' : 'Add', null];

View::begin('staff', ['title' => $editing ? 'Edit customer' : 'Add customer', 'crumbs' => $crumbs, 'scripts' => ['customers.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1><?= $editing ? 'Edit customer' : 'Add a customer' ?></h1>
        <p class="page-lead">A customer record lets you make reservations for this person. It does not create a login.</p>
    </div>
</header>
<?php if ($error): ?>
<p class="alert" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form class="panel" method="post" action="<?= $editing ? '/customers/update' : '/customers/create' ?>" data-customer-form>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<?php if ($editing): ?>
    <input type="hidden" name="customer_id" value="<?= (int) $c['customer_id'] ?>">
<?php endif; ?>
    <div class="panel-body form-section">
        <div class="form-section-heading"><h2>Customer</h2></div>
        <div class="form-grid">
            <label class="field"><span class="field-label">Full name</span><input name="full_name" maxlength="160" value="<?= $e($c['full_name'] ?? '') ?>" required></label>
            <label class="field"><span class="field-label">How they came to us</span>
                <select name="customer_type" required>
<?php foreach ($types as $t): ?>
                    <option value="<?= $e($t) ?>"<?= ($c['customer_type'] ?? 'walk_in') === $t ? ' selected' : '' ?>><?= $e(Status::label($t)) ?></option>
<?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span class="field-label">Company name</span><input name="company_name" maxlength="160" value="<?= $e($c['company_name'] ?? '') ?>"><small class="field-hint">Required for corporate customers.</small></label>
            <label class="field"><span class="field-label">Referred by</span><input name="referral_source" maxlength="160" value="<?= $e($c['referral_source'] ?? '') ?>"><small class="field-hint">Required for referral customers.</small></label>
        </div>
    </div>
<?php if (!$editing): ?>
    <div class="panel-body form-section">
        <div class="form-section-heading"><h2>Contact</h2><p>Optional now, encrypted when saved. A phone number is needed to send the booking link by SMS.</p></div>
        <div class="form-grid">
            <label class="field"><span class="field-label">Phone</span><input name="phone" type="tel" maxlength="40" autocomplete="off" value="<?= $e($old['phone'] ?? '') ?>"></label>
            <label class="field"><span class="field-label">Email</span><input name="email" type="email" maxlength="254" autocomplete="off" value="<?= $e($old['email'] ?? '') ?>"></label>
        </div>
    </div>
    <div class="panel-body form-section">
        <div class="form-section-heading"><h2>Identity document</h2><p>Optional now, encrypted when saved. More can be added on the customer’s page.</p></div>
        <div class="form-grid form-grid--three">
            <label class="field"><span class="field-label">Document type</span>
                <select name="document_type">
                    <option value="">None yet</option>
<?php foreach ($documentTypes as $docType): ?>
                    <option value="<?= $e($docType) ?>"<?= ($old['document_type'] ?? '') === $docType ? ' selected' : '' ?>><?= $e(Status::label($docType)) ?></option>
<?php endforeach; ?>
                </select>
            </label>
            <label class="field"><span class="field-label">Document number</span><input name="document_number" maxlength="100" autocomplete="off" value="<?= $e($old['document_number'] ?? '') ?>"></label>
            <label class="field"><span class="field-label">Expiry date</span><input type="date" name="expires_on" value="<?= $e($old['expires_on'] ?? '') ?>"></label>
        </div>
    </div>
<?php endif; ?>
    <div class="form-actions">
        <button class="button button-primary" type="submit"><?= $editing ? 'Save customer' : 'Create customer' ?></button>
        <a class="button button-ghost" href="<?= $e($backHref) ?>">Cancel</a>
    </div>
</form>
<?php View::end(); ?>
