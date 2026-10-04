<?php require __DIR__.'/svc.php';
$v=newVehicle($ADMIN); $c=newCustomer($ADMIN);
$mk=fn($s,$e,$pt='10:00',$rt='10:00')=>attempt(fn()=>$rentals->create(['customer_id'=>$c,'vehicle_id'=>$v,'start_date'=>$s,'end_date'=>$e,'scheduled_pickup_at'=>$s.'T'.$pt,'scheduled_return_at'=>$e.'T'.$rt,'deposit_amount'=>'0'],$ADMIN));
check('A 2026-12-01..03 created', str_starts_with($mk('2026-12-01','2026-12-03'),'OK'));
check('half-open: B starting on A end date (12-03..05) is allowed', str_starts_with($r=$mk('2026-12-03','2026-12-05'),'OK'),$r);
check('overlap: C 12-02..04 refused', str_starts_with($r=$mk('2026-12-02','2026-12-04'),'ERR'),$r);
check('same-day D 12-05..05 vs B(ends 12-05) allowed', str_starts_with($r=$mk('2026-12-05','2026-12-05'),'OK'),$r);
check('same-day E 12-05..05 again refused', str_starts_with($r=$mk('2026-12-05','2026-12-05'),'ERR'),$r);
// Hand-off day with clock overlap: A2 returns 12-10 20:00, B2 picks up 12-10 08:00
$mk('2026-12-08','2026-12-10','09:00','20:00');
$r=$mk('2026-12-10','2026-12-12','08:00','09:00');
check('time overlap on hand-off day (return 20:00, next pickup 08:00) is refused', str_starts_with($r,'ERR'),$r);
// past dates on staff path
$r=$mk('2025-01-01','2025-01-03'); check('staff cannot create a rental entirely in the past', str_starts_with($r,'ERR'),$r);
// end before start / bad formats
check('end before start refused', str_starts_with($mk('2026-12-20','2026-12-19'),'ERR'));
check('invalid date 2026-02-30 refused', str_starts_with($mk('2026-02-30','2026-03-02'),'ERR'));
// extreme length
$r=$mk('2027-01-01','2099-12-31'); check('staff rental of 73 years refused (no max length)', str_starts_with($r,'ERR'),$r);
if(str_starts_with($r,'OK')){$id=(int)substr($r,3);$row=$db->query("SELECT rental_days,base_amount,downpayment_amount FROM rental_agreements WHERE agreement_id=$id")->fetch();echo "   -> stored: ".json_encode($row)."\n";}
