<?php require __DIR__.'/svc.php'; require __DIR__.'/http.php';
$root=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root',''); $root->exec("DELETE FROM rate_counters WHERE scope='throttle'");
$tz=new DateTimeZone('Asia/Manila'); $d=fn($s)=>(new DateTimeImmutable($s,$tz))->format('Y-m-d');
// 1. master data
$v=newVehicle($ADMIN,'1333.33','1000.00'); $c=newCustomer($ADMIN); $drv=newDriver($ADMIN);
// 2. chauffeur rental that started 3 days ago and was due back yesterday (staff back-entry; see past-date finding)
$s=$d('today -3 days'); $e=$d('today -1 day');
$aid=$rentals->create(['customer_id'=>$c,'vehicle_id'=>$v,'rental_type'=>'chauffeur','start_date'=>$s,'end_date'=>$e,'scheduled_pickup_at'=>$s.'T09:00','scheduled_return_at'=>$e.'T09:00','deposit_amount'=>'0'],$ADMIN);
$row=$db->query("SELECT rental_days,base_amount,downpayment_amount FROM rental_agreements WHERE agreement_id=$aid")->fetch();
check('base = 2 days x 1333.33 = 2666.66', $row['base_amount']==='2666.66' && (int)$row['rental_days']===2, json_encode($row));
check('downpayment = round(30% of 2666.66)=800.00', $row['downpayment_amount']==='800.00');
$chauffeur->assignDriver($aid,$drv,$ADMIN);
$fee=$db->query("SELECT amount FROM rental_charges WHERE agreement_id=$aid AND charge_type='chauffeur_fee'")->fetchColumn(); check('chauffeur fee = 2 x 1000 = 2000.00', $fee==='2000.00');
check('downpayment is 30% of base only (chauffeur fee not included)', $db->query("SELECT downpayment_amount FROM rental_agreements WHERE agreement_id=$aid")->fetchColumn()==='800.00', 'total now '.$rentals->total($aid).', 30% of total would be '.number_format(0.3*(float)$rentals->total($aid),2,'.',''));
$rentals->recordDownpayment($aid,'GC'.random_int(10000000,99999999),$ADMIN,'gcash');
$rentals->transition($aid,'confirm',$ADMIN);
$rentals->transition($aid,'pickup',$ADMIN,null,5000);
check('vehicle rented after pickup', $db->query("SELECT current_status FROM vehicles WHERE vehicle_id=$v")->fetchColumn()==='rented');
// Can a future booking be made for a vehicle that is currently out?
$fs=$d('today +30 days'); $fe=$d('today +32 days');
$r=attempt(fn()=>$rentals->create(['customer_id'=>newCustomer($ADMIN),'vehicle_id'=>$v,'start_date'=>$fs,'end_date'=>$fe,'scheduled_pickup_at'=>$fs.'T09:00','scheduled_return_at'=>$fe.'T09:00','deposit_amount'=>'0'],$ADMIN));
check('a non-overlapping future booking can be made while the vehicle is currently rented', str_starts_with($r,'OK'), $r);
// 3. late return (due yesterday 09:00, returned now)
$before=$rentals->total($aid); $rentals->transition($aid,'return',$ADMIN,null,5400);
check('late return (>=1 day overdue) adds an extra-day/late charge automatically', $rentals->total($aid)!==$before, "total before=$before after=".$rentals->total($aid));
// 4. damage at return via HTTP (front_desk), photo required
$fd=new C; $fd->login('qa_front_desk@audit.test'); $tok=$fd->token();
$ch=curl_init($fd->base.'/rentals/damage/report'); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$fd->jar,CURLOPT_COOKIEJAR=>$fd->jar,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['_csrf'=>$tok,'agreement_id'=>$aid,'phase'=>'post','has_damage'=>'1','location'=>'Front bumper','damage_type'=>'Dent','severity'=>'severe','repair_cost_suggestion'=>'15000','photos[]'=>new CURLFile(__DIR__.'/ok.jpg','image/jpeg','ok.jpg')]]); curl_exec($ch);
$rep=(int)$db->query("SELECT MAX(report_id) FROM damage_reports WHERE agreement_id=$aid")->fetchColumn(); check('severe post-rental damage recorded over HTTP', $rep>0);
$vs=$db->query("SELECT current_status FROM vehicles WHERE vehicle_id=$v")->fetchColumn();
check('vehicle with severe unresolved damage is not left bookable (status '.$vs.')', $vs!=='available');
$s2=$d('today +2 days'); $e2=$d('today +3 days');
$r=attempt(fn()=>$rentals->create(['customer_id'=>newCustomer($ADMIN),'vehicle_id'=>$v,'start_date'=>$s2,'end_date'=>$e2,'scheduled_pickup_at'=>$s2.'T09:00','scheduled_return_at'=>$e2.'T09:00','deposit_amount'=>'0'],$ADMIN));
echo "INFO booking the severely damaged vehicle for the day after tomorrow: $r\n";
// 5. damage liability + charge via services (manager decides, finance posts)
$dmg=new \TripleR\Services\DamageService($db,new \TripleR\Repositories\DamageReportRepository($db),$rentalRepo,new \TripleR\Services\VehiclePhotoService($db),$rentals);
$dec=$dmg->decide($rep,true,'12000.00','Customer liable per inspection',$ADMIN); $dmg->postCharge($dec,'12000.00','',$ADMIN);
// 6. money cross-check vs raw SQL
$sql=$db->query("SELECT r.base_amount + COALESCE(SUM(CASE WHEN c.charge_type='discount' THEN -1 ELSE 1 END * CASE WHEN c.entry_kind='reversal' THEN -1 ELSE 1 END * c.amount),0) FROM rental_agreements r LEFT JOIN rental_charges c ON c.agreement_id=r.agreement_id WHERE r.agreement_id=$aid GROUP BY r.agreement_id")->fetchColumn();
check('app total equals raw SQL total', $rentals->total($aid)===$sql, "app={$rentals->total($aid)} sql=$sql");
$ps=new \TripleR\Services\PaymentService($db,new \TripleR\Repositories\PaymentRepository($db),$rentalRepo,$rentals,new \TripleR\Repositories\PaymentProofRepository($db),new \TripleR\Repositories\RulesAcceptanceRepository($db),new \TripleR\Services\RateLimiter($db),new \TripleR\Repositories\SecurityLogRepository($db),null);
check('overpayment refused', str_starts_with(attempt(fn()=>$ps->recordBalance($aid,'cash','','99999.00',$ADMIN)),'ERR'));
$r=attempt(fn()=>$rentals->transition($aid,'complete',$ADMIN)); check('cannot complete with balance outstanding', str_starts_with($r,'ERR'), $r);
$ps->recordBalance($aid,'cash','','',$ADMIN);
$paid=$db->query("SELECT SUM(amount) FROM payments WHERE agreement_id=$aid AND payment_status='paid'")->fetchColumn(); check('payments sum to total', $paid===$sql, "paid=$paid total=$sql");
$rentals->transition($aid,'complete',$ADMIN); check('completed', $db->query("SELECT status FROM rental_agreements WHERE agreement_id=$aid")->fetchColumn()==='completed');
$m=$db->query("SELECT GROUP_CONCAT(mileage ORDER BY mileage_log_id) FROM vehicle_mileage_logs WHERE vehicle_id=$v")->fetchColumn(); echo "INFO mileage log: $m\n";
$t=$db->query("SELECT DATE_FORMAT(actual_pickup_at,'%Y-%m-%d %H:%i'), DATE_FORMAT(CONVERT_TZ(actual_pickup_at,'+00:00','+08:00'),'%Y-%m-%d %H:%i') FROM rental_agreements WHERE agreement_id=$aid")->fetch(PDO::FETCH_NUM); echo "INFO actual_pickup_at stored UTC {$t[0]} = Manila {$t[1]}\n";
$html=$fd->req('GET',"/rentals/detail?agreement_id=$aid")['body']; check('detail page shows pickup time in Manila time', str_contains($html,(new DateTimeImmutable($t[1]))->format('g:i')) , 'looking for '.(new DateTimeImmutable($t[1]))->format('g:i'));
check('detail page shows peso amounts', str_contains($html,'₱'));
