<?php require __DIR__.'/http.php';
$root=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root',''); $root->exec("DELETE FROM rate_counters WHERE scope='throttle'");
$up=function(C $c,string $path,array $fields,string $field,string $file,string $mime){ $ch=curl_init($c->base.$path); $fields[$field]=new CURLFile(__DIR__.'/'.$file,$mime,$file);
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEJAR=>$c->jar,CURLOPT_COOKIEFILE=>$c->jar,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$fields,CURLOPT_HEADER=>true]); $r=curl_exec($ch); return curl_getinfo($ch,CURLINFO_HTTP_CODE); };
$fm=new C; $fm->login('qa_fleet_manager@audit.test'); $tok=$fm->token(); $vid=(int)$root->query("SELECT MAX(vehicle_id) FROM vehicles")->fetchColumn();
$count=fn()=>(int)$root->query("SELECT COUNT(*) FROM photos WHERE vehicle_id=$vid")->fetchColumn();
foreach(['shell.jpg'=>'image/jpeg','x.svg'=>'image/svg+xml','big.jpg'=>'image/jpeg','poly.jpg'=>'image/jpeg','ok.jpg'=>'image/jpeg'] as $f=>$m){ $b=$count(); $code=$up($fm,'/fleet/vehicles/photos/upload',['_csrf'=>$tok,'vehicle_id'=>$vid],'photo',$f,$m); $stored=$count()>$b; echo "INFO vehicle photo $f -> http $code stored=".($stored?'yes':'no')."\n"; }
$p=$root->query("SELECT storage_path,mime FROM photos WHERE vehicle_id=$vid ORDER BY photo_id")->fetchAll(PDO::FETCH_ASSOC); echo "INFO stored: ".json_encode($p)."\n";
$storage=getenv('STORAGE_PATH'); check('stored photos live outside public/ web root', !str_contains(realpath($storage),'/public'));
$r=(new C)->req('GET','/'.$p[0]['storage_path']); check('stored file not reachable by URL', $r['code']===404,(string)$r['code']);
foreach(['/.env','/../.env','/..%2f.env','/%2e%2e/.env','/storage/','/database/migrations/001_notifications.sql','/app/Config.php','/router.php','/index.php/../../.env','/.git/config','/composer.json','/package.json'] as $u){ $r=(new C)->req('GET',$u); $leak=preg_match('/DB_PASSWORD|CREATE TABLE|namespace TripleR|\[core\]|"devDependencies"/',$r['body']); echo ($leak?'FAIL':'PASS').": GET $u -> {$r['code']}".($leak?' LEAKED':'')."\n"; }
