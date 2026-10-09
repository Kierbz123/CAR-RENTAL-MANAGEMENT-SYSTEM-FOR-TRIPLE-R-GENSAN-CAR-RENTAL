<?php
declare(strict_types=1);

namespace TripleR\Controllers\Customers;

use PDOException;
use RuntimeException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\CustomerRepository;
use TripleR\Security\Access;
use TripleR\Security\Csrf;
use TripleR\Support\Pager;
use TripleR\Services\CustomerPiiCipher;
use TripleR\Services\CustomerService;
use TripleR\Services\NotificationService;
use TripleR\Services\ProfilePhotoService;
use TripleR\Services\TelegramLinkService;

final class CustomerController
{
    public function __construct(private readonly AuthMiddleware $guard,private readonly CustomerRepository $customers,private readonly CustomerService $service,private readonly CustomerPiiCipher $cipher,private readonly TelegramLinkService $telegram,private readonly ProfilePhotoService $photos,private readonly NotificationService $notifications) {}

    public function index(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        $type=trim((string)($request->query['type']??'')); if ($type!==''&&!in_array($type,['walk_in','online','corporate','repeat','referral'],true)) $type='';
        $removed=($request->query['show']??'')==='removed'; $search=(string)($request->query['search']??'');
        $total=$this->customers->count($type?:null,$search,$removed); $window=Pager::window($total);
        $rows=$this->customers->list($type?:null,$search,$removed,$window['limit'],$window['offset']);
        foreach ($rows as &$row) $row['has_photo']=$this->photos->has('customers',(int)$row['customer_id']);
        unset($row);
        return $this->render('customers/list',['customers'=>$rows,'total'=>$total,'removed'=>$removed,'type'=>$type,'search'=>(string)($request->query['search']??''),'telegramOn'=>$this->telegram->isConfigured(),'user'=>$user]);
    }

    public function newForm(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        // ?return=reservation: opened from the New reservation form, so saving goes back there.
        return $this->render('customers/form',['customer'=>null,'old'=>['return'=>$request->query['return']??''],'error'=>null,'user'=>$user]);
    }

    public function create(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        try { $id=$this->service->create($request->form,(int)$user['id']); return Response::redirect(($request->form['return']??'')==='reservation'?'/rentals/new?customer_id='.$id:'/customers/detail?customer_id='.$id); }
        // On an error the form comes back with what was typed, so nothing has to be entered twice.
        catch (PDOException $e) { if ($this->isDuplicate($e)) return $this->render('customers/form',['customer'=>null,'old'=>$request->form,'error'=>'A customer with that identity document already exists. If they were removed, find them under Customers, Removed, and restore them instead.','user'=>$user],409); throw $e; }
        catch (RuntimeException $e) { return $this->render('customers/form',['customer'=>null,'old'=>$request->form,'error'=>$e->getMessage(),'user'=>$user],422); }
    }

    public function detail(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['customer_id']??null); $customer=$id?$this->customers->find($id,false,true):null;
        if (!$customer) return Response::html('Customer not found.',404);
        $contacts=$this->customers->contacts($id); foreach ($contacts as &$contact) $contact['masked_value']=$this->service->maskContact($contact); unset($contact);
        $documents=$this->customers->documents($id); foreach ($documents as &$doc) $doc['masked_value']=$this->service->maskDocument($doc); unset($doc);
        $notice=$_SESSION['_customer_notice']??null; unset($_SESSION['_customer_notice']);
        return $this->render('customers/detail',['hasPhoto'=>$this->photos->has('customers',$id),'customer'=>$customer,'contacts'=>$contacts,'documents'=>$documents,'notes'=>$this->customers->notes($id),'documentAudits'=>$this->customers->documentAuditHistory($id),'rentals'=>$this->customers->rentalHistory($id),'sameName'=>$this->customers->sameName($id,(string)$customer['full_name']),'telegram'=>$this->telegramPanel($id),'messageChannel'=>$this->notifications->channelFor($id),'lifecycle'=>$this->service->lifecycleHistory($id),'notice'=>$notice,'user'=>$user]);
    }

    public function editForm(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['customer_id']??null); $customer=$id?$this->customers->find($id):null;
        if (!$customer) return Response::html('Customer not found.',404);
        return $this->render('customers/form',['customer'=>$customer,'error'=>null,'user'=>$user]);
    }

    public function update(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id) return Response::html('Invalid customer.',422);
        try { $this->service->update($id,$request->form); return Response::redirect('/customers/detail?customer_id='.$id); }
        catch (RuntimeException $e) {
            $customer=$this->customers->find($id); if (!$customer) return Response::html('Customer not found.',404);
            $typed=array_intersect_key($request->form,array_flip(['full_name','customer_type','company_name','referral_source']));
            return $this->render('customers/form',['customer'=>array_merge($customer,array_map('strval',$typed)),'error'=>$e->getMessage(),'user'=>$user],422);
        }
    }

    public function addContact(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id) return Response::html('Invalid customer.',422);
        try { $this->service->addContact($id,(string)($request->form['contact_type']??''),(string)($request->form['contact_value']??''),($request->form['is_primary']??'')==='1'); }
        catch (RuntimeException $e) { $_SESSION['_customer_notice']=$e->getMessage(); }
        return Response::redirect('/customers/detail?customer_id='.$id);
    }

    public function updateContact(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); $contact=$this->id($request->form['contact_id']??null); if (!$id||!$contact) return Response::html('Invalid contact.',422);
        try { $this->service->updateContact($id,$contact,(string)($request->form['contact_type']??''),(string)($request->form['contact_value']??''),($request->form['is_primary']??'')==='1'); }
        catch (RuntimeException $e) { $_SESSION['_customer_notice']=$e->getMessage(); }
        return Response::redirect('/customers/detail?customer_id='.$id);
    }

    public function removeContact(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); $contact=$this->id($request->form['contact_id']??null); if (!$id||!$contact) return Response::html('Invalid contact.',422);
        try { $this->service->removeContact($id,$contact); } catch (RuntimeException $e) { $_SESSION['_customer_notice']=$e->getMessage(); }
        return Response::redirect('/customers/detail?customer_id='.$id);
    }

    public function addDocument(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id) return Response::html('Invalid customer.',422);
        try { $this->service->addDocument($id,$request->form,(int)$user['id']); $_SESSION['_customer_notice']='Identity document saved.'; }
        catch (PDOException $e) { if ($this->isDuplicate($e)) $_SESSION['_customer_notice']='That identity document is already associated with a customer. Correct the existing record if it was entered incorrectly.'; else throw $e; }
        catch (RuntimeException $e) { $_SESSION['_customer_notice']=$e->getMessage(); }
        return Response::redirect('/customers/detail?customer_id='.$id);
    }

    public function updateDocument(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); $doc=$this->id($request->form['document_id']??null); if (!$id||!$doc) return Response::html('Invalid identity document.',422);
        try { $this->service->updateDocument($id,$doc,$request->form,(int)$user['id']); $_SESSION['_customer_notice']='Identity document corrected and audit entry recorded.'; }
        catch (PDOException $e) { if ($this->isDuplicate($e)) $_SESSION['_customer_notice']='That identity document is already associated with another customer.'; else throw $e; }
        catch (RuntimeException $e) { $_SESSION['_customer_notice']=$e->getMessage(); }
        return Response::redirect('/customers/detail?customer_id='.$id);
    }

    public function addNote(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id) return Response::html('Invalid customer.',422);
        try { $this->service->addNote($id,(string)($request->form['note_text']??''),(int)$user['id']); $_SESSION['_customer_notice']='Note added.'; }
        catch (RuntimeException $e) { $_SESSION['_customer_notice']=$e->getMessage(); }
        return Response::redirect('/customers/detail?customer_id='.$id);
    }

    public function blacklist(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id) return Response::html('Invalid customer.',422);
        try { $this->service->blacklist($id,(string)($request->form['reason']??''),(int)$user['id']); $_SESSION['_customer_notice']='Customer blacklisted. Existing rentals remain valid.'; }
        catch (RuntimeException $e) { $_SESSION['_customer_notice']=$e->getMessage(); }
        return Response::redirect('/customers/detail?customer_id='.$id);
    }

    public function unblacklist(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id) return Response::html('Invalid customer.',422);
        try { $this->service->unblacklist($id,(string)($request->form['reason']??''),(int)$user['id']); $_SESSION['_customer_notice']='Customer removed from the blacklist.'; }
        catch (RuntimeException $e) { $_SESSION['_customer_notice']=$e->getMessage(); }
        return Response::redirect('/customers/detail?customer_id='.$id);
    }

    public function softDelete(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id) return Response::html('Invalid customer.',422);
        try { $this->service->softDelete($id,(int)$user['id'],(string)($request->form['reason']??'')); return Response::redirect('/customers'); }
        catch (RuntimeException $e) { $_SESSION['_customer_notice']=$e->getMessage(); return Response::redirect('/customers/detail?customer_id='.$id); }
    }

    /** Puts a removed customer back into every list; same roles as removing. */
    public function restore(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id) return Response::html('Invalid customer.',422);
        try { $this->service->restore($id,(int)$user['id'],(string)($request->form['reason']??'')); $_SESSION['_customer_notice']='Customer restored.'; }
        catch (RuntimeException $e) { $_SESSION['_customer_notice']=$e->getMessage(); }
        return Response::redirect('/customers/detail?customer_id='.$id);
    }

    public function reveal(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS,true); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::json(['error'=>'Invalid request token.'],403);
        $customer=$this->id($request->form['customer_id']??null); $record=$this->id($request->form['record_id']??null); $kind=(string)($request->form['kind']??'');
        if (!$customer||!$record||!in_array($kind,['contact','document'],true)) return Response::json(['error'=>'Invalid customer value.'],422);
        try { $value=$kind==='contact'?$this->service->revealContact($customer,$record):$this->service->revealDocument($customer,$record); return Response::json(['value'=>$value]); }
        catch (RuntimeException) { return Response::json(['error'=>'Value not found or unavailable.'],404); }
    }

    /** Creates the one-time code the customer sends to the bot. It is shown on the customer page until it is used or expires. */
    public function telegramCode(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id) return Response::html('Invalid customer.',422);
        try { $_SESSION['_telegram_codes'][$id]=$this->telegram->createCode($id,(int)$user['id']); }
        catch (RuntimeException $e) { $_SESSION['_customer_notice']=$e->getMessage(); }
        return Response::redirect('/customers/detail?customer_id='.$id.'#telegram');
    }

    public function telegramDisconnect(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id) return Response::html('Invalid customer.',422);
        unset($_SESSION['_telegram_codes'][$id]);
        $_SESSION['_customer_notice']=$this->telegram->disconnectByStaff($id,(int)$user['id'])?'Telegram disconnected. This customer\'s messages go by SMS from now on.':'This customer is not connected to Telegram.';
        return Response::redirect('/customers/detail?customer_id='.$id.'#telegram');
    }

    /** The page where staff write to one customer. The message goes to Telegram when they are connected, else by SMS. */
    public function messageForm(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['customer_id']??null); $customer=$id?$this->customers->find($id):null;
        if (!$customer) return Response::html('Customer not found.',404);
        $notice=$_SESSION['_customer_notice']??null; $draft=(string)($_SESSION['_customer_message_draft']??''); unset($_SESSION['_customer_notice'],$_SESSION['_customer_message_draft']);
        // The nonce makes a double click or a resent form queue the message once.
        return $this->render('customers/message',['customer'=>$customer,'channel'=>$this->notifications->channelFor($id),'hasPhone'=>$this->primaryPhone($id)!==null,'messages'=>$this->notifications->directMessages($id),'nonce'=>bin2hex(random_bytes(8)),'draft'=>$draft,'notice'=>$notice,'user'=>$user]);
    }

    public function sendMessage(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id||!$this->customers->find($id)) return Response::html('Customer not found.',404);
        $text=(string)($request->form['message']??''); $nonce=(string)($request->form['nonce']??'');
        try {
            $phone=$this->primaryPhone($id); if ($phone===null) throw new RuntimeException('The message was not sent: this customer has no primary phone number.');
            // The key also records which staff account sent it.
            $channel=$this->notifications->sendDirect($id,$phone,$text,preg_match('/^[a-f0-9]{16}$/',$nonce)===1?'staff-msg-'.$id.'-'.(int)$user['id'].'-'.$nonce:null);
            $_SESSION['_customer_notice']=$channel==='telegram'?'The message is on its way to the customer’s Telegram.':'The message was queued to go by SMS to the customer’s primary phone.';
        }
        // On a refusal the page comes back with what was typed.
        catch (RuntimeException|\DomainException|\InvalidArgumentException $e) { $_SESSION['_customer_notice']=$e->getMessage(); $_SESSION['_customer_message_draft']=mb_substr($text,0,NotificationService::DIRECT_MAX_LENGTH); }
        return Response::redirect('/customers/message?customer_id='.$id);
    }

    public function photo(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['customer_id']??null); $photo=$id?$this->photos->read('customers',$id):null;
        return $photo?Response::binary($photo['body'],$photo['mime']):Response::html('Photo not found.',404);
    }

    /** Adds the customer's photo, or replaces the one already there. */
    public function uploadPhoto(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id||!$this->customers->find($id)) return Response::html('Customer not found.',404);
        try { $this->photos->replace('customers',$id,$request->files['photo']??[]); $_SESSION['_customer_notice']='Photo saved.'; }
        catch (RuntimeException $e) { $_SESSION['_customer_notice']=$e->getMessage(); }
        return Response::redirect('/customers/detail?customer_id='.$id);
    }

    public function removePhoto(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS); if ($user instanceof Response) return $user;
        if (!Csrf::valid($request)) return Response::html('Invalid request token.',403);
        $id=$this->id($request->form['customer_id']??null); if (!$id||!$this->customers->find($id)) return Response::html('Customer not found.',404);
        $this->photos->remove('customers',$id); $_SESSION['_customer_notice']='Photo removed.';
        return Response::redirect('/customers/detail?customer_id='.$id);
    }

    /** Lets the customer page notice, without a reload, that the customer has pressed Start. */
    public function telegramStatus(Request $request): Response
    {
        $user=$this->guard->requireRoles(Access::CUSTOMERS,true); if ($user instanceof Response) return $user;
        $id=$this->id($request->query['customer_id']??null); if (!$id||!$this->customers->find($id,false,true)) return Response::json(['error'=>'Customer not found.'],404);
        return Response::json(['connected'=>$this->telegram->activeLink($id)!==null]);
    }

    /** Connection status plus, while it is still usable, the code this staff member created. */
    private function telegramPanel(int $customerId): array
    {
        $panel=$this->telegram->statusFor($customerId); $pending=$_SESSION['_telegram_codes'][$customerId]??null;
        if (!is_array($pending)||$panel['connected']||$panel['open_code_expires_at']===null||(int)($pending['expires_at']??0)<=time()) { unset($_SESSION['_telegram_codes'][$customerId]); $pending=null; }
        $panel['pending']=$pending; return $panel;
    }

    private function render(string $view,array $data,int $status=200): Response
    {
        $csrfToken=Csrf::token(); $types=['walk_in','online','corporate','repeat','referral']; $documentTypes=['ph_driver_license','passport','national_id','other_government_id']; extract($data,EXTR_SKIP); ob_start(); require APP_ROOT.'/app/Views/'.$view.'.php'; return Response::html((string)ob_get_clean(),$status);
    }
    private function primaryPhone(int $customerId): ?string
    { foreach ($this->customers->contacts($customerId) as $contact) if ($contact['contact_type']==='phone'&&(int)$contact['is_primary']===1) return $this->cipher->decrypt($contact['contact_ciphertext'],'customer-contact:phone'); return null; }
    private function id(mixed $value): ?int { $id=filter_var($value,FILTER_VALIDATE_INT); return $id!==false&&$id!==null&&$id>0?(int)$id:null; }
    private function isDuplicate(PDOException $error): bool { return (int)($error->errorInfo[1]??0)===1062; }
}
