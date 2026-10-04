<?php
declare(strict_types=1);

namespace TripleR\Controllers\Rentals;

use RuntimeException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Security\Csrf;
use TripleR\Services\DamageService;

final class DamageController
{
    public function __construct(private readonly AuthMiddleware $guard,private readonly DamageService $damage) {}

    public function record(Request $request): Response
    {
        $user=$this->guard->requireRoles(['front_desk','fleet_manager']);if($user instanceof Response)return $user;
        if(!Csrf::valid($request))return Response::html('Invalid request token.',403);
        $agreement=$this->id($request->form['agreement_id']??null);if(!$agreement)return Response::html('Invalid rental agreement.',422);
        try{$this->damage->record($agreement,(string)($request->form['phase']??''),($request->form['has_damage']??'')==='1',$request->form,$this->uploads($request->files['photos']??[]),(int)$user['id']);$held=$this->damage->vehicleHeldAs();$_SESSION['_rental_notice']='Damage inspection recorded.'.($held===null?'':' The vehicle is now '.($held==='out_of_service'?'out of service':'in maintenance').' and cannot be booked until a fleet manager makes it available again.');}
        catch(RuntimeException $e){$_SESSION['_rental_notice']=$e->getMessage();}
        return Response::redirect('/rentals/detail?agreement_id='.$agreement);
    }

    public function decide(Request $request): Response
    {
        $user=$this->guard->requireRoles(['fleet_manager','system_admin']);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);
        $report=$this->id($request->form['report_id']??null);$agreement=$this->id($request->form['agreement_id']??null);if(!$report||!$agreement)return Response::html('Invalid damage report.',422);
        try{$this->damage->decide($report,($request->form['customer_liable']??'')==='1',(string)($request->form['liable_amount']??''),(string)($request->form['reason']??''),(int)$user['id'],$this->id($request->form['supersedes_decision_id']??null));$_SESSION['_rental_notice']='Liability decision recorded.';}
        catch(RuntimeException $e){$_SESSION['_rental_notice']=$e->getMessage();}return Response::redirect('/rentals/detail?agreement_id='.$agreement);
    }

    public function postCharge(Request $request): Response
    {
        $user=$this->guard->requireRoles(['finance_staff']);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);
        $agreement=$this->id($request->form['agreement_id']??null);$decision=$this->id($request->form['decision_id']??null);if(!$agreement||!$decision)return Response::html('Invalid damage charge.',422);
        try{$this->damage->postCharge($decision,(string)($request->form['amount']??''),(string)($request->form['adjustment_reason']??''),(int)$user['id']);$_SESSION['_rental_notice']='Damage charge added.';}
        catch(RuntimeException $e){$_SESSION['_rental_notice']=$e->getMessage();}return Response::redirect('/rentals/detail?agreement_id='.$agreement);
    }

    public function photo(Request $request): Response
    {
        $user=$this->guard->requireRoles(['system_admin','front_desk','fleet_manager','finance_staff']);if($user instanceof Response)return $user;
        $id=$this->id($request->query['photo_id']??null);if(!$id)return Response::html('Photo not found.',404);
        try{$photo=$this->damage->streamPhoto($id);return Response::binary($photo['body'],$photo['mime']);}catch(RuntimeException){return Response::html('Photo not found.',404);}
    }

    public function detail(Request $request): Response
    {
        $user=$this->guard->requireRoles(['system_admin','front_desk','fleet_manager','finance_staff']);if($user instanceof Response)return $user;
        $id=$this->id($request->query['report_id']??null);if(!$id)return Response::html('Damage report not found.',404);$report=$this->damage->detail($id);if(!$report)return Response::html('Damage report not found.',404);
        $e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');ob_start();require APP_ROOT.'/app/Views/rentals/damage-report.php';return Response::html((string)ob_get_clean());
    }

    private function uploads(mixed $files): array
    {
        if(!is_array($files)||!isset($files['name']))return [];
        if(!is_array($files['name']))return [$files];$result=[];foreach($files['name'] as $i=>$name)$result[]=['name'=>$name,'type'=>$files['type'][$i]??'','tmp_name'=>$files['tmp_name'][$i]??'','error'=>$files['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$files['size'][$i]??0];return $result;
    }
    private function id(mixed $v): ?int{$id=filter_var($v,FILTER_VALIDATE_INT);return $id!==false&&$id!==null&&$id>0?(int)$id:null;}
}
