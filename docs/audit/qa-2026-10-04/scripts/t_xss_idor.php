<?php require __DIR__.'/http.php';
$root=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root',''); $root->exec("DELETE FROM rate_counters WHERE scope='throttle'");
$start=(new DateTimeImmutable('today +40 days',new DateTimeZone('Asia/Manila')))->format('Y-m-d'); $end=(new DateTimeImmutable('today +42 days',new DateTimeZone('Asia/Manila')))->format('Y-m-d');
$policy=(int)$root->query("SELECT MAX(rules_version_id) FROM rules_versions")->fetchColumn();
$payload='"><img src=x onerror=alert(1)><script>alert(2)</script>';
$cust=new C; $page=$cust->req('GET',"/book?start_date=$start&end_date=$end")['body']; preg_match('/name="_csrf" value="([^"]+)"/',$page,$m); preg_match('/name="vehicle_id" value="(\d+)"/',$page,$v);
$r=$cust->req('POST','/book',['_csrf'=>$m[1],'start_date'=>$start,'end_date'=>$end,'vehicle_id'=>$v[1],'pickup_time'=>'10:00','full_name'=>$payload,'phone'=>'0921'.random_int(1000000,9999999),'email'=>'','accept_policy'=>'1','policy_version_id'=>$policy]);
echo "INFO booking with XSS name -> {$r['code']}\n";
$aid=(int)$root->query("SELECT MAX(agreement_id) FROM rental_agreements WHERE booking_source='online'")->fetchColumn(); $cid=(int)$root->query("SELECT customer_id FROM rental_agreements WHERE agreement_id=$aid")->fetchColumn();
$fd=new C; $fd->login('qa_front_desk@audit.test');
foreach(['/customers',"/customers/detail?customer_id=$cid",'/rentals',"/rentals/detail?agreement_id=$aid",'/staff'] as $p){ $b=$fd->req('GET',$p)['body']; check("stored XSS escaped on $p", !str_contains($b,'<script>alert(2)') && !str_contains($b,'<img src=x'), str_contains($b,'&lt;script&gt;')?'escaped copy present':'payload not rendered'); }
$fin=new C; $fin->login('qa_finance_staff@audit.test'); $b=$fin->req('GET','/payments')['body']; check('stored XSS escaped on /payments', !str_contains($b,'<script>alert(2)'));
// Customer-side IDOR: this customer's session asks for another booking's receipt / proof
$other=$root->query("SELECT receipt_number FROM payments WHERE agreement_id<>$aid ORDER BY payment_id LIMIT 1")->fetchColumn();
$r=$cust->req('GET','/customer/booking/payment?receipt='.$other); check('customer cannot open another booking\'s payment receipt', !str_contains($r['body'],$other) || $r['code']>=400, "http={$r['code']}");
$r=$cust->req('GET','/payments/proof?proof_id=1'); check('customer session cannot fetch payment proof images', $r['code']===303||$r['code']===403, (string)$r['code']);
$r=$cust->req('GET','/rentals/damage/photo?photo_id=1'); check('customer session cannot fetch damage photos', $r['code']===303||$r['code']===403, (string)$r['code']);
// Booking lookup brute force limit
$f=new C; $codes=[]; for($i=0;$i<12;$i++){ $pg=$f->req('GET','/book/find')['body']; preg_match('/name="_csrf" value="([^"]+)"/',$pg,$mm); $codes[]=$f->req('POST','/book/find',['_csrf'=>$mm[1],'reference'=>'ABCDEFG'.$i,'phone'=>'09171234567'])['code']; }
check('booking lookup throttled after 10 tries per IP / 15 min', in_array(429,$codes,true), implode(',',$codes));
