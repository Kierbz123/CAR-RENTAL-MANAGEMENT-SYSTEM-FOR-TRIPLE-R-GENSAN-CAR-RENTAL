<?php
declare(strict_types=1);

namespace TripleR\Controllers;

use RuntimeException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\MaintenanceRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Security\Csrf;
use TripleR\Services\MaintenanceService;

final class MaintenanceController
{
    private const READ=['system_admin','fleet_manager','mechanic','auditor'];
    private const OPERATE=['system_admin','fleet_manager','mechanic'];
    private const CONFIGURE=['system_admin','fleet_manager'];

    public function __construct(private readonly AuthMiddleware $guard,private readonly MaintenanceRepository $repository,private readonly VehicleRepository $vehicles,private readonly MaintenanceService $service) {}

    public function index(): Response
    {
        $user=$this->guard->requireRoles(self::READ);if($user instanceof Response)return $user;
        try{$defaults=$this->service->defaults();$due=$this->repository->dueSoon($defaults['days'],$defaults['kilometers']);}catch(RuntimeException $e){return Response::html(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'),500);}
        return $this->render('maintenance-schedules',['user'=>$user,'vehicles'=>$this->repository->vehicles(),'schedules'=>$this->repository->schedules(),'services'=>$this->repository->services(),'due'=>$due,'needsReview'=>$this->repository->needsReview(),'defaults'=>$defaults,'notice'=>$_SESSION['_maintenance_notice']??null]);
    }

    public function history(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::READ);if($user instanceof Response)return $user;$vehicle=$this->id($request->query['vehicle_id']??null);if(!$vehicle)return Response::html('Choose a valid vehicle.',422);if(!$this->vehicles->findIncludingRetired($vehicle))return Response::html('Vehicle not found.',404);
        return $this->render('maintenance-history',['user'=>$user,'vehicleId'=>$vehicle,'vehicle'=>$this->vehicles->findIncludingRetired($vehicle),'schedules'=>$this->repository->schedules($vehicle),'services'=>$this->repository->services($vehicle),'notice'=>$_SESSION['_maintenance_notice']??null]);
    }

    public function serviceForm(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::OPERATE);if($user instanceof Response)return $user;$vehicleId=$this->id($request->query['vehicle_id']??null);$vehicle=$vehicleId?$this->vehicles->find($vehicleId):null;if($vehicleId!==null&&!$vehicle)return Response::html('Vehicle not found.',404);
        $schedules=array_values(array_filter($this->repository->schedules(),static fn(array $row):bool=>(int)$row['is_active']===1));return $this->render('maintenance-service-form',['user'=>$user,'vehicle'=>$vehicle,'vehicles'=>$this->repository->vehicles(),'schedules'=>$schedules,'service'=>null,'notice'=>null]);
    }

    public function createSchedule(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::CONFIGURE);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);
        try{$this->service->createSchedule($request->form,(int)$user['id']);$_SESSION['_maintenance_notice']='Maintenance schedule created.';}catch(RuntimeException $e){$_SESSION['_maintenance_notice']=$e->getMessage();}return Response::redirect('/maintenance');
    }

    public function updateSchedule(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::CONFIGURE);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);$id=$this->id($request->form['schedule_id']??null);if(!$id)return Response::html('Invalid maintenance schedule.',422);
        try{$this->service->updateSchedule($id,$request->form,(int)$user['id']);$_SESSION['_maintenance_notice']='Maintenance schedule updated.';}catch(RuntimeException $e){$_SESSION['_maintenance_notice']=$e->getMessage();}return Response::redirect('/maintenance');
    }

    public function start(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::OPERATE);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);
        try{$id=$this->service->start($request->form,(int)$user['id']);$_SESSION['_maintenance_notice']='Service started.';return Response::redirect('/maintenance/service?service_id='.$id);}catch(RuntimeException $e){$_SESSION['_maintenance_notice']=$e->getMessage();return Response::redirect('/maintenance');}
    }

    public function complete(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::OPERATE);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);$id=$this->id($request->form['service_id']??null);$mileage=filter_var($request->form['mileage']??null,FILTER_VALIDATE_INT);if(!$id||$mileage===false)return Response::html('Enter a valid service and odometer reading.',422);
        try{$this->service->complete($id,(int)$mileage,(int)$user['id']);$_SESSION['_maintenance_notice']='Service completed.';}catch(RuntimeException $e){$_SESSION['_maintenance_notice']=$e->getMessage();}return Response::redirect('/maintenance/service?service_id='.$id);
    }

    public function cancel(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::OPERATE);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);$id=$this->id($request->form['service_id']??null);if(!$id)return Response::html('Invalid service.',422);
        try{$this->service->cancel($id,(string)($request->form['reason']??''),(int)$user['id']);$_SESSION['_maintenance_notice']='Service cancelled.';}catch(RuntimeException $e){$_SESSION['_maintenance_notice']=$e->getMessage();}return Response::redirect('/maintenance/service?service_id='.$id);
    }

    public function costs(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::OPERATE);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);$id=$this->id($request->form['service_id']??null);if(!$id)return Response::html('Invalid service.',422);
        try{$this->service->updateCosts($id,$request->form,(string)($request->form['reason']??''),(int)$user['id'],in_array($user['role'],['fleet_manager','system_admin'],true));$_SESSION['_maintenance_notice']='Service costs updated and audited.';}catch(RuntimeException $e){$_SESSION['_maintenance_notice']=$e->getMessage();}return Response::redirect('/maintenance/service?service_id='.$id);
    }

    public function review(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::CONFIGURE);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);$id=$this->id($request->form['service_id']??null);if(!$id)return Response::html('Invalid service.',422);
        try{$this->service->resolveReview($id,(string)($request->form['target_status']??''),(string)($request->form['reason']??''),(int)$user['id']);$_SESSION['_maintenance_notice']='Vehicle status review resolved.';}catch(RuntimeException $e){$_SESSION['_maintenance_notice']=$e->getMessage();}return Response::redirect('/maintenance');
    }

    public function photoUpload(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::OPERATE);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);$id=$this->id($request->form['service_id']??null);if(!$id)return Response::html('Invalid service.',422);
        try{$this->service->addPhoto($id,(string)($request->form['phase']??''),$request->files['photo']??[],(int)$user['id']);$_SESSION['_maintenance_notice']='Photo uploaded.';}catch(RuntimeException $e){$_SESSION['_maintenance_notice']=$e->getMessage();}return Response::redirect('/maintenance/service?service_id='.$id);
    }

    public function photo(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::READ);if($user instanceof Response)return $user;$id=$this->id($request->query['photo_id']??null);if(!$id)return Response::html('Photo not found.',404);
        try{$photo=$this->service->streamPhoto($id);return new Response($photo['body'],200,['Content-Type'=>$photo['mime'],'Content-Length'=>(string)strlen($photo['body']),'Content-Disposition'=>'inline; filename="maintenance-evidence"','Cache-Control'=>'private, no-store']);}catch(RuntimeException){return Response::html('Photo not found.',404);}
    }

    public function serviceDetail(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::READ);if($user instanceof Response)return $user;$id=$this->id($request->query['service_id']??null);$service=$id?$this->repository->service($id):null;if(!$service)return Response::html('Maintenance service not found.',404);
        return $this->render('maintenance-service-form',['user'=>$user,'vehicle'=>null,'vehicles'=>[],'schedules'=>[],'service'=>$service,'photos'=>$this->repository->photos($id),'audits'=>$this->repository->costAudits($id),'notice'=>$_SESSION['_maintenance_notice']??null]);
    }

    public function dueReport(): Response
    {
        $user=$this->guard->requireRoles(self::READ);if($user instanceof Response)return $user;try{$defaults=$this->service->defaults();$rows=$this->repository->dueSoon($defaults['days'],$defaults['kilometers']);}catch(RuntimeException $e){return Response::html(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'),500);}
        if(($_GET['format']??'')==='csv'){$lines=[['Plate','Vehicle','Schedule','State','Next due date','Next due mileage','Current mileage','Days window','Kilometer window']];foreach($rows as $r)$lines[]=[$r['plate_number'],trim($r['make'].' '.$r['model']),$r['schedule_name'],$r['due_state'],$r['next_due_date'],$r['next_due_mileage'],$r['current_mileage'],$r['effective_due_soon_days'],$r['effective_due_soon_mileage']];$body='';foreach($lines as $line){$f=fopen('php://temp','r+');fputcsv($f,$line);rewind($f);$body.=stream_get_contents($f);fclose($f);}return new Response($body,200,['Content-Type'=>'text/csv; charset=utf-8','Content-Disposition'=>'attachment; filename="maintenance-due.csv"','Cache-Control'=>'private, no-store']);}
        return $this->render('maintenance-due-report',['user'=>$user,'rows'=>$rows,'defaults'=>$defaults]);
    }

    private function render(string $view,array $data): Response
    {
        $csrfToken=Csrf::token();$e=static fn(mixed $v):string=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');extract($data,EXTR_SKIP);ob_start();require APP_ROOT.'/app/Views/maintenance/'.$view.'.php';return Response::html((string)ob_get_clean());
    }
    private function id(mixed $raw): ?int{$id=filter_var($raw,FILTER_VALIDATE_INT);return $id!==false&&$id!==null&&$id>0?(int)$id:null;}
}
