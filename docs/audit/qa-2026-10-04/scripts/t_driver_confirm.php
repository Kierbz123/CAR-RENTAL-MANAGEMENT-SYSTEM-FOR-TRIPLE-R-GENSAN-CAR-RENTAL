<?php require __DIR__.'/svc.php';
$rows=$db->query("SELECT driver_id, GROUP_CONCAT(agreement_id) ids FROM rental_agreements WHERE driver_id IS NOT NULL AND status='reserved' AND start_date='2027-05-10' GROUP BY driver_id HAVING COUNT(*)=2 ORDER BY driver_id DESC LIMIT 1")->fetch();
[$a,$b]=array_map('intval',explode(',',$rows['ids']));
foreach([$a,$b] as $i=>$id){ $rentals->recordDownpayment($id,'GC'.random_int(10000000,99999999),$ADMIN,'gcash'); }
$r1=attempt(fn()=>$rentals->transition($a,'confirm',$ADMIN)); $r2=attempt(fn()=>$rentals->transition($b,'confirm',$ADMIN));
check('sequential confirm catches the double-assigned driver on the second agreement', str_starts_with($r1,'OK') && str_starts_with($r2,'ERR'), "first=$r1 second=$r2");
