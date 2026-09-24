<?php
declare(strict_types=1);
$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Vehicle fleet | Triple R Gensan</title><link rel="stylesheet" href="/assets/css/app.css"><script src="/assets/js/vehicles.js" defer></script></head><body>
<header class="topbar"><a class="brand" href="/staff">Triple R Gensan</a><nav class="staff-actions"><a href="/fleet/vehicles">Fleet</a><a href="/fleet/locations">Locations</a><a href="/staff/notifications">Notifications</a></nav></header>
<main class="page-shell"><section class="page-heading"><div><p class="eyebrow">Fleet management</p><h1>Vehicles</h1><p>Signed in as <?= $e($user['email']) ?> (<?= $e($user['role']) ?>)</p></div><a class="button-link" href="/fleet/vehicles/new">Register vehicle</a></section>
<form class="panel filter-form" method="get" action="/fleet/vehicles"><label for="status">Status</label><select id="status" name="status"><option value="">All</option><?php foreach($statuses as $s):?><option value="<?= $e($s) ?>"<?= $status===$s?' selected':'' ?>><?= $e(str_replace('_',' ',$s)) ?></option><?php endforeach;?></select><button type="submit">Filter</button></form>
<section class="panel"><div class="table-wrap"><table><thead><tr><th>Plate</th><th>Vehicle</th><th>Status</th><th>Daily rate</th><th>Mileage (km)</th><th>Location</th><th></th></tr></thead><tbody>
<?php foreach($vehicles as $v):?><tr><td><?= $e($v['plate_number']) ?></td><td><?= $e($v['model_year'].' '.$v['make'].' '.$v['model']) ?></td><td><?= $e($v['current_status']) ?></td><td>₱<?= $e($v['daily_rate']) ?></td><td><?= $e($v['current_mileage']) ?></td><td><?= $e($v['location_name']??'—') ?></td><td><a href="/fleet/vehicles/detail?vehicle_id=<?= (int)$v['vehicle_id'] ?>">Details</a></td></tr><?php endforeach;?>
<?php if(!$vehicles):?><tr><td colspan="7">No vehicles match this filter.</td></tr><?php endif;?></tbody></table></div></section></main></body></html>
