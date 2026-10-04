<?php require __DIR__.'/svc.php'; require __DIR__.'/http.php';
$root=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root','');
$root->exec("DELETE FROM rate_counters WHERE scope='throttle'"); // fresh limiter windows for this test only
for($i=0;$i<12;$i++) newVehicle($ADMIN,'1800.00',null);
$start=(new DateTimeImmutable('today +20 days',new DateTimeZone('Asia/Manila')))->format('Y-m-d'); $end=(new DateTimeImmutable('today +23 days',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$policy=(int)$db->query("SELECT MAX(rules_version_id) FROM rules_versions WHERE rules_key='downpayment_policy'")->fetchColumn();
$book=function(string $phone,string $name,?int $vehicle=null) use($start,$end,$policy){ $c=new C; $page=$c->req('GET',"/book?start_date=$start&end_date=$end")['body']; preg_match('/name="_csrf" value="([^"]+)"/',$page,$m); preg_match_all('/name="vehicle_id" value="(\d+)"/',$page,$vs);
  $v=$vehicle??(int)($vs[1][0]??0); $r=$c->req('POST','/book',['_csrf'=>$m[1]??'','start_date'=>$start,'end_date'=>$end,'vehicle_id'=>$v,'pickup_time'=>'10:00','full_name'=>$name,'phone'=>$phone,'email'=>'','accept_policy'=>'1','policy_version_id'=>$policy]);
  return [$r['code'],$v,$c,count($vs[1])]; };
// 1. Booking with an existing customer's phone attaches to that customer
$victimPhone='0918'.random_int(1000000,9999999); $victim=newCustomer($ADMIN,['phone'=>$victimPhone,'full_name'=>'Real Victim']);
[$code,$v]=$book($victimPhone,'Attacker Name');
$row=$root->query("SELECT r.customer_id,c.full_name FROM rental_agreements r JOIN customers c USING(customer_id) WHERE r.vehicle_id=$v AND r.booking_source='online' ORDER BY agreement_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check('anonymous booking with someone else\'s phone does NOT attach to that customer', (int)($row['customer_id']??0)!==$victim, "http=$code booked_under={$row['full_name']} (customer $victim)");
// 2. Inventory hold: how many vehicles can one IP hold with fresh phone numbers, no payment?
$held=0; $avail=null; for($i=0;$i<14;$i++){ [$code,$v,,$n]=$book('0919'.random_int(1000000,9999999),'Hold '.$i); $avail??=$n; if($code===303)$held++; }
echo "INFO vehicles listed available for the dates before test: $avail\n";
check('one anonymous IP cannot reserve many vehicles without paying', $held<=2, "held=$held unpaid 24h holds from one IP in a minute");
$left=$book('0920'.random_int(1000000,9999999),'Probe')[3]; echo "INFO vehicles still bookable for those dates now: $left\n";
$n=(int)$root->query("SELECT COUNT(*) FROM customers WHERE customer_type='online' AND full_name LIKE 'Hold %'")->fetchColumn(); echo "INFO unverified customer records created by anonymous visitor: $n\n";
