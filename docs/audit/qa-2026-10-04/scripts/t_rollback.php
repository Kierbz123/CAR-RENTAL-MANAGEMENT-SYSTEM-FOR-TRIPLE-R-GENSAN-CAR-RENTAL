<?php
/* Before running, create the injection trigger in the THROWAWAY DB (mysql CLI):
 * DELIMITER $$
 * CREATE TRIGGER qa_inject_fail BEFORE INSERT ON status_logs FOR EACH ROW BEGIN IF NEW.subject='deposit' AND NEW.reason='Initial deposit state' AND @qa_inject=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='QA injected failure'; END IF; END$$
 * DELIMITER ;
 * Afterwards: DROP TRIGGER qa_inject_fail;
 */
 require __DIR__.'/svc.php';
$v=newVehicle($ADMIN); $c=newCustomer($ADMIN);
$before=(int)$db->query("SELECT COUNT(*) FROM rental_agreements WHERE vehicle_id=$v")->fetchColumn();
$db->exec('SET @qa_inject=1');
$r=attempt(fn()=>$rentals->create(['customer_id'=>$c,'vehicle_id'=>$v,'start_date'=>'2027-07-01','end_date'=>'2027-07-03','scheduled_pickup_at'=>'2027-07-01T10:00','scheduled_return_at'=>'2027-07-03T10:00','deposit_amount'=>'500'],$ADMIN));
$db->exec('SET @qa_inject=0');
$after=(int)$db->query("SELECT COUNT(*) FROM rental_agreements WHERE vehicle_id=$v")->fetchColumn();
$logs=(int)$db->query("SELECT COUNT(*) FROM status_logs s JOIN rental_agreements r USING(agreement_id) WHERE r.vehicle_id=$v")->fetchColumn();
check('DB error on last write rolls back the agreement and its status log', str_starts_with($r,'ERR') && $after===$before && $logs===0, "result=".substr($r,0,60)." agreements_after=$after logs=$logs inTx=".var_export($db->inTransaction(),true));
$r=attempt(fn()=>$rentals->create(['customer_id'=>$c,'vehicle_id'=>$v,'start_date'=>'2027-07-01','end_date'=>'2027-07-03','scheduled_pickup_at'=>'2027-07-01T10:00','scheduled_return_at'=>'2027-07-03T10:00','deposit_amount'=>'500'],$ADMIN));
check('vehicle bookable again after the rolled-back attempt (no phantom hold)', str_starts_with($r,'OK'),$r);
