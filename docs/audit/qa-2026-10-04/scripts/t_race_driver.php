<?php require __DIR__.'/svc.php';
$holdLock=($argv[1]??'lock')==='lock';
$d=newDriver($ADMIN); $agreements=[];
foreach([1,2] as $k){ $v=newVehicle($ADMIN); $c=newCustomer($ADMIN);
  $agreements[]=$rentals->create(['customer_id'=>$c,'vehicle_id'=>$v,'rental_type'=>'chauffeur','start_date'=>'2027-05-10','end_date'=>'2027-05-12','scheduled_pickup_at'=>'2027-05-10T09:00','scheduled_return_at'=>'2027-05-12T09:00','deposit_amount'=>'0'],$ADMIN); }
$cfg=fn()=>new PDO('mysql:host=127.0.0.1;dbname=audit_tr','root_unused','',[]);
$blocker=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($holdLock){$blocker->beginTransaction();$blocker->query("SELECT * FROM drivers WHERE driver_id=$d FOR UPDATE")->fetch();}
$pids=[];$start=microtime(true)+0.5;
foreach($agreements as $aid){ $pid=pcntl_fork(); if($pid===0){
  $rp=new ReflectionProperty(\TripleR\Database::class,'connection');$rp->setValue(null,null); $pdo=\TripleR\Database::connection();
  $o=new \TripleR\Services\BookingOverlapService($pdo); $rr=new \TripleR\Repositories\RentalRepository($pdo,$o);
  $cs=new \TripleR\Services\ChauffeurService($pdo,$rr,new \TripleR\Repositories\ChargeRepository($pdo),new \TripleR\Repositories\VehicleRepository($pdo),$o);
  while(microtime(true)<$start) usleep(100);
  try{$cs->assignDriver($aid,$d,$ADMIN);exit(0);}catch(\Throwable $e){file_put_contents('php://stderr',"  child $aid: ".$e->getMessage()."\n");exit(1);} }
  $pids[]=$pid; }
if($holdLock){usleep(1500000);$blocker->commit();}
$ok=0; foreach($pids as $p){pcntl_waitpid($p,$s); if(pcntl_wexitstatus($s)===0)$ok++;}
$db2=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root','');
$n=(int)$db2->query("SELECT COUNT(*) FROM rental_agreements WHERE driver_id=$d AND status IN ('reserved','confirmed','active')")->fetchColumn();
check('concurrent assignment of one driver to two overlapping rentals ('.($holdLock?'with':'without').' external lock) -> only 1 succeeds', $ok===1 && $n===1, "succeeded=$ok agreements_with_driver=$n");
