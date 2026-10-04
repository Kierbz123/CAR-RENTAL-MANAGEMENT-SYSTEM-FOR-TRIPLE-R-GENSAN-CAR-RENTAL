<?php require __DIR__.'/http.php';
$routes=array_map(fn($l)=>explode(' ',trim($l)),file(__DIR__.'/routes.txt'));
$db=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root','');
$aid=(int)$db->query("SELECT agreement_id FROM rental_agreements WHERE status='reserved' ORDER BY agreement_id LIMIT 1")->fetchColumn();
$admin=new C; echo "admin login ".$admin->login('qa_system_admin@audit.test')."\n";
$publicPosts=['/staff/login','/book','/book/find','/api/magic-links/redeem','/webhooks/sms/inbound','/webhooks/sms/delivery','/webhooks/payments','/pay/demo','/api/tracking/report','/customer/booking/proof','/customer/booking/pay'];
$bad=0;
foreach($routes as [$m,$p]){ if($m!=='post') continue;
  $r=$admin->req('POST',$p,['agreement_id'=>$aid,'customer_id'=>1,'vehicle_id'=>1,'driver_id'=>1,'user_id'=>1,'action'=>'confirm']);
  $mut = !in_array($r['code'],[400,401,403,404,415,422,429],true) && !($r['code']===303 && str_contains($r['headers'],'login'));
  if(in_array($p,$publicPosts,true)) { echo "INFO public POST $p -> {$r['code']}\n"; continue; }
  if($r['code']!==403){ $bad++; echo "FAIL: CSRF-less POST $p as admin -> {$r['code']} ".substr(preg_replace('/\s+/',' ',strip_tags($r['body'])),0,90)."\n"; }
}
echo ($bad===0?'PASS':'FAIL').": every authenticated state-changing POST rejects missing CSRF token (failures=$bad)\n";
// Fuzz: array params and huge/garbage values on GET and POST (with CSRF) -> any 500?
$tok=$admin->token(); $five=[];
foreach($routes as [$m,$p]){
  foreach([['x[]'=>'1','agreement_id[]'=>'1','customer_id[]'=>1,'vehicle_id[]'=>1,'driver_id[]'=>1,'status[]'=>'a','search[]'=>'a','type[]'=>'a','limit[]'=>1,'purpose[]'=>'x','receipt[]'=>'x','proof_id[]'=>1,'photo_id[]'=>1,'report_id[]'=>1,'user_id[]'=>1,'start_date[]'=>'x','class[]'=>'x'],['agreement_id'=>'99999999999999999999','customer_id'=>'-1','status'=>str_repeat('A',5000),'search'=>"' OR 1=1 -- ",'limit'=>'1e9','start_date'=>'2026-13-45','end_date'=>'0000-00-00','receipt'=>'../../etc/passwd','photo_id'=>'1 UNION SELECT 1']] as $q){
    $r = $m==='get' ? $admin->req('GET',$p.'?'.http_build_query($q)) : $admin->req('POST',$p,$q+['_csrf'=>$tok],['X-CSRF-Token: '.$tok]);
    if($r['code']>=500){ $five[]="$m $p ".json_encode(array_keys($q))[0]."... -> {$r['code']}"; }
    if(preg_match('/(Stack trace|PDOException|SQLSTATE|\.php on line|Fatal error)/',$r['body'])) echo "FAIL: error detail leaked at $m $p\n";
  }
}
echo (count($five)===0?'PASS':'FAIL').": no 5xx from array/garbage params across ".count($routes)." routes (5xx=".count($five).")\n"; foreach($five as $f) echo "   $f\n";
