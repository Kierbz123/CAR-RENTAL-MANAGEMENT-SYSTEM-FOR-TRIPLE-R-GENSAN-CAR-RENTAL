<?php
declare(strict_types=1);

namespace TripleR\Controllers\Rentals;

use RuntimeException;
use TripleR\Http\AuthMiddleware;
use TripleR\Http\Request;
use TripleR\Http\Response;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\ChargeRepository;
use TripleR\Security\Csrf;
use TripleR\Services\RentalService;
use TripleR\Services\ChauffeurService;
use TripleR\Services\DriverService;
use TripleR\Services\DamageService;

final class AgreementController
{
    private const READ=['system_admin','fleet_manager','front_desk','finance_staff'];
    // The list and detail pages. Driver coordinators assign drivers there (M6); the views show them
    // the schedule and the driver panel only, never charges, deposits or damage records.
    private const VIEW=['system_admin','fleet_manager','front_desk','finance_staff','driver_coordinator'];
    public function __construct(private readonly AuthMiddleware $guard,private readonly RentalRepository $rentals,private readonly ChargeRepository $charges,private readonly RentalService $service, private readonly ChauffeurService $chauffeurs, private readonly DriverService $driverService, private readonly DamageService $damage, private readonly \TripleR\Repositories\PaymentProofRepository $proofs, private readonly \TripleR\Repositories\RulesAcceptanceRepository $rules, private readonly \TripleR\Repositories\PaymentRepository $payments, private readonly \TripleR\Services\PaymentService $paymentService) {}

    public function index(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::VIEW);if($user instanceof Response)return $user;$status=(string)($request->query['status']??'');if($status!==''&&!in_array($status,RentalService::STATUSES,true))$status='';
        return $this->render('rentals/agreements',['user'=>$user,'rows'=>$this->rentals->list(['status'=>$status]),'status'=>$status,'statuses'=>RentalService::STATUSES]);
    }

    public function newForm(): Response
    { 
        $user=$this->guard->requireRoles(['system_admin','front_desk']);if($user instanceof Response)return $user;
        $drivers = $this->driverService->selectableForAssignment();
        return $this->render('rentals/booking-new',['user'=>$user,'customers'=>$this->service->eligibleCustomers(),'vehicles'=>$this->service->availableVehicles(),'drivers'=>$drivers,'error'=>null,'values'=>[]]); 
    }

    public function create(Request $request): Response
    {
        $user=$this->guard->requireRoles(['system_admin','front_desk']);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);
        try{
            $id=$this->service->create($request->form,(int)$user['id']);
            if (($request->form['rental_type'] ?? '') === 'chauffeur' && ($request->form['driver_id'] ?? '') !== '') {
                try {
                    $this->chauffeurs->assignDriver($id, (int)$request->form['driver_id'], (int)$user['id']);
                } catch (RuntimeException $assignEx) {
                    $_SESSION['_rental_notice'] = "Reservation created successfully, but the selected driver could not be assigned (conflict). Please assign a different driver.";
                    return Response::redirect('/rentals/detail?agreement_id='.$id);
                }
            }
            $agreement=$this->rentals->find($id);return $this->render('rentals/reserve',['user'=>$user,'agreement'=>$agreement]);
        }
        catch(RuntimeException $e){
            $start = (string)($request->form['start_date'] ?? '');
            $end = (string)($request->form['end_date'] ?? '');
            $drivers = ($start !== '' && $end !== '') ? $this->driverService->availableForAssignment($start, $end) : $this->driverService->selectableForAssignment();
            return $this->render('rentals/booking-new',['user'=>$user,'customers'=>$this->service->eligibleCustomers(),'vehicles'=>$this->service->availableVehicles(),'drivers'=>$drivers,'error'=>$e->getMessage(),'values'=>$request->form]);
        }
    }

    public function detail(Request $request): Response
    {
        $user=$this->guard->requireRoles(self::VIEW);if($user instanceof Response)return $user;$id=$this->id($request->query['agreement_id']??null);$row=$id?$this->rentals->find($id):null;if(!$row)return Response::html('Rental agreement not found.',404);
        if ($row['rental_type'] === 'chauffeur') {
            if ($row['driver_id'] !== null) {
                // Fetch full name for the assigned driver
                $d = (new \TripleR\Repositories\DriverRepository((new \TripleR\Database())->connection()))->find((int)$row['driver_id'], false, true);
                $row['driver_name'] = $d ? $d['full_name'] : 'Unknown Driver';
            }
        }
        $drivers = $this->driverService->selectableForAssignment((string)$row['end_date']);
        $notice=$_SESSION['_rental_notice']??null;unset($_SESSION['_rental_notice']);return $this->render('rentals/agreement-detail',['user'=>$user,'agreement'=>$row,'charges'=>$this->charges->forAgreement($id),'total'=>$this->service->total($id),'statusHistory'=>$this->rentals->statusHistory($id),'depositHistory'=>$this->rentals->depositHistory($id),'drivers'=>$drivers,'damageReports'=>$this->damage->forAgreement($id),'proofs'=>$this->proofs->forAgreement($id),'policyAcceptance'=>$this->rules->acceptanceForAgreement($id),'payments'=>$this->payments->forAgreement($id),'outstanding'=>$this->service->outstandingCents($id)/100,'notice'=>$notice]);
    }

    public function action(Request $request): Response
    {
        $id=$this->id($request->form['agreement_id']??null);$action=(string)($request->form['action']??'');$roles=match($action){'confirm','cancel','no_show'=>['system_admin','front_desk'],'pickup','return'=>['system_admin','front_desk','fleet_manager'],'complete'=>['system_admin','finance_staff'],default=>[]};
        $user=$this->guard->requireRoles($roles);if($user instanceof Response)return $user;if(!$id)return Response::html('Invalid rental agreement.',422);if(!Csrf::valid($request))return Response::html('Invalid request token.',403);
        $mileage=null;if(in_array($action,['pickup','return'],true)){$parsed=filter_var($request->form['mileage']??null,FILTER_VALIDATE_INT);if($parsed===false||$parsed<0)return Response::html('Enter a valid whole-kilometer odometer reading.',422);$mileage=$parsed;}
        $locationId=null;$rawLocation=trim((string)($request->form['location_id']??''));if($rawLocation!==''){$locationId=$this->id($rawLocation);if(!$locationId)return Response::html('Choose a valid active location.',422);}
        try{$this->service->transition($id,$action,(int)$user['id'],(string)($request->form['reason']??''),$mileage,$locationId);$_SESSION['_rental_notice']='Rental agreement updated.';}catch(RuntimeException $e){$_SESSION['_rental_notice']=$e->getMessage();}return Response::redirect('/rentals/detail?agreement_id='.$id);
    }

    public function addCharge(Request $request): Response
    { $user=$this->guard->requireRoles(['system_admin','finance_staff']);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);$id=$this->id($request->form['agreement_id']??null);if(!$id)return Response::html('Invalid rental agreement.',422);try{$this->service->addCharge($id,(string)($request->form['charge_type']??''),(string)($request->form['amount']??''),(string)($request->form['description']??''),(int)$user['id']);}catch(RuntimeException $e){$_SESSION['_rental_notice']=$e->getMessage();}return Response::redirect('/rentals/detail?agreement_id='.$id); }

    public function reverseCharge(Request $request): Response
    { $user=$this->guard->requireRoles(['system_admin','finance_staff']);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);$id=$this->id($request->form['agreement_id']??null);$charge=$this->id($request->form['charge_id']??null);if(!$id||!$charge)return Response::html('Invalid charge.',422);try{$this->service->reverseCharge($id,$charge,(string)($request->form['reason']??''),(int)$user['id']);}catch(RuntimeException $e){$_SESSION['_rental_notice']=$e->getMessage();}return Response::redirect('/rentals/detail?agreement_id='.$id); }

    public function deposit(Request $request): Response
    { $user=$this->guard->requireRoles(['system_admin','finance_staff']);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);$id=$this->id($request->form['agreement_id']??null);if(!$id)return Response::html('Invalid rental agreement.',422);try{$this->service->setDeposit($id,(string)($request->form['deposit_status']??''),(string)($request->form['amount']??''),(string)($request->form['reason']??''),(int)$user['id']);}catch(RuntimeException $e){$_SESSION['_rental_notice']=$e->getMessage();}return Response::redirect('/rentals/detail?agreement_id='.$id); }

    public function downpayment(Request $request): Response
    { $user=$this->guard->requireRoles(['system_admin','finance_staff']);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);$id=$this->id($request->form['agreement_id']??null);if(!$id)return Response::html('Invalid rental agreement.',422);try{$this->service->recordDownpayment($id,(string)($request->form['reference']??''),(int)$user['id'],(string)($request->form['method']??'gcash'));$_SESSION['_rental_notice']='Downpayment recorded. The reservation can now be confirmed.';}catch(RuntimeException $e){$_SESSION['_rental_notice']=$e->getMessage();}return Response::redirect('/rentals/detail?agreement_id='.$id.'#downpayment'); }

    /** Finance records money received toward the balance, by any method; a blank amount means all that is owed. */
    public function balance(Request $request): Response
    { $user=$this->guard->requireRoles(['system_admin','finance_staff']);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);$id=$this->id($request->form['agreement_id']??null);if(!$id)return Response::html('Invalid rental agreement.',422);try{$receipt=$this->paymentService->recordBalance($id,(string)($request->form['method']??''),(string)($request->form['reference']??''),(string)($request->form['amount']??''),(int)$user['id']);$_SESSION['_rental_notice']='Payment recorded. Receipt '.$receipt.'.';}catch(RuntimeException $e){$_SESSION['_rental_notice']=$e->getMessage();}return Response::redirect('/rentals/detail?agreement_id='.$id.'#payments'); }

    public function issueLink(Request $request): Response
    { $user=$this->guard->requireRoles(['system_admin','front_desk']);if($user instanceof Response)return $user;if(!Csrf::valid($request))return Response::html('Invalid request token.',403);$id=$this->id($request->form['agreement_id']??null);if(!$id)return Response::html('Invalid rental agreement.',422);try{$this->service->issueManagementLink($id);$_SESSION['_rental_notice']='A booking-management link was queued.';}catch(RuntimeException $e){$_SESSION['_rental_notice']=$e->getMessage();}return Response::redirect('/rentals/detail?agreement_id='.$id); }

    private function render(string $view,array $data): Response { $csrfToken=Csrf::token();extract($data,EXTR_SKIP);ob_start();require APP_ROOT.'/app/Views/'.$view.'.php';return Response::html((string)ob_get_clean()); }
    private function id(mixed $value): ?int{$id=filter_var($value,FILTER_VALIDATE_INT);return $id!==false&&$id!==null&&$id>0?(int)$id:null;}
}
