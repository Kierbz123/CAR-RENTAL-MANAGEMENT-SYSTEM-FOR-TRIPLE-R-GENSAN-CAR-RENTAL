<?php
// Minimal cookie-jar HTTP client for audit tests.
final class C {
  public string $jar; public string $base='http://127.0.0.1:8000';
  public function __construct(){ $this->jar=tempnam(sys_get_temp_dir(),'jar'); }
  public function req(string $m,string $path,array|string|null $body=null,array $h=[],bool $follow=false): array {
    $ch=curl_init($this->base.$path);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_CUSTOMREQUEST=>$m,CURLOPT_FOLLOWLOCATION=>$follow,CURLOPT_HTTPHEADER=>$h,CURLOPT_TIMEOUT=>30]);
    if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,is_array($body)?http_build_query($body):$body);
    $r=curl_exec($ch); $hs=curl_getinfo($ch,CURLINFO_HEADER_SIZE); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    return ['code'=>$code,'headers'=>substr($r,0,$hs),'body'=>substr($r,$hs)];
  }
  public function csrf(string $path='/staff/login'): string { $b=$this->req('GET',$path)['body']; preg_match('/name="_csrf" value="([^"]+)"/',$b,$m); if(!$m) preg_match('/"csrf":"([^"]+)"/',$b,$m); return $m[1]??''; }
  public function login(string $email,string $pw='Audit-Role-Pass-12345'): int { $t=$this->csrf(); return $this->req('POST','/staff/login',['_csrf'=>$t,'email'=>$email,'password'=>$pw])['code']; }
  public function token(): string { $r=$this->req('GET','/api/staff/navigation'); $j=json_decode($r['body'],true); return $j['csrf']??''; }
}
if(!function_exists("check")){function check(string $label,bool $ok,string $detail=''){ echo ($ok?'PASS':'FAIL').": $label".($detail!==''?" [$detail]":'')."\n"; }}
