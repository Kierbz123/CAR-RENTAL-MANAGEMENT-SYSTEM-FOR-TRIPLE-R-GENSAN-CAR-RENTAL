<?php
declare(strict_types=1);

namespace TripleR\Controllers\Fleet;

use PDOException;
use RuntimeException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\DriverRepository;
use TripleR\Security\Csrf;
use TripleR\Services\DriverPiiCipher;
use TripleR\Services\DriverService;

final class DriverController
{
    private const MANAGERS = ['system_admin','fleet_manager'];
    private const READERS = ['system_admin','fleet_manager','driver_coordinator'];

    public function __construct(private readonly AuthMiddleware $guard, private readonly DriverRepository $drivers, private readonly DriverService $service, private readonly DriverPiiCipher $cipher) {}

    public function index(Request $request): Response
    {
        $user = $this->guard->requireRoles(self::READERS);
        if ($user instanceof Response) return $user;
        $canManage = in_array($user['role'],self::MANAGERS,true);
        $rows = $this->drivers->list((string)($request->query['search'] ?? ''));
        if ($canManage) foreach ($rows as &$row) $row['license_display'] = '****' . substr(DriverPiiCipher::normalizeLicense($this->cipher->decrypt($row['license_number_ciphertext'],'driver-license')),-4);
        else foreach ($rows as &$row) $row['license_display'] = 'Restricted';
        unset($row);
        return $this->render('drivers/list',['drivers'=>$rows,'eligibleDrivers'=>$this->service->selectableForAssignment(),'search'=>(string)($request->query['search']??''),'user'=>$user,'canManage'=>$canManage]);
    }

    public function newForm(): Response
    {
        $user=$this->guard->requireRoles(self::MANAGERS); if ($user instanceof Response) return $user;
        return $this->render('drivers/form',['driver'=>null,'error'=>null,'user'=>$user]);
    }

    public function create(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::MANAGERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        try { $id=$this->service->create($request->form,(int)$user['id']); return Response::redirect('/fleet/drivers/detail?driver_id='.$id); }
        catch (PDOException $error) { if ($this->duplicate($error)) return $this->render('drivers/form',['driver'=>null,'error'=>'That driver license is already recorded.','user'=>$user]); throw $error; }
        catch (RuntimeException $error) { return $this->render('drivers/form',['driver'=>null,'error'=>$error->getMessage(),'user'=>$user]); }
    }

    public function editForm(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::MANAGERS); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['driver_id']??null); $driver=$id?$this->drivers->find($id):null;
        if (!$driver) return Response::html('Driver not found.',404);
        return $this->render('drivers/form',['driver'=>$driver,'error'=>null,'user'=>$user]);
    }

    public function update(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::MANAGERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); if (!$id) return Response::html('Invalid driver.',422);
        try { $this->service->update($id,$request->form); $_SESSION['_driver_notice']='Driver record updated.'; }
        catch (PDOException $error) { if ($this->duplicate($error)) $_SESSION['_driver_notice']='That driver license is already recorded.'; else throw $error; }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); }
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    public function detail(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::READERS); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['driver_id']??null); $driver=$id?$this->drivers->find($id,false,true):null;
        if (!$driver) return Response::html('Driver not found.',404);
        $canManage=in_array($user['role'],self::MANAGERS,true);
        $contacts=$this->drivers->contacts($id);
        foreach ($contacts as &$contact) $contact['display']=$canManage?$this->service->masked($contact['contact_ciphertext'],'contact',$contact['contact_type']):'Restricted';
        unset($contact);
        $pii=[];
        if ($canManage) {
            $pii['license']='****'.substr(DriverPiiCipher::normalizeLicense($this->cipher->decrypt($driver['license_number_ciphertext'],'driver-license')),-4);
            $pii['address']=$driver['address_ciphertext']===null?'Not recorded':'Address on file';
            $pii['emergency_name']=$driver['emergency_contact_name_ciphertext']===null?'Not recorded':'Name on file';
            $pii['emergency_phone']=$driver['emergency_contact_phone_ciphertext']===null?'Not recorded':'****'.substr(preg_replace('/\W/','',$this->cipher->decrypt($driver['emergency_contact_phone_ciphertext'],'driver-emergency-phone')),-4);
        } else {
            $pii=['license'=>'Restricted','address'=>'Restricted','emergency_name'=>'Restricted','emergency_phone'=>'Restricted'];
        }
        $notice=$_SESSION['_driver_notice']??null; unset($_SESSION['_driver_notice']);
        return $this->render('drivers/detail',['driver'=>$driver,'contacts'=>$contacts,'statusHistory'=>$this->drivers->statusHistory($id),'assignments'=>$this->drivers->assignmentHistory($id),'pii'=>$pii,'user'=>$user,'canManage'=>$canManage,'notice'=>$notice]);
    }

    public function status(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::MANAGERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); if (!$id) return Response::html('Invalid driver.',422);
        try { $this->service->changeStatus($id,(string)($request->form['status']??''),(int)$user['id']); $_SESSION['_driver_notice']='Driver status updated.'; }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); }
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    public function delete(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::MANAGERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); if (!$id) return Response::html('Invalid driver.',422);
        try { $this->service->softDelete($id); return Response::redirect('/fleet/drivers'); }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); return Response::redirect('/fleet/drivers/detail?driver_id='.$id); }
    }

    public function addContact(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::MANAGERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); if (!$id) return Response::html('Invalid driver.',422);
        try { $this->service->addContact($id,(string)($request->form['contact_type']??''),(string)($request->form['contact_value']??''),($request->form['is_primary']??'')==='1'); $_SESSION['_driver_notice']='Contact added.'; }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); }
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    public function updateContact(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::MANAGERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); $contact=$this->id($request->form['contact_id']??null); if (!$id||!$contact) return Response::html('Invalid contact.',422);
        try { $this->service->updateContact($id,$contact,(string)($request->form['contact_type']??''),(string)($request->form['contact_value']??''),($request->form['is_primary']??'')==='1'); $_SESSION['_driver_notice']='Contact updated.'; }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); }
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    public function removeContact(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::MANAGERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); $contact=$this->id($request->form['contact_id']??null); if (!$id||!$contact) return Response::html('Invalid contact.',422);
        try { $this->service->removeContact($id,$contact); $_SESSION['_driver_notice']='Contact removed.'; }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); }
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    public function reveal(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::MANAGERS,true); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::json(['error'=>'Invalid request token.'],403);
        $id=$this->id($request->form['driver_id']??null); $record=$this->id($request->form['record_id']??null); $kind=(string)($request->form['kind']??'');
        if (!$id || !in_array($kind,['license','address','emergency_name','emergency_phone','contact'],true)) return Response::json(['error'=>'Invalid driver value.'],422);
        try { return Response::json(['value'=>$this->service->reveal($id,$kind,$record)]); }
        catch (RuntimeException) { return Response::json(['error'=>'Value not found or unavailable.'],404); }
    }

    private function render(string $view,array $data): Response
    {
        $csrfToken=Csrf::token(); extract($data,EXTR_SKIP); ob_start(); require APP_ROOT.'/app/Views/'.$view.'.php'; return Response::html((string)ob_get_clean());
    }
    private function id(mixed $value): ?int { $id=filter_var($value,FILTER_VALIDATE_INT); return $id!==false&&$id!==null&&$id>0?(int)$id:null; }
    private function duplicate(PDOException $error): bool { return (int)($error->errorInfo[1]??0)===1062; }
}
