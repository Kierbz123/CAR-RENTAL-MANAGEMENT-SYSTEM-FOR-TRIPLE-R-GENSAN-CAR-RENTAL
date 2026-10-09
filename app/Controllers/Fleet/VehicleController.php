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
use TripleR\Security\Access;
use TripleR\Security\Csrf;
use TripleR\Support\Pager;
use TripleR\Services\VehiclePhotoService;
use TripleR\Services\VehicleService;

final class VehicleController
{
    public function __construct(private readonly AuthMiddleware $guard,private readonly VehicleRepository $vehicles,private readonly VehicleLocationRepository $locations,private readonly VehicleService $service,private readonly VehiclePhotoService $photos,private readonly \TripleR\Services\VehicleTrackingService $tracking) {}

    public function index(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_VIEW); if ($user instanceof Response) return $user;
        $status=(string)($request->query['status']??''); if ($status!=='' && !in_array($status,VehicleService::STATUSES,true)) $status='';
        $search=trim((string)($request->query['search']??''));
        $place=$this->id($request->query['location']??null);
        $filters=['status'=>$status,'search'=>$search,'location'=>$place]; $total=$this->vehicles->count($filters); $window=Pager::window($total);
        return $this->render('fleet/vehicles',['vehicles'=>$this->vehicles->list($filters,$window['limit'],$window['offset']),'total'=>$total,'status'=>$status,'search'=>$search,'place'=>$place,'user'=>$user,'notice'=>null]);
    }

    public function createForm(): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_MANAGE); if ($user instanceof Response) return $user;
        return $this->render('fleet/vehicle-form',['vehicle'=>null,'user'=>$user,'error'=>null]);
    }

    public function editForm(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_MANAGE); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['vehicle_id']??null); $vehicle=$id?$this->vehicles->find($id):null;
        if (!$vehicle) return Response::html('Vehicle not found.',404);
        return $this->render('fleet/vehicle-form',['vehicle'=>$vehicle,'user'=>$user,'error'=>null]);
    }

    public function create(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        try { $data=$this->service->validate($request->form); $id=$this->service->register($data,(int)$user['id']); return Response::redirect('/fleet/vehicles/detail?vehicle_id=' . $id); }
        // On an error the form comes back with what was typed, so nothing has to be entered twice.
        catch (PDOException $e) { if ((int)($e->errorInfo[1]??0)===1062) return $this->render('fleet/vehicle-form',['vehicle'=>null,'old'=>$request->form,'user'=>$user,'error'=>'Plate, engine, or chassis number is already in use.'],409); throw $e; }
        catch (RuntimeException $e) { return $this->render('fleet/vehicle-form',['vehicle'=>null,'old'=>$request->form,'user'=>$user,'error'=>$e->getMessage()],422); }
    }

    public function update(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['vehicle_id']??null); if (!$id) return Response::html('Invalid vehicle.',422);
        try { $data=$this->service->validate($request->form); $this->service->update($id,$data); return Response::redirect('/fleet/vehicles/detail?vehicle_id=' . $id); }
        catch (PDOException $e) { if ((int)($e->errorInfo[1]??0)===1062) return $this->editAgain($id,$request,$user,'Plate, engine, or chassis number is already in use.',409); throw $e; }
        catch (RuntimeException $e) { return $this->editAgain($id,$request,$user,$e->getMessage(),422); }
    }

    /** The edit form again, with the error and the values that were typed. */
    private function editAgain(int $id,Request $request,array $user,string $error,int $status): Response
    {
        $vehicle=$this->vehicles->find($id); if (!$vehicle) return Response::html('Vehicle not found.',404);
        $typed=array_map(static fn(mixed $v): string => is_string($v)?$v:'',array_diff_key($request->form,['_csrf'=>1,'vehicle_id'=>1]));
        return $this->render('fleet/vehicle-form',['vehicle'=>array_merge($vehicle,$typed),'user'=>$user,'error'=>$error],$status);
    }

    public function detail(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_VIEW); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['vehicle_id']??null); $vehicle=$id?$this->vehicles->findIncludingRetired($id):null;
        if (!$vehicle) return Response::html('Vehicle not found.',404);
        // A notice is shown once, on the page that follows the action.
        $notice=$_SESSION['_fleet_notice']??null; unset($_SESSION['_fleet_notice']);
        return $this->render('fleet/vehicle-detail',['vehicle'=>$vehicle,'photos'=>$this->vehicles->photos($id),'statusHistory'=>$this->vehicles->statusHistory($id),'mileageHistory'=>$this->vehicles->mileageHistory($id),'locations'=>$this->locations->selectable(),'user'=>$user,'notice'=>$notice]);
    }

    public function status(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['vehicle_id']??null); if (!$id) return Response::html('Invalid vehicle.',422);
        try { $this->service->transitionStatus($id,(string)($request->form['status']??''),(int)$user['id']); $_SESSION['_fleet_notice']='Vehicle status updated.'; return Response::redirect('/fleet/vehicles/detail?vehicle_id=' . $id); }
        catch (RuntimeException $e) { $_SESSION['_fleet_notice']=$e->getMessage(); return Response::redirect('/fleet/vehicles/detail?vehicle_id=' . $id); }
    }

    public function mileage(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_MANAGE); if ($user instanceof Response) return $user;
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
        $user=$this->guard->requireRoles(Access::FLEET_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['vehicle_id']??null); if (!$id || !$this->vehicles->find($id)) return Response::html('Vehicle not found.',404);
        try { $this->photos->upload($id,$request->files['photo']??[],(int)$user['id']); $_SESSION['_fleet_notice']='Photo uploaded.'; }
        catch (RuntimeException $e) { $_SESSION['_fleet_notice']=$e->getMessage(); }
        return Response::redirect('/fleet/vehicles/detail?vehicle_id=' . $id);
    }

    public function coverPhoto(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['vehicle_id']??null); $photo=$this->id($request->form['photo_id']??null);
        if (!$id || !$photo || !$this->vehicles->find($id)) return Response::html('Vehicle not found.',404);
        try { $this->photos->makeCover($photo,$id); $_SESSION['_fleet_notice']='Cover photo changed.'; }
        catch (RuntimeException $e) { $_SESSION['_fleet_notice']=$e->getMessage(); }
        return Response::redirect('/fleet/vehicles/detail?vehicle_id=' . $id);
    }

    public function photo(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_VIEW); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['photo_id']??null); if (!$id) return Response::html('Photo not found.',404);
        try { $photo=$this->photos->stream($id); return new Response($photo['body'],200,['Content-Type'=>$photo['mime'],'Content-Length'=>(string)strlen($photo['body']),'Content-Disposition'=>'inline; filename="vehicle-photo"','Cache-Control'=>'private, no-store']); }
        catch (RuntimeException) { return Response::html('Photo not found.',404); }
    }

    public function locations(): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_VIEW); if ($user instanceof Response) return $user;
        // The live map's pictures come from the map server named in config/tracking.php; this is the one page allowed to load them.
        $map=$this->tracking->settings();
        $notice=$_SESSION['_location_notice']??null; unset($_SESSION['_location_notice']);
        return $this->render('fleet/locations',['locations'=>$this->locations->all(),'user'=>$user,'notice'=>$notice,'vehiclesOut'=>$this->tracking->feed(),'map'=>$map])->withImagesFrom($map['tiles']['origin']);
    }

    /** Every location action ends back at the list, with one line saying what happened or why it did not. */
    private function locationsWith(string $notice): Response
    {
        $_SESSION['_location_notice']=$notice;
        return Response::redirect('/fleet/locations#all-locations');
    }

    public function createLocation(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $name=trim((string)($request->form['name']??'')); if ($name===''||mb_strlen($name)>120) return $this->locationsWith('Enter a location name up to 120 characters.');
        try { $this->locations->create($name); } catch (PDOException $e) { if ((int)($e->errorInfo[1]??0)===1062) return $this->locationsWith('A location named '.$name.' already exists. If it was removed, choose a different name.'); throw $e; }
        return $this->locationsWith($name.' added.');
    }

    public function renameLocation(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['location_id']??null); $location=$id?$this->locations->find($id):null; if (!$location) return $this->locationsWith('Location not found.');
        $name=trim((string)($request->form['name']??'')); if ($name===''||mb_strlen($name)>120) return $this->locationsWith('Enter a location name up to 120 characters.');
        try { $this->locations->rename($id,$name); } catch (PDOException $e) { if ((int)($e->errorInfo[1]??0)===1062) return $this->locationsWith('Another location is already named '.$name.'.'); throw $e; }
        return $this->locationsWith($location['name'].' is now called '.$name.'.');
    }

    public function retireLocation(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['location_id']??null); if (!$id) return $this->locationsWith('Location not found.');
        try { $this->service->retireLocation($id); } catch (RuntimeException $e) { return $this->locationsWith($e->getMessage()); }
        return $this->locationsWith('Location retired. It can no longer be chosen for new records.');
    }

    public function reactivateLocation(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::FLEET_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['location_id']??null);
        return $this->locationsWith($id && $this->locations->reactivate($id) ? 'Location is in use again.' : 'That location is not retired.');
    }

    public function removeLocation(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::ADMIN); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['location_id']??null); if (!$id) return $this->locationsWith('Location not found.');
        try { $this->locations->remove($id); } catch (PDOException $e) { if (str_contains(strtolower($e->getMessage()),'foreign key')) return $this->locationsWith('A location referenced in fleet history cannot be removed. It stays retired instead.'); throw $e; } catch (RuntimeException $e) { return $this->locationsWith($e->getMessage()); }
        return $this->locationsWith('Location removed.');
    }

    private function render(string $view,array $data,int $httpStatus=200): Response
    {
        // Front desk sees the fleet pages without the controls that change them.
        $canManage=in_array($data['user']['role']??'',Access::FLEET_MANAGE,true);
        $csrfToken=Csrf::token(); $statuses=VehicleService::STATUSES; $locations=$data['locations']??$this->locations->selectable(); $notice=$data['notice']??null; extract($data,EXTR_SKIP); ob_start(); require APP_ROOT . '/app/Views/' . $view . '.php'; return Response::html((string)ob_get_clean(),$httpStatus);
    }
    private function id(mixed $value): ?int { $id=filter_var($value,FILTER_VALIDATE_INT); return $id!==false && $id!==null && $id>0?(int)$id:null; }
}
