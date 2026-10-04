<?php require __DIR__.'/http.php';
$db=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root',''); $db->exec("DELETE FROM rate_counters WHERE scope='throttle'");
$db->exec("UPDATE users SET is_active=0, deleted_at=UTC_TIMESTAMP(6) WHERE role='system_admin' AND email<>'qa_system_admin@audit.test'");
$codes=[]; for($i=0;$i<5;$i++){ $codes[]=(new C)->login('qa_system_admin@audit.test','bad-password-guess'); }
$ok=(int)$db->query("SELECT COUNT(*) FROM users WHERE role='system_admin' AND is_active=1 AND deleted_at IS NULL AND locked_at IS NULL")->fetchColumn();
check('anonymous guesses cannot leave zero usable admins', $ok>=1, "codes=".implode(',',$codes)." unlocked_admins=$ok");
$db->exec("UPDATE users SET locked_at=NULL,failed_login_count=0,is_active=1,deleted_at=NULL"); $db->exec("DELETE FROM rate_counters WHERE scope='throttle'");
