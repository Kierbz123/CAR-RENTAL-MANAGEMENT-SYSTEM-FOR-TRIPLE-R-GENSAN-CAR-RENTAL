<?php
declare(strict_types=1);

namespace TripleR\Controllers\Fleet;

use PDOException;
use RuntimeException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\VehicleLocationRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Security\Csrf;
use TripleR\Services\VehiclePhotoService;
use TripleR\Services\VehicleService;

final class VehicleController
{
    private const ROLES=['system_admin','fleet_manager'];
    public function __construct(private readonly AuthMiddleware $guard,private readonly VehicleRepository $vehicles,private readonly VehicleLocationRepository $locations,private readonly VehicleService $service,private readonly VehiclePhotoService $photos) {}

    public function index(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        $status=(string)($request->query['status']??''); if ($status!=='' && !in_array($status,VehicleService::STATUSES,true)) $status='';
        return $this->render('fleet/vehicles',['vehicles'=>$this->vehicles->list(['status'=>$status]),'status'=>$status,'user'=>$user,'notice'=>null]);
    }

    public function createForm(): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        return $this->render('fleet/vehicle-form',['vehicle'=>null,'user'=>$user,'error'=>null]);
    }

    public function editForm(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['vehicle_id']??null); $vehicle=$id?$this->vehicles->find($id):null;
        if (!$vehicle) return Response::html('Vehicle not found.',404);
        return $this->render('fleet/vehicle-form',['vehicle'=>$vehicle,'user'=>$user,'error'=>null]);
    }

    public function create(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        try { $data=$this->service->validate($request->form); $id=$this->service->register($data,(int)$user['id']); return Response::redirect('/fleet/vehicles/detail?vehicle_id=' . $id); }
        catch (PDOException $e) { if ((int)($e->errorInfo[1]??0)===1062) return $this->render('fleet/vehicle-form',['vehicle'=>null,'user'=>$user,'error'=>'Plate, engine, or chassis number is already in use.']); throw $e; }
        catch (RuntimeException $e) { return $this->render('fleet/vehicle-form',['vehicle'=>null,'user'=>$user,'error'=>$e->getMessage()]); }
    }

    public function update(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['vehicle_id']??null); if (!$id) return Response::html('Invalid vehicle.',422);
        try { $data=$this->service->validate($request->form); $this->service->update($id,$data); return Response::redirect('/fleet/vehicles/detail?vehicle_id=' . $id); }
        catch (PDOException $e) { if ((int)($e->errorInfo[1]??0)===1062) return Response::html('Plate, engine, or chassis number is already in use.',409); throw $e; }
        catch (RuntimeException $e) { return Response::html(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'),422); }
    }

    public function detail(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['vehicle_id']??null); $vehicle=$id?$this->vehicles->findIncludingRetired($id):null;
        if (!$vehicle) return Response::html('Vehicle not found.',404);
        return $this->render('fleet/vehicle-detail',['vehicle'=>$vehicle,'photos'=>$this->vehicles->photos($id),'statusHistory'=>$this->vehicles->statusHistory($id),'mileageHistory'=>$this->vehicles->mileageHistory($id),'locations'=>$this->locations->selectable(),'user'=>$user,'notice'=>$_SESSION['_fleet_notice']??null]);
    }

    public function status(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['vehicle_id']??null); if (!$id) return Response::html('Invalid vehicle.',422);
        try { $this->service->transitionStatus($id,(string)($request->form['status']??''),(int)$user['id']); $_SESSION['_fleet_notice']='Vehicle status updated.'; return Response::redirect('/fleet/vehicles/detail?vehicle_id=' . $id); }
        catch (RuntimeException $e) { $_SESSION['_fleet_notice']=$e->getMessage(); return Response::redirect('/fleet/vehicles/detail?vehicle_id=' . $id); }
    }

    public function mileage(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['vehicle_id']??null); $value=filter_var($request->form['mileage']??null,FILTER_VALIDATE_INT);
        $rawLocation=trim((string)($request->form['location_id']??'')); $location=$rawLocation===''?null:$this->id($rawLocation);
        $rawCorrection=trim((string)($request->form['corrects_log_id']??'')); $corrects=$rawCorrection===''?null:$this->id($rawCorrection);
        if (($rawLocation!=='' && $location===null) || ($rawCorrection!=='' && $corrects===null)) return Response::html('Invalid location or mileage-history reference.',422);
        if (!$id || $value===false || $value<0 || $value>4294967295) return Response::html('Enter a non-negative whole-kilometer reading in the supported range.',422);
        try { $reason=trim((string)($request->form['correction_reason']??''))?:null; if ($corrects && $user['role']!=='system_admin') throw new RuntimeException('Only a system administrator may correct a mileage entry.'); $this->service->recordMileage($id,$value,$location,(int)$user['id'],$corrects,$reason); $_SESSION['_fleet_notice']=$corrects?'Mileage correction recorded.':'Mileage reading recorded.'; }
        catch (RuntimeException $e) { $_SESSION['_fleet_notice']=$e->getMessage(); }
        return Response::redirect('/fleet/vehicles/detail?vehicle_id=' . $id);
    }

    public function uploadPhoto(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['vehicle_id']??null); if (!$id || !$this->vehicles->find($id)) return Response::html('Vehicle not found.',404);
        try { $this->photos->upload($id,$request->files['photo']??[],(int)$user['id']); $_SESSION['_fleet_notice']='Photo uploaded.'; }
        catch (RuntimeException $e) { $_SESSION['_fleet_notice']=$e->getMessage(); }
        return Response::redirect('/fleet/vehicles/detail?vehicle_id=' . $id);
    }

    public function photo(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['photo_id']??null); if (!$id) return Response::html('Photo not found.',404);
        try { $photo=$this->photos->stream($id); return new Response($photo['body'],200,['Content-Type'=>$photo['mime'],'Content-Length'=>(string)strlen($photo['body']),'Content-Disposition'=>'inline; filename="vehicle-photo"','Cache-Control'=>'private, no-store']); }
        catch (RuntimeException) { return Response::html('Photo not found.',404); }
    }

    public function locations(): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        return $this->render('fleet/locations',['locations'=>$this->locations->all(),'user'=>$user,'notice'=>null]);
    }

    public function createLocation(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $name=trim((string)($request->form['name']??'')); if ($name===''||mb_strlen($name)>120) return Response::html('Enter a location name up to 120 characters.',422);
        try { $this->locations->create($name); } catch (PDOException $e) { if ((int)($e->errorInfo[1]??0)===1062) return Response::html('That location already exists.',409); throw $e; }
        return Response::redirect('/fleet/locations');
    }

    public function retireLocation(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::ROLES); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['location_id']??null); if (!$id) return Response::html('Invalid location.',422);
        try { $this->service->retireLocation($id); } catch (RuntimeException $e) { return Response::html($e->getMessage(),404); }
        return Response::redirect('/fleet/locations');
    }

    public function removeLocation(Request $request): Response
    {
        $user=$this->guard->requireRoles(['system_admin']); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['location_id']??null); if (!$id) return Response::html('Invalid location.',422);
        try { $this->locations->remove($id); } catch (PDOException $e) { if (str_contains(strtolower($e->getMessage()),'foreign key')) return Response::html('A location referenced in fleet history cannot be removed.',409); throw $e; } catch (RuntimeException $e) { return Response::html($e->getMessage(),409); }
        return Response::redirect('/fleet/locations');
    }

    private function render(string $view,array $data): Response
    {
        $csrfToken=Csrf::token(); $statuses=VehicleService::STATUSES; $locations=$this->locations->selectable(); $notice=$data['notice']??null; extract($data,EXTR_SKIP); ob_start(); require APP_ROOT . '/app/Views/' . $view . '.php'; return Response::html((string)ob_get_clean());
    }
    private function id(mixed $value): ?int { $id=filter_var($value,FILTER_VALIDATE_INT); return $id!==false && $id!==null && $id>0?(int)$id:null; }
}
