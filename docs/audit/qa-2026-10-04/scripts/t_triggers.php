<?php
// Attempts UPDATE and DELETE on one row of each append-only table, as root (no privilege barrier), inside a rolled-back transaction.
$root=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tables=['inbound_sms_events'=>'id','security_logs'=>'id','vehicle_mileage_logs'=>'mileage_log_id','customer_notes'=>'note_id','customer_identity_document_audit_logs'=>'audit_id','rental_charges'=>'charge_id','rules_acceptances'=>'acceptance_id','rules_versions'=>'rules_version_id','payment_proofs'=>'proof_id','payments'=>'payment_id','damage_reports'=>'report_id','damage_liability_decisions'=>'decision_id','customer_telegram_links'=>'link_id','status_logs'=>'status_log_id'];
foreach($tables as $t=>$pk){
  $id=$root->query("SELECT $pk FROM $t ".($t==='payment_proofs'?"WHERE proof_status<>'submitted' ":'').($t==='payments'?"WHERE payment_status<>'pending' ":'').($t==='customer_telegram_links'?"WHERE link_status='revoked' ":'')."LIMIT 1")->fetchColumn();
  if($id===false){echo "SKIP $t (no row)\n";continue;}
  foreach(['UPDATE'=>"UPDATE $t SET $pk=$pk WHERE $pk=$id",'DELETE'=>"DELETE FROM $t WHERE $pk=$id"] as $op=>$sql){
    $root->beginTransaction();
    try{$n=$root->exec($sql);echo "FAIL $t $op allowed (rows=$n)\n";}catch(PDOException $e){$m=$e->errorInfo[1].' '.substr($e->errorInfo[2],0,70);echo (str_starts_with($m,'1644')?'PASS':'INFO')." $t $op blocked: $m\n";}
    $root->rollBack();
  }
}
// damage photos
$id=$root->query("SELECT photo_id FROM photos WHERE damage_report_id IS NOT NULL LIMIT 1")->fetchColumn();
foreach(['UPDATE'=>"UPDATE photos SET sort_order=sort_order+1 WHERE photo_id=$id",'DELETE'=>"DELETE FROM photos WHERE photo_id=$id"] as $op=>$sql){$root->beginTransaction();try{$root->exec($sql);echo "FAIL photos(damage) $op allowed\n";}catch(PDOException $e){echo "PASS photos(damage) $op blocked: ".substr($e->errorInfo[2],0,60)."\n";}$root->rollBack();}
// tables that look like history but have no guard
foreach(['telegram_updates','vehicle_positions','notifications','booking_access_tokens','telegram_link_codes','customer_identity_documents'] as $t){$n=$root->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='audit_tr' AND EVENT_OBJECT_TABLE='$t' AND EVENT_MANIPULATION IN ('DELETE')")->fetchColumn();echo "INFO $t delete-guard triggers=$n\n";}
