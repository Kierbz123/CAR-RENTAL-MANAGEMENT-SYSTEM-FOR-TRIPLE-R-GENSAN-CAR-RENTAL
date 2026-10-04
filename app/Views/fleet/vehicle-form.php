<?php
declare(strict_types=1);

use TripleR\Support\StatusPresenter as Status;
use TripleR\Support\View;

$e = static fn (mixed $value): string => View::e($value);
// $old holds what was typed when a new vehicle could not be saved; strings only.
$v = $vehicle ?? array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $old ?? []);
$editing = $vehicle !== null;
$backHref = $editing ? '/fleet/vehicles/detail?vehicle_id=' . (int) $v['vehicle_id'] : '/fleet/vehicles';
$crumbs = [['Fleet', null], ['Vehicles', '/fleet/vehicles']];
if ($editing) {
    $crumbs[] = [(string) $v['plate_number'], $backHref];
}
$crumbs[] = [$editing ? 'Edit' : 'Register', null];
$select = static function (string $name, array $choices, mixed $current) use ($e): string {
    $html = '<select name="' . $name . '" required>';
    foreach ($choices as $choice) {
        $html .= '<option value="' . $e($choice) . '"' . ($current === $choice ? ' selected' : '') . '>' . $e(Status::label($choice)) . '</option>';
    }
    return $html . '</select>';
};

View::begin('staff', ['title' => $editing ? 'Edit vehicle' : 'Register vehicle', 'crumbs' => $crumbs, 'scripts' => ['vehicles.js']]);
?>
<header class="page-header">
    <div class="page-header-text">
        <h1><?= $editing ? 'Edit vehicle' : 'Register a vehicle' ?></h1>
        <p class="page-lead">Fields marked optional can be filled in later, once the documents arrive.</p>
    </div>
</header>
<?php if ($error): ?>
<p class="alert" role="alert"><?= $e($error) ?></p>
<?php endif; ?>
<form class="panel" method="post" action="<?= $editing ? '/fleet/vehicles/update' : '/fleet/vehicles/create' ?>" data-vehicle-form>
    <input type="hidden" name="_csrf" value="<?= $e($csrfToken) ?>">
<?php if ($editing): ?>
    <input type="hidden" name="vehicle_id" value="<?= (int) $v['vehicle_id'] ?>">
<?php endif; ?>
    <div class="panel-body form-section">
        <div class="form-section-heading"><h2>Identity</h2><p>How staff recognise this vehicle.</p></div>
        <div class="form-grid">
            <label class="field"><span class="field-label">Plate number</span><input name="plate_number" maxlength="20" value="<?= $e($v['plate_number'] ?? '') ?>" required autocomplete="off"></label>
            <label class="field"><span class="field-label">Color</span><input name="color" maxlength="40" value="<?= $e($v['color'] ?? '') ?>" required></label>
            <label class="field"><span class="field-label">Make</span><input name="make" maxlength="60" value="<?= $e($v['make'] ?? '') ?>" required placeholder="e.g. Toyota"></label>
            <label class="field"><span class="field-label">Model</span><input name="model" maxlength="80" value="<?= $e($v['model'] ?? '') ?>" required placeholder="e.g. Vios"></label>
            <label class="field"><span class="field-label">Model year</span><input name="model_year" type="number" min="1" maxlength="5" value="<?= $e($v['model_year'] ?? '') ?>" required></label>
            <label class="field"><span class="field-label">Seats</span><input name="seating_capacity" type="number" min="1" maxlength="3" value="<?= $e($v['seating_capacity'] ?? '') ?>" required></label>
            <label class="field"><span class="field-label">Body type</span><?= $select('body_type', ['sedan', 'SUV', 'van', 'pickup', 'hatchback'], $v['body_type'] ?? '') ?></label>
            <label class="field"><span class="field-label">Transmission</span><?= $select('transmission', ['manual', 'automatic'], $v['transmission'] ?? '') ?></label>
            <label class="field"><span class="field-label">Fuel</span><?= $select('fuel_type', ['gasoline', 'diesel', 'hybrid'], $v['fuel_type'] ?? '') ?></label>
            <label class="field"><span class="field-label">Current mileage (km)</span><input name="current_mileage" type="number" min="0" step="1" value="<?= $e($v['current_mileage'] ?? 0) ?>" required<?= $editing ? ' readonly' : '' ?>><?php if ($editing): ?><small class="field-hint">Add a new odometer reading on the vehicle page to change this.</small><?php endif; ?></label>
        </div>
    </div>
    <div class="panel-body form-section">
        <div class="form-section-heading"><h2>Rates</h2><p>Per day, in pesos. Weekly and monthly rates are not stored.</p></div>
        <div class="form-grid">
            <label class="field"><span class="field-label">Self-drive daily rate (₱)</span><input name="daily_rate" type="number" min="0" step="0.01" maxlength="10" value="<?= $e($v['daily_rate'] ?? '') ?>" required></label>
            <label class="field"><span class="field-label">Chauffeur daily rate (₱) <span class="optional">(optional)</span></span><input name="chauffeur_daily_rate" type="number" min="0" step="0.01" maxlength="10" value="<?= $e($v['chauffeur_daily_rate'] ?? '') ?>"><small class="field-hint">Leave blank if this vehicle isn’t offered with a driver.</small></label>
        </div>
    </div>
    <div class="panel-body form-section">
        <div class="form-section-heading"><h2>Documents</h2><p>All optional. Engine and chassis numbers can wait until the papers are in.</p></div>
        <div class="form-grid">
            <label class="field"><span class="field-label">Engine number</span><input name="engine_number" maxlength="80" value="<?= $e($v['engine_number'] ?? '') ?>" autocomplete="off"></label>
            <label class="field"><span class="field-label">Chassis number</span><input name="chassis_number" maxlength="80" value="<?= $e($v['chassis_number'] ?? '') ?>" autocomplete="off"></label>
            <label class="field"><span class="field-label">Registration expiry</span><input type="date" name="registration_expiry" value="<?= $e($v['registration_expiry'] ?? '') ?>"></label>
            <label class="field"><span class="field-label">Insurance expiry</span><input type="date" name="insurance_expiry" value="<?= $e($v['insurance_expiry'] ?? '') ?>"></label>
            <label class="field field--wide"><span class="field-label">Insurance provider</span><input name="insurance_provider" maxlength="80" value="<?= $e($v['insurance_provider'] ?? '') ?>"></label>
            <label class="field field--wide"><span class="field-label">Notes</span><textarea name="notes" rows="4"><?= $e($v['notes'] ?? '') ?></textarea></label>
        </div>
    </div>
    <div class="form-actions">
        <button class="button button-primary" type="submit"><?= $editing ? 'Save changes' : 'Register vehicle' ?></button>
        <a class="button button-ghost" href="<?= $e($backHref) ?>">Cancel</a>
    </div>
</form>
<?php View::end(); ?>
