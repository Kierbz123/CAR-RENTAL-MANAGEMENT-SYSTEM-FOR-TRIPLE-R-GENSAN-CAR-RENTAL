<?php require __DIR__.'/svc.php'; require __DIR__.'/http.php';
use TripleR\Services\SmsMessageCipher;
// Book a rental for a customer with a phone; the service issues a booking_manage link.
$v=newVehicle($ADMIN); $phone='0917'.random_int(1000000,9999999); $c=newCustomer($ADMIN,['phone'=>$phone]);
$aid=$rentals->create(['customer_id'=>$c,'vehicle_id'=>$v,'start_date'=>'2027-08-01','end_date'=>'2027-08-03','scheduled_pickup_at'=>'2027-08-01T10:00','scheduled_return_at'=>'2027-08-03T10:00','deposit_amount'=>'0'],$ADMIN);
$row=$db->query("SELECT recipient_phone,template_key,rendered_message FROM notifications WHERE customer_id=$c AND template_key='magic_link.booking_manage' ORDER BY id DESC LIMIT 1")->fetch();
$msg=(new SmsMessageCipher())->decrypt($row['rendered_message'],SmsMessageCipher::context($row['recipient_phone'],$row['template_key']));
preg_match('/token=([A-Za-z0-9_-]{43})/',$msg,$m); $tok=$m[1]; echo "INFO token recovered from queued SMS (len ".strlen($tok).")\n";
$H=['Content-Type: application/json','Origin: http://127.0.0.1:8000'];
$redeem=fn(C $cl,string $t,string $p='booking_manage',array $h=null)=>$cl->req('POST','/api/magic-links/redeem',json_encode(['token'=>$t,'purpose'=>$p]),$h??$H);
$a=new C;
check('missing Origin refused', $redeem($a,$tok,'booking_manage',['Content-Type: application/json'])['code']===403);
check('cross-origin refused', $redeem($a,$tok,'booking_manage',['Content-Type: application/json','Origin: https://evil.example'])['code']===403);
check('form-encoded (CSRF-able) refused', $a->req('POST','/api/magic-links/redeem','token='.$tok.'&purpose=booking_manage',['Content-Type: application/x-www-form-urlencoded','Origin: http://127.0.0.1:8000'])['code']===403);
check('token in query string refused', $a->req('POST','/api/magic-links/redeem?token='.$tok,'{}',$H)['code']===400);
check('wrong purpose refused', $redeem($a,$tok,'vehicle_tracker')['code']===400);
$tam=substr($tok,0,-1).($tok[-1]==='A'?'B':'A'); check('tampered token refused', $redeem($a,$tam)['code']===400);
$r=$redeem($a,$tok); check('valid token redeems', $r['code']===200, $r['body']);
$r=$a->req('GET','/api/rentals/booking-context'); $j=json_decode($r['body'],true); check('redeemed session sees only its own booking', ($j['booking']['agreement_id']??0)===$aid);
$b=new C; check('replay of used token refused (second browser)', $redeem($b,$tok)['code']===400);
// expiry
$v2=newVehicle($ADMIN); $c2=newCustomer($ADMIN,['phone'=>'0917'.random_int(1000000,9999999)]);
$aid2=$rentals->create(['customer_id'=>$c2,'vehicle_id'=>$v2,'start_date'=>'2027-08-01','end_date'=>'2027-08-03','scheduled_pickup_at'=>'2027-08-01T10:00','scheduled_return_at'=>'2027-08-03T10:00','deposit_amount'=>'0'],$ADMIN);
$row=$db->query("SELECT recipient_phone,template_key,rendered_message FROM notifications WHERE customer_id=$c2 AND template_key='magic_link.booking_manage' ORDER BY id DESC LIMIT 1")->fetch();
$msg=(new SmsMessageCipher())->decrypt($row['rendered_message'],SmsMessageCipher::context($row['recipient_phone'],$row['template_key'])); preg_match('/token=([A-Za-z0-9_-]{43})/',$msg,$m); $tok2=$m[1];
$root=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root',''); $root->exec("UPDATE booking_access_tokens SET expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE booking_id=$aid2");
check('expired token refused', $redeem(new C,$tok2)['code']===400);
// Session revoked when booking reaches terminal state
$rentals->transition($aid,'cancel',$ADMIN,'QA cancel');
$r=$a->req('GET','/api/rentals/booking-context'); check('customer session loses access after booking cancelled', $r['code']===401,(string)$r['code']);
echo "INFO hold/expiry: link TTL capped at hold_expires_at; DB stores only sha256(token)\n";
$stored=$db->query("SELECT token_hash FROM booking_access_tokens WHERE booking_id=$aid")->fetchColumn(); check('DB stores hash not raw token', $stored===hash('sha256',$tok));
