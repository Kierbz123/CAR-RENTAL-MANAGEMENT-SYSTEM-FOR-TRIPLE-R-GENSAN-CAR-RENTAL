<?php require __DIR__.'/svc.php';
$doc='D'.random_int(10000000,99999999);
$c1=newCustomer($ADMIN,['document_type'=>'passport','document_number'=>$doc,'expires_on'=>'2031-01-01']);
check('customer appears in eligible list', in_array($c1,array_map('intval',array_column($rentals->eligibleCustomers(),'customer_id')),true));
$customers->softDelete($c1);
check('soft-deleted customer gone from eligible list', !in_array($c1,array_map('intval',array_column($rentals->eligibleCustomers(),'customer_id')),true));
check('soft-deleted customer row still exists (not hard-deleted)', (bool)$db->query("SELECT COUNT(*) FROM customers WHERE customer_id=$c1 AND deleted_at IS NOT NULL")->fetchColumn());
$r=attempt(fn()=>newCustomer($ADMIN,['document_type'=>'passport','document_number'=>$doc,'expires_on'=>'2031-01-01']));
check('re-registering same person (same passport) after soft delete is possible', str_starts_with($r,'OK'), $r);
$lic='N01-23-'.random_int(100000,999999);
$d1=newDriver($ADMIN,['license_number'=>$lic]); $drivers->softDelete($d1);
$r=attempt(fn()=>newDriver($ADMIN,['license_number'=>$lic]));
check('re-adding a soft-deleted driver (same license) is possible', str_starts_with($r,'OK'), $r);
check('soft-deleted driver gone from assignment dropdown', !in_array($d1,array_map('intval',array_column($drivers->selectableForAssignment(),'driver_id')),true));
// restore capability
foreach(['CustomerService'=>$customers,'DriverService'=>$drivers,'VehicleService'=>$vehicles] as $n=>$s){ $m=array_filter(get_class_methods($s),fn($x)=>preg_match('/restore|undelete|reactivat/i',$x)); check("$n exposes a restore operation", $m!==[], implode(',',$m)); }
// customer with only historical (completed) rental can be deleted, and the rental list still renders the name
$hist=$db->query("SELECT customer_id FROM rental_agreements WHERE status='completed' LIMIT 1")->fetchColumn();
$r=attempt(fn()=>$customers->softDelete((int)$hist));
check('customer with only completed rentals can be soft-deleted', str_starts_with($r,'OK'),$r);
$rows=$rentalRepo->list(['status'=>'completed']); $names=array_column(array_filter($rows,fn($x)=>(int)$x['customer_id']===(int)$hist),'customer_name');
check('historical rental still shows deleted customer name', $names!==[] && $names[0]!=='', json_encode($names));
// customer with open rental cannot
$open=$db->query("SELECT customer_id FROM rental_agreements WHERE status='active' LIMIT 1")->fetchColumn();
$r=attempt(fn()=>$customers->softDelete((int)$open)); check('customer with active rental cannot be soft-deleted', str_starts_with($r,'ERR'),$r);
