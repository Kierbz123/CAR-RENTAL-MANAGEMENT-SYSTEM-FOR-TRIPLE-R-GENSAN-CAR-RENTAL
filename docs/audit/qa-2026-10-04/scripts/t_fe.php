<?php require __DIR__.'/http.php';
$root=new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=audit_tr','root',''); $root->exec("DELETE FROM rate_counters WHERE scope='throttle'");
$q=fn($s)=>$root->query($s)->fetchColumn();
$ids=['agreement_id'=>$q("SELECT MAX(agreement_id) FROM rental_agreements WHERE booking_reference NOT LIKE 'Z%'"),'customer_id'=>$q("SELECT MAX(customer_id) FROM customers WHERE deleted_at IS NULL"),'vehicle_id'=>$q("SELECT MAX(vehicle_id) FROM vehicles"),'driver_id'=>$q("SELECT MAX(driver_id) FROM drivers WHERE deleted_at IS NULL"),'user_id'=>$q("SELECT MIN(id) FROM users")];
$pages=['/staff','/rentals','/rentals/new','/rentals/detail?agreement_id='.$ids['agreement_id'],'/customers','/customers/new','/customers/detail?customer_id='.$ids['customer_id'],'/customers/edit?customer_id='.$ids['customer_id'],'/fleet/vehicles','/fleet/vehicles/new','/fleet/vehicles/detail?vehicle_id='.$ids['vehicle_id'],'/fleet/vehicles/edit?vehicle_id='.$ids['vehicle_id'],'/fleet/drivers','/fleet/drivers/new','/fleet/drivers/detail?driver_id='.$ids['driver_id'],'/fleet/locations','/payments','/admin/users','/admin/sessions?user_id='.$ids['user_id'],'/staff/notifications','/staff/booking-qr'];
$public=['/','/book','/book/find','/staff/login','/magic-link','/track'];
$assets=[]; $issues=[];
$audit=function(string $who,string $p,array $r) use(&$assets,&$issues){
  $b=$r['body']; if($r['code']!==200) return;
  $doc=new DOMDocument(); @$doc->loadHTML($b); $x=new DOMXPath($doc);
  if(!$x->query('//html[@lang]')->length) $issues['missing html lang'][]=$p;
  if(!$x->query('//meta[@name="viewport"]')->length) $issues['missing viewport meta'][]=$p;
  foreach($x->query('//img[not(@alt)]') as $n) $issues['img without alt'][]=$p;
  $idc=[]; foreach($x->query('//*[@id]') as $n){$idc[$n->getAttribute('id')]=($idc[$n->getAttribute('id')]??0)+1;} foreach($idc as $id=>$n) if($n>1) $issues['duplicate id'][]="$p#$id";
  foreach($x->query('//input[not(@type="hidden") and not(@type="submit") and not(@type="button")]|//select|//textarea') as $n){
    $id=$n->getAttribute('id'); $has=$n->getAttribute('aria-label')!==''||$n->getAttribute('aria-labelledby')!=='';
    if(!$has && $id!=='' && $x->query('//label[@for="'.$id.'"]')->length) $has=true;
    for($a=$n->parentNode;!$has && $a;$a=$a->parentNode) if($a->nodeName==='label') $has=true;
    if(!$has) $issues['form control without label'][]=$p.' name='.$n->getAttribute('name');
  }
  foreach($x->query('//form[@method="post" or @method="POST"]') as $f){ if(!$x->query('.//input[@name="_csrf"]',$f)->length && !str_contains($f->getAttribute('action'),'/book')) $issues['POST form without _csrf field'][]=$p.' -> '.$f->getAttribute('action'); }
  foreach($x->query('//script[@src]|//link[@rel="stylesheet"][@href]|//img[@src]') as $n){ $u=$n->getAttribute('src')?:$n->getAttribute('href'); if(str_starts_with($u,'/')) $assets[strtok($u,'?')]=1; }
  if(preg_match('/(SMS_|_KEY=|DB_PASSWORD|api[_-]?key|BEGIN PRIVATE)/i',$b)) $issues['secret-like string in HTML'][]=$p;
  if(preg_match('/\+63\d{10}|09\d{9}/',$b) && $who!=='public') $issues['unmasked phone in HTML (verify role)'][]="$who $p";
};
foreach($public as $p){ $c=new C; $t=microtime(true); $r=$c->req('GET',$p); $ms=(int)((microtime(true)-$t)*1000); echo "public $p {$r['code']} {$ms}ms ".strlen($r['body'])."B\n"; $audit('public',$p,$r); }
$slow=[];
foreach(['system_admin','fleet_manager','front_desk','driver_coordinator','finance_staff'] as $role){ $c=new C; $c->login("qa_$role@audit.test");
  foreach($pages as $p){ $t=microtime(true); $r=$c->req('GET',$p); $ms=(int)((microtime(true)-$t)*1000); if($r['code']===200){ $audit($role,$p,$r); if($ms>500) $slow[]="$role $p {$ms}ms ".round(strlen($r['body'])/1024).'KB'; } } }
foreach(array_keys($assets) as $a){ $r=(new C)->req('GET',$a); if($r['code']!==200) $issues['broken asset'][]="$a {$r['code']}"; }
echo "INFO assets checked: ".count($assets)."\n";
foreach($issues as $k=>$v){ $v=array_values(array_unique($v)); echo "ISSUE $k (".count($v)."): ".implode(' | ',array_slice($v,0,6))."\n"; }
echo "SLOW (>500ms): ".implode(' | ',$slow)."\n";
