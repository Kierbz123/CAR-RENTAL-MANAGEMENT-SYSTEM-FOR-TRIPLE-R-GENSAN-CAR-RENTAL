<?php require __DIR__.'/http.php';
$db=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root','');
// Cookie flags
$c=new C; $r=$c->req('GET','/staff/login'); preg_match('/Set-Cookie: triple_r_staff=[^\r\n]*/i',$r['headers'],$m);
echo "INFO session cookie: ".($m[0]??'none')."\n";
check('session cookie HttpOnly', stripos($m[0]??'','httponly')!==false);
check('session cookie SameSite=Lax', stripos($m[0]??'','samesite=lax')!==false);
check('session cookie Secure over plain http (expected absent locally)', stripos($m[0]??'','secure')===false);
foreach(['X-Content-Type-Options','Content-Security-Policy','Referrer-Policy','Strict-Transport-Security','X-Frame-Options','Permissions-Policy'] as $h) echo "INFO header $h: ".(stripos($r['headers'],$h.':')!==false?'present':'ABSENT')."\n";
// Session fixation: attacker-chosen ID
$c=new C; file_put_contents($c->jar,"127.0.0.1\tFALSE\t/\tFALSE\t0\ttriple_r_staff\tattackerchosenid1234567890ab\n");
$c->login('qa_front_desk@audit.test'); $jar=file_get_contents($c->jar);
check('session fixation: attacker-chosen session id not kept after login', !str_contains($jar,'attackerchosenid1234567890ab') || !preg_match('/triple_r_staff\tattackerchosenid/',$jar));
// Logout invalidates server-side
$c=new C; $c->login('qa_front_desk@audit.test'); $jarCopy=file_get_contents($c->jar); $tok=$c->token();
$c->req('POST','/staff/logout',['_csrf'=>$tok]); file_put_contents($c->jar,$jarCopy);
$r=$c->req('GET','/staff'); check('old session cookie rejected after logout', $r['code']===303 && str_contains($r['headers'],'/staff/login'), (string)$r['code']);
// Logout without CSRF
$c=new C; $c->login('qa_front_desk@audit.test'); $r=$c->req('POST','/staff/logout',[]); check('logout without CSRF token refused', $r['code']===403);
// Session expiry: force expires_at past
$c=new C; $c->login('qa_finance_staff@audit.test'); $db->exec("UPDATE sessions s JOIN users u ON u.id=s.user_id SET s.expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE u.email='qa_finance_staff@audit.test' AND s.invalidated_at IS NULL");
$r=$c->req('GET','/payments'); check('expired session redirected to login', $r['code']===303, (string)$r['code']);
// Idle timeout: is there one? (last_seen_at is updated but never compared)
echo "INFO idle timeout: sessions valid until absolute expires_at (12h default); last_seen_at not enforced\n";
// Password hash algorithm
$h=$db->query("SELECT password_hash FROM users WHERE email='admin@example.test'")->fetchColumn(); echo "INFO stored hash prefix: ".substr($h,0,7)."\n";
// Lockout DoS
$c=new C; for($i=0;$i<5;$i++){ $c->login('qa_fleet_manager@audit.test','wrong-password-xx'); }
$locked=$db->query("SELECT locked_at IS NOT NULL FROM users WHERE email='qa_fleet_manager@audit.test'")->fetchColumn();
$c2=new C; $code=$c2->login('qa_fleet_manager@audit.test'); 
check('5 wrong passwords from an anonymous client do NOT permanently lock the real user out', !$locked, "locked=$locked; correct password then -> HTTP $code");
// Fleet manager sees customer phones in notification API
$db->exec("UPDATE users SET locked_at=NULL, failed_login_count=0 WHERE email='qa_fleet_manager@audit.test'");
$c=new C; $c->login('qa_fleet_manager@audit.test'); $r=$c->req('GET','/api/staff/notifications');
$j=json_decode($r['body'],true); $p=$j['notifications'][0]['recipient_phone']??''; $prev=$j['notifications'][0]['message_preview']??'';
check('fleet_manager cannot see full customer phone numbers (customer reveal is admin/front_desk only)', !preg_match('/^\+63\d{10}$/',$p), "recipient_phone=$p");
echo "INFO preview sample: ".substr($prev,0,120)."\n";
