<?php require __DIR__.'/svc.php';
$v=newVehicle($ADMIN); $c=newCustomer($ADMIN); $N=12; $start=microtime(true)+1.0;
$pids=[];
for($i=0;$i<$N;$i++){ $pid=pcntl_fork(); if($pid===0){
  // child: fresh connection
  $r=new ReflectionProperty(\TripleR\Database::class,'connection'); $r->setValue(null,null);
  $svc=\TripleR\Services\RentalRuntimeFactory::service(\TripleR\Database::connection());
  while(microtime(true)<$start) usleep(100);
  try{$id=$svc->create(['customer_id'=>$c,'vehicle_id'=>$v,'start_date'=>'2027-03-01','end_date'=>'2027-03-04','scheduled_pickup_at'=>'2027-03-01T10:00','scheduled_return_at'=>'2027-03-04T10:00','deposit_amount'=>'0'],$ADMIN);exit(0);}catch(\Throwable $e){exit(1);} }
  $pids[]=$pid; }
$ok=0; foreach($pids as $p){pcntl_waitpid($p,$s); if(pcntl_wexitstatus($s)===0)$ok++;}
$rp=new ReflectionProperty(\TripleR\Database::class,"connection");$rp->setValue(null,null);$db=\TripleR\Database::connection();
$rows=(int)$db->query("SELECT COUNT(*) FROM rental_agreements WHERE vehicle_id=$v AND status='reserved'")->fetchColumn();
check("$N concurrent bookings of one vehicle/dates -> exactly 1 succeeds", $ok===1 && $rows===1, "succeeded=$ok rows=$rows");
