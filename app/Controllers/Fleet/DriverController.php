<?php
declare(strict_types=1);

namespace TripleR\Controllers\Fleet;

use PDOException;
use RuntimeException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\DriverRepository;
use TripleR\Repositories\StaffUserRepository;
use TripleR\Security\Access;
use TripleR\Security\Csrf;
use TripleR\Support\Format;
use TripleR\Support\Pager;
use TripleR\Services\DriverPiiCipher;
use TripleR\Services\DriverService;
use TripleR\Services\ProfilePhotoService;

final class DriverController
{
    public const UNREADABLE = 'Unreadable';

    public function __construct(private readonly AuthMiddleware $guard, private readonly DriverRepository $drivers, private readonly DriverService $service, private readonly DriverPiiCipher $cipher, private readonly ProfilePhotoService $photos, private readonly StaffUserRepository $users) {}

    public function index(Request $request): Response
    {
        $user = $this->guard->requireRoles(Access::DRIVERS_VIEW);
        if ($user instanceof Response) return $user;
        $canManage = in_array($user['role'],Access::DRIVERS_MANAGE,true);
        $canReveal = in_array($user['role'],Access::DRIVER_REVEAL_ALL,true);
        $today = Format::today();
        $query = $request->query;
        $removed = ($query['show'] ?? '') === 'removed';
        $search = trim((string)($query['search'] ?? ''));
        $state = !$removed && in_array($query['state'] ?? '', DriverRepository::AVAILABILITY, true) ? (string)$query['state'] : '';
        $sort = in_array($query['sort'] ?? '', ['expiry','trips'], true) ? (string)$query['sort'] : 'name';
        // "Free between": one date alone means that day; dates the wrong way round are read as one day.
        $freeFrom = $removed ? null : $this->date($query['free_from'] ?? null);
        $freeTo = $freeFrom === null ? null : max($freeFrom, $this->date($query['free_to'] ?? null) ?? $freeFrom);
        // A whole licence number finds its driver through the stored hash; the number itself stays encrypted.
        $fingerprint = null;
        if ($canReveal && preg_match('/\d/', $search) === 1) { try { $fingerprint = $this->cipher->licenseFingerprint(DriverPiiCipher::normalizeLicense($search)); } catch (RuntimeException) {} }
        $filters = ['removed'=>$removed,'search'=>$search,'license_fingerprint'=>$fingerprint,'state'=>$state,'free_from'=>$freeFrom,'free_to'=>$freeTo];
        $total = $this->drivers->rosterCount($filters, $today);
        $window = Pager::window($total);
        $rows = $this->drivers->roster($filters, $today, $window['limit'], $window['offset'], $sort);
        $ids = array_map(static fn (array $row): int => (int) $row['driver_id'], $rows);
        $bookings = $this->drivers->openBookings($ids);
        $phones = $this->drivers->mainPhones($ids);
        foreach ($rows as &$row) {
            $id = (int) $row['driver_id'];
            $row['license_display'] = $canReveal ? $this->maskedOrUnreadable(fn (): string => '****' . substr(DriverPiiCipher::normalizeLicense($this->cipher->decrypt($row['license_number_ciphertext'],'driver-license')),-4)) : null;
            $row['phone_display'] = isset($phones[$id]) ? $this->maskedOrUnreadable(fn (): string => $this->service->masked($phones[$id],'contact','phone')) : null;
            $row['bookings'] = $bookings[$id] ?? [];
            $row['has_photo'] = $this->photos->has('drivers', $id);
        }
        unset($row);
        return $this->render('drivers/list',['drivers'=>$rows,'tally'=>$this->drivers->rosterTally($today),'today'=>$today,'search'=>$search,'state'=>$state,'sort'=>$sort,'freeFrom'=>$freeFrom,'freeTo'=>$freeTo,'user'=>$user,'canManage'=>$canManage,'canReveal'=>$canReveal,'canAccount'=>in_array($user['role'],Access::ADMIN,true),'removed'=>$removed,'total'=>$total]);
    }

    public function newForm(): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_MANAGE); if ($user instanceof Response) return $user;
        return $this->render('drivers/form',['driver'=>null,'error'=>null,'user'=>$user]);
    }

    public function create(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        try { $id=$this->service->create($request->form,(int)$user['id']); $this->warnIfExpired($request->form); return Response::redirect('/fleet/drivers/detail?driver_id='.$id); }
        catch (PDOException $error) { if ($this->duplicate($error)) return $this->render('drivers/form',['driver'=>null,'error'=>'That driver license is already recorded. If the driver was removed, find them under Drivers, Removed, and restore them instead.','user'=>$user]); throw $error; }
        catch (RuntimeException $error) { return $this->render('drivers/form',['driver'=>null,'error'=>$error->getMessage(),'user'=>$user]); }
    }

    public function editForm(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_MANAGE); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['driver_id']??null); $driver=$id?$this->drivers->find($id):null;
        if (!$driver) return Response::html('Driver not found.',404);
        return $this->render('drivers/form',['driver'=>$driver,'error'=>null,'user'=>$user]);
    }

    public function update(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); if (!$id) return Response::html('Invalid driver.',422);
        try { $this->service->update($id,$request->form); $_SESSION['_driver_notice']='Driver record updated.'; $this->warnIfExpired($request->form); }
        catch (PDOException $error) { if ($this->duplicate($error)) $_SESSION['_driver_notice']='That driver license is already recorded. If the driver was removed, find them under Drivers, Removed, and restore them instead.'; else throw $error; }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); }
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    public function detail(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_VIEW); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['driver_id']??null); $driver=$id?$this->drivers->find($id,false,true):null;
        if (!$driver) return Response::html('Driver not found.',404);
        $canManage=in_array($user['role'],Access::DRIVERS_MANAGE,true);
        $canReveal=in_array($user['role'],Access::DRIVER_REVEAL_ALL,true);
        // Sign-in accounts are the administrator's to create, so only they see the driver's.
        $canAccount=in_array($user['role'],Access::ADMIN,true);
        $contacts=$this->drivers->contacts($id);
        // Front desk needs to call a driver, so a phone number is theirs to reveal; everything else is not.
        foreach ($contacts as &$contact) { $contact['can_reveal']=$canReveal||$contact['contact_type']==='phone'; $contact['display']=$contact['can_reveal']?$this->maskedOrUnreadable(fn (): string => $this->service->masked($contact['contact_ciphertext'],'contact',$contact['contact_type'])):'Restricted'; }
        unset($contact);
        $pii=[];
        if ($canReveal) {
            $pii['license']=$this->maskedOrUnreadable(fn (): string => '****'.substr(DriverPiiCipher::normalizeLicense($this->cipher->decrypt($driver['license_number_ciphertext'],'driver-license')),-4));
            $pii['address']=$driver['address_ciphertext']===null?'Not recorded':'Address on file';
            $pii['emergency_name']=$driver['emergency_contact_name_ciphertext']===null?'Not recorded':'Name on file';
            $pii['emergency_phone']=$driver['emergency_contact_phone_ciphertext']===null?'Not recorded':$this->maskedOrUnreadable(fn (): string => '****'.substr(preg_replace('/\W/','',$this->cipher->decrypt($driver['emergency_contact_phone_ciphertext'],'driver-emergency-phone')),-4));
        } else {
            $pii=['license'=>'Restricted','address'=>'Restricted','emergency_name'=>'Restricted','emergency_phone'=>'Restricted'];
        }
        $notice=$_SESSION['_driver_notice']??null; unset($_SESSION['_driver_notice']);
        return $this->render('drivers/detail',['driver'=>$driver,'contacts'=>$contacts,'statusHistory'=>$this->drivers->statusHistory($id),'assignments'=>$this->drivers->assignmentHistory($id),'pii'=>$pii,'hasPhoto'=>$this->photos->has('drivers',$id),'user'=>$user,'canManage'=>$canManage,'canReveal'=>$canReveal,'canAccount'=>$canAccount,'account'=>$canAccount?$this->users->accountForDriver($id):null,'lifecycle'=>$this->service->lifecycleHistory($id),'notice'=>$notice]);
    }

    public function status(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); if (!$id) return Response::html('Invalid driver.',422);
        try { $this->service->changeStatus($id,(string)($request->form['status']??''),(int)$user['id']); $_SESSION['_driver_notice']='Driver status updated.'; }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); }
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    public function delete(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); if (!$id) return Response::html('Invalid driver.',422);
        try { $this->service->softDelete($id,(int)$user['id'],(string)($request->form['reason']??'')); return Response::redirect('/fleet/drivers'); }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); return Response::redirect('/fleet/drivers/detail?driver_id='.$id); }
    }

    public function addContact(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); if (!$id) return Response::html('Invalid driver.',422);
        try { $this->service->addContact($id,(string)($request->form['contact_type']??''),(string)($request->form['contact_value']??''),($request->form['is_primary']??'')==='1'); $_SESSION['_driver_notice']='Contact added.'; }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); }
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    public function updateContact(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); $contact=$this->id($request->form['contact_id']??null); if (!$id||!$contact) return Response::html('Invalid contact.',422);
        try { $this->service->updateContact($id,$contact,(string)($request->form['contact_type']??''),(string)($request->form['contact_value']??''),($request->form['is_primary']??'')==='1'); $_SESSION['_driver_notice']='Contact updated.'; }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); }
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    public function removeContact(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); $contact=$this->id($request->form['contact_id']??null); if (!$id||!$contact) return Response::html('Invalid contact.',422);
        try { $this->service->removeContact($id,$contact); $_SESSION['_driver_notice']='Contact removed.'; }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); }
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    public function photo(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_VIEW); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['driver_id']??null); $photo=$id?$this->photos->read('drivers',$id):null;
        return $photo?Response::binary($photo['body'],$photo['mime']):Response::html('Photo not found.',404);
    }

    /** Adds the driver's photo, or replaces the one already there. */
    public function uploadPhoto(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); if (!$id || !$this->drivers->find($id)) return Response::html('Driver not found.',404);
        try { $this->photos->replace('drivers',$id,$request->files['photo']??[]); $_SESSION['_driver_notice']='Photo saved.'; }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); }
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    public function removePhoto(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); if (!$id || !$this->drivers->find($id)) return Response::html('Driver not found.',404);
        $this->photos->remove('drivers',$id); $_SESSION['_driver_notice']='Photo removed.';
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    /** Puts a removed driver back into every list; same roles as removing. */
    public function restore(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_MANAGE); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['driver_id']??null); if (!$id) return Response::html('Invalid driver.',422);
        try { $this->service->restore($id,(int)$user['id'],(string)($request->form['reason']??'')); $_SESSION['_driver_notice']='Driver restored.'; }
        catch (RuntimeException $error) { $_SESSION['_driver_notice']=$error->getMessage(); }
        return Response::redirect('/fleet/drivers/detail?driver_id='.$id);
    }

    public function reveal(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::DRIVERS_VIEW,true); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::json(['error'=>'Invalid request token.'],403);
        $id=$this->id($request->form['driver_id']??null); $record=$this->id($request->form['record_id']??null); $kind=(string)($request->form['kind']??'');
        if (!$id || !in_array($kind,['license','address','emergency_name','emergency_phone','contact'],true)) return Response::json(['error'=>'Invalid driver value.'],422);
        // Outside fleet management only a driver's phone number may be revealed.
        if (!in_array($user['role'],Access::DRIVER_REVEAL_ALL,true) && ($kind!=='contact' || !$record || ($this->drivers->findContact($record,$id)['contact_type']??'')!=='phone')) return Response::json(['error'=>'This account is not authorized for this action.'],403);
        try { return Response::json(['value'=>$this->service->reveal($id,$kind,$record)]); }
        catch (RuntimeException) { return Response::json(['error'=>'Value not found or unavailable.'],404); }
    }

    private function render(string $view,array $data): Response
    {
        $csrfToken=Csrf::token(); extract($data,EXTR_SKIP); ob_start(); require APP_ROOT.'/app/Views/'.$view.'.php'; return Response::html((string)ob_get_clean());
    }
    /**
     * One record that cannot be decrypted (a wrong key, or a row written outside the application)
     * must not take the whole page down. Show it as unreadable so staff can re-enter the value.
     */
    private function maskedOrUnreadable(callable $masked): string
    {
        try { return $masked(); }
        catch (RuntimeException) { return self::UNREADABLE; }
    }
    private function id(mixed $value): ?int { $id=filter_var($value,FILTER_VALIDATE_INT); return $id!==false&&$id!==null&&$id>0?(int)$id:null; }
    /** A date as the date fields send it (2026-10-08), or null. */
    private function date(mixed $value): ?string { $text=is_string($value)?trim($value):''; $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$text); return $date!==false&&$date->format('Y-m-d')===$text?$text:null; }
    /** A lapsed licence may be recorded (a renewal can be pending), but the person saving it is told what it means. */
    private function warnIfExpired(array $form): void
    {
        $expiry=$this->date($form['license_expiry']??null);
        if ($expiry!==null && $expiry<Format::today()) $_SESSION['_driver_notice']='Saved. The licence expired on '.Format::date($expiry).', so this driver cannot be given a booking until it is renewed.';
    }
    private function duplicate(PDOException $error): bool { return (int)($error->errorInfo[1]??0)===1062; }
}
