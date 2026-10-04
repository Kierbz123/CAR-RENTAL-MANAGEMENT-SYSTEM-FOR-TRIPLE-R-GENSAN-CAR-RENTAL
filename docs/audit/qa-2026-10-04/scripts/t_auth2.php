<?php require __DIR__.'/http.php';
$db=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root',''); $db->exec("DELETE FROM rate_counters WHERE scope='throttle'");
// Locked account: correct password refused, generic message
$db->exec("UPDATE users SET locked_at=UTC_TIMESTAMP(6) WHERE email='qa_driver_coordinator@audit.test'");
$c=new C; $code=$c->login('qa_driver_coordinator@audit.test'); check('locked account refuses even the correct password (confirms lockout DoS impact)', $code===401, "http=$code");
$db->exec("UPDATE users SET locked_at=NULL,failed_login_count=0 WHERE email='qa_driver_coordinator@audit.test'");
// Timing-based enumeration
$t=function($e){$s=microtime(true);$c=new C;$c->login($e,'Wrong-password-123456');return microtime(true)-$s;};
$known=[];$unk=[];for($i=0;$i<4;$i++){$db->exec("DELETE FROM rate_counters WHERE scope='throttle'");$db->exec("UPDATE users SET locked_at=NULL,failed_login_count=0");$known[]=$t('qa_front_desk@audit.test');$unk[]=$t('nobody'.$i.'@audit.test');}
sort($known);sort($unk); printf("INFO login timing median: existing account %.0f ms vs unknown email %.0f ms\n",$known[1]*1000,$unk[1]*1000);
$db->exec("UPDATE users SET locked_at=NULL,failed_login_count=0"); $db->exec("DELETE FROM rate_counters WHERE scope='throttle'");
// Fleet manager sees full customer phones
$c=new C; $c->login('qa_fleet_manager@audit.test'); $r=$c->req('GET','/api/staff/notifications?limit=200'); $j=json_decode($r['body'],true); $phones=array_filter(array_column($j['notifications']??[],'recipient_phone'));
check('fleet_manager notification API masks customer phone numbers', !preg_grep('/^\+63\d{10}$/',$phones), 'sample='.(array_values($phones)[0]??'none').' count='.count($phones));
$r=$c->req('POST','/customers/reveal',['_csrf'=>$c->token(),'customer_id'=>1,'contact_id'=>1]); echo "INFO fleet_manager /customers/reveal -> {$r['code']} (expected 403)\n";
// Admin self-protection: two admins demote each other until none left?
$db->exec("UPDATE users SET is_active=0, deleted_at=UTC_TIMESTAMP(6) WHERE role='system_admin' AND email NOT IN ('qa_system_admin@audit.test','admin@example.test')");
$a=new C; $a->login('qa_system_admin@audit.test'); $other=(int)$db->query("SELECT id FROM users WHERE email='admin@example.test'")->fetchColumn();
$r=$a->req('POST','/admin/users/deactivate',['_csrf'=>$a->token(),'user_id'=>$other]);
$self=(int)$db->query("SELECT id FROM users WHERE email='qa_system_admin@audit.test'")->fetchColumn();
$r2=$a->req('POST','/admin/users/role',['_csrf'=>$a->token(),'user_id'=>$self,'role'=>'front_desk']); $r3=$a->req('POST','/admin/users/deactivate',['_csrf'=>$a->token(),'user_id'=>$self]);
$active=(int)$db->query("SELECT COUNT(*) FROM users WHERE role='system_admin' AND is_active=1 AND deleted_at IS NULL")->fetchColumn();
check('system keeps at least one active admin (self-demote/deactivate blocked)', $active>=1, "active_admins=$active");
// Can a lone admin be locked out by brute force -> nobody can unlock
for($i=0;$i<5;$i++){ (new C)->login('qa_system_admin@audit.test','bad-password-guess'); }
$locked=(int)$db->query("SELECT COUNT(*) FROM users WHERE role='system_admin' AND is_active=1 AND deleted_at IS NULL AND locked_at IS NULL")->fetchColumn();
check('anonymous guesses cannot leave zero usable admins', $locked>=1, "unlocked_active_admins=$locked (only recovery: direct DB edit)");
$db->exec("UPDATE users SET locked_at=NULL,failed_login_count=0, is_active=1, deleted_at=NULL"); $db->exec("DELETE FROM rate_counters WHERE scope='throttle'");
