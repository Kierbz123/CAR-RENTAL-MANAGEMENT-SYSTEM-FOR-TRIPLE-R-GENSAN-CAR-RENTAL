<?php
declare(strict_types=1);

namespace TripleR\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use TripleR\Config;
use TripleR\Repositories\CustomerRepository;
use TripleR\Repositories\ChargeRepository;
use TripleR\Repositories\PaymentRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Support\PaymentMethods;

final class RentalService
{
    public const STATUSES=['reserved','confirmed','active','returned','completed','cancelled','no_show'];
    public const CHARGE_TYPES=['fee','discount','tax','damage','chauffeur_fee','other'];
    /** Share of the rental paid before a reservation can be confirmed. Non-refundable; the rest is paid at pickup. */
    public const DOWNPAYMENT_PERCENT=30;
    /** The sole charge-sign mapping used by every total calculation. */
    private const CHARGE_SIGN=['fee'=>1,'discount'=>-1,'tax'=>1,'damage'=>1,'chauffeur_fee'=>1,'other'=>1];

    public function __construct(private readonly PDO $db,private readonly RentalRepository $rentals,private readonly ChargeRepository $charges,private readonly VehicleRepository $vehicles,private readonly CustomerRepository $customers,private readonly CustomerPiiCipher $customerCipher,private readonly VehicleService $vehicleService,private readonly NotificationService $notifications,private readonly MagicLinkService $magicLinks,private readonly ChauffeurService $chauffeurs,private readonly PaymentRepository $payments) {}

    public function eligibleCustomers(): array { return $this->customers->eligibleForBooking(); }
    public function availableVehicles(): array { return $this->vehicles->availableForBooking(); }

    /** $source is 'online' when the customer made the booking themselves on the public site. */
    public function create(array $input,int $actor,string $source='staff'): int
    {
        $rentalType=(string)($input['rental_type']??'self_drive');
        if(!in_array($rentalType, ['self_drive', 'chauffeur'], true))throw new RuntimeException('Invalid rental type.');
        $customer=$this->positiveId($input['customer_id']??null,'customer');$vehicle=$this->positiveId($input['vehicle_id']??null,'vehicle');
        $vehicleRow = $this->vehicles->find($vehicle);
        if ($rentalType === 'chauffeur' && (!$vehicleRow || $vehicleRow['chauffeur_daily_rate'] === null)) throw new RuntimeException('This vehicle is not available for chauffeur rentals.');
        $start=$this->date((string)($input['start_date']??''),'start date');$end=$this->date((string)($input['end_date']??''),'end date');
        if($end<$start)throw new RuntimeException('Return date must be the same day or after pickup date.');
        $pickup=$this->localDateTime((string)($input['scheduled_pickup_at']??''));$return=$this->localDateTime((string)($input['scheduled_return_at']??''));
        if($pickup===null||$return===null)throw new RuntimeException('Scheduled pickup and return times are required.');
        if((new DateTimeImmutable($pickup,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d')!==$start||(new DateTimeImmutable($return,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d')!==$end)throw new RuntimeException('Scheduled pickup and return times must fall on their selected Manila rental dates.');
        $deposit=$this->money((string)($input['deposit_amount']??'0'),'security deposit');
        if($pickup!==null&&$return!==null&&$return<$pickup)throw new RuntimeException('Scheduled return must not be before scheduled pickup.');
        $id=$this->rentals->createInTransaction(['customer_id'=>$customer,'vehicle_id'=>$vehicle,'rental_type'=>$rentalType,'start_date'=>$start,'end_date'=>$end,'scheduled_pickup_at'=>$pickup,'scheduled_return_at'=>$return,'deposit_amount'=>$deposit,'downpayment_percent'=>self::DOWNPAYMENT_PERCENT,'booking_source'=>$source,'hold_minutes'=>Config::int('RESERVATION_HOLD_MINUTES',1440)],$actor);
        // The reservation is durable even if provider queuing fails; staff can reissue from the detail view.
        try{$phone=$this->primaryCustomerPhone($customer);if($phone!==null){$r=$this->rentals->find($id);$this->magicLinks->issue($phone,null,'booking_manage',$id,new DateTimeImmutable((string)$r['hold_expires_at'],new DateTimeZone('UTC')),(string)$r['booking_reference'],$customer);}}catch(\Throwable $e){error_log('Booking management link could not be queued for agreement '.$id.': '.get_class($e));}
        return $id;
    }

    public function transition(int $id,string $action,int $actor,?string $reason=null,?int $mileage=null,?int $locationId=null): void
    {
        $reason=trim((string)$reason);
        if(in_array($action,['cancel','no_show'],true)&&($reason===''||mb_strlen($reason)>500))throw new RuntimeException('A reason up to 500 characters is required.');
        if(in_array($action,['pickup','return'],true)&&($mileage===null||$mileage<0||$mileage>4294967295))throw new RuntimeException('Enter a valid whole-kilometer odometer reading for pickup and return.');
        if(!in_array($action,['pickup','return'],true)&&$locationId!==null)throw new RuntimeException('A location can only be recorded with pickup or return mileage.');
        $this->db->beginTransaction();
        try{
            $snapshot=$this->rentals->find($id);if(!$snapshot)throw new RuntimeException('Rental agreement not found.');
            $vehicle=$this->rentals->lockVehicle((int)$snapshot['vehicle_id']);if(!$vehicle)throw new RuntimeException('Vehicle not found.');
            $customer=$this->rentals->lockCustomer((int)$snapshot['customer_id']);if(!$customer)throw new RuntimeException('Customer not found.');
            $snapshot=$this->rentals->find($id);if(!$snapshot)throw new RuntimeException('Rental agreement not found.');
            $driver=null;
            if($snapshot['rental_type']==='chauffeur'&&$snapshot['driver_id']!==null)$driver=$this->rentals->lockDriver((int)$snapshot['driver_id']);
            $r=$this->rentals->lockAgreement($id);if(!$r)throw new RuntimeException('Rental agreement not found.');
            if((string)$r['driver_id']!==(string)$snapshot['driver_id'])throw new RuntimeException('The driver assignment changed in another request. Reload and try again.');
            $from=(string)$r['status'];$to=null;$timeColumn=null;
            switch($action){
                case 'confirm': if($from!=='reserved')throw new RuntimeException('Only a reserved agreement can be confirmed.');$to='confirmed';break;
                case 'pickup': if($from!=='confirmed')throw new RuntimeException('Only a confirmed agreement can be picked up.');$to='active';$timeColumn='actual_pickup_at';break;
                case 'return': if($from!=='active')throw new RuntimeException('Only an active agreement can be returned.');$to='returned';$timeColumn='actual_return_at';break;
                case 'complete':
                    if($from!=='returned')throw new RuntimeException('Only a returned agreement can be completed.');
                    if(!in_array($r['deposit_status'],['not_required','released','refunded','forfeited'],true))throw new RuntimeException('Settle the security deposit before completing this agreement.');
                    // An agreement that took a downpayment is finished only when the rest has been received too.
                    if($r['downpayment_status']==='received'&&($owed=$this->outstandingCents($id))>0)throw new RuntimeException('Record the balance of '.$this->pesos($owed).' before completing this agreement.');
                    $to='completed';break;
                case 'cancel': if(!in_array($from,['reserved','confirmed'],true))throw new RuntimeException('Only a reserved or confirmed agreement can be cancelled.');$to='cancelled';break;
                case 'no_show':
                    if(!in_array($from,['reserved','confirmed'],true))throw new RuntimeException('Only a reserved or confirmed agreement can be marked no-show.');
                    if($r['scheduled_pickup_at']===null)throw new RuntimeException('A scheduled pickup time is required before no-show can be recorded.');
                    $grace=max(0,Config::int('NO_SHOW_GRACE_MINUTES',60));$cutoff=(new DateTimeImmutable((string)$r['scheduled_pickup_at'],new DateTimeZone('UTC')))->modify('+'.$grace.' minutes');
                    if(new DateTimeImmutable('now',new DateTimeZone('UTC'))<$cutoff)throw new RuntimeException('No-show can be recorded only after the configured pickup grace period.');
                    $to='no_show';break;
                default: throw new RuntimeException('Unknown rental action.');
            }
            if($action==='confirm') $this->chauffeurs->validateForConfirmation($r,$driver);
            if($action==='confirm'&&$r['downpayment_status']==='due')throw new RuntimeException('Record the '.self::DOWNPAYMENT_PERCENT.'% downpayment before confirming this reservation.');
            // A received downpayment stops the hold clock: the customer has paid, so the vehicle stays theirs.
            if($action==='confirm'&&$r['downpayment_status']!=='received'&&($r['hold_expires_at']===null||new DateTimeImmutable((string)$r['hold_expires_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable('now',new DateTimeZone('UTC'))))throw new RuntimeException('The reservation hold has expired and cannot be confirmed.');
            if($to==='confirmed'&&!in_array($vehicle['current_status'],['available','reserved'],true))throw new RuntimeException('The vehicle is no longer available for confirmation.');
            if($to==='active'&&$vehicle['current_status']!=='reserved')throw new RuntimeException('The vehicle is not in the reserved status required for pickup.');
            if($to==='returned'&&$vehicle['current_status']!=='rented')throw new RuntimeException('The vehicle is not marked rented.');
            if(in_array($action,['pickup','return'],true))$this->vehicleService->recordMileageInTransaction((int)$r['vehicle_id'],(int)$mileage,$locationId,$actor);
            if(!$this->rentals->setStatus($id,$from,$to,in_array($to,['cancelled','no_show'],true)?$reason:null,$actor,$timeColumn))throw new RuntimeException('The agreement changed in another request. Reload and try again.');
            if($to==='confirmed'&&$vehicle['current_status']==='available')$this->vehicleService->transitionStatusInTransaction((int)$r['vehicle_id'],'reserved',$actor);
            if($to==='active')$this->vehicleService->transitionStatusInTransaction((int)$r['vehicle_id'],'rented',$actor);
            if($to==='returned'||(in_array($to,['cancelled','no_show'],true)&&$from==='confirmed'))$this->reconcileVehicleStatus((int)$r['vehicle_id'],$id,$actor);
            if(in_array($to,['completed','cancelled','no_show'],true))$this->invalidateLinks($id);
            if(in_array($to,['cancelled','no_show'],true)) {
                $cList = $this->charges->forAgreement($id);
                foreach ($cList as $cRow) {
                    if ($cRow['charge_type'] === 'chauffeur_fee' && $cRow['entry_kind'] === 'charge' && !$cRow['is_reversed']) {
                        $locked = $this->charges->lockOriginalForReversal($id, (int)$cRow['charge_id']);
                        if ($locked) $this->charges->appendReversal($id, $locked, 'Reversal: Agreement ' . $to, $actor);
                    }
                }
            }
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $template=['confirmed'=>'rental.confirmed','active'=>'rental.pickup','returned'=>'rental.returned'][$to]??null;
        if($template!==null)$this->notifyCustomer($r,$template,$to);
    }

    /** $beforeAppend runs inside the transaction, after the agreement is locked and before the charge is written; throwing cancels the charge. */
    public function addCharge(int $id,string $type,string $amount,string $description,int $actor,?callable $beforeAppend=null,?int $damageDecisionId=null,?string $damageAdjustmentReason=null): int
    {
        if(!in_array($type,self::CHARGE_TYPES,true)||$type==='chauffeur_fee')throw new RuntimeException('Choose a supported charge type.');
        $amount=$this->money($amount,'charge amount');$description=trim($description);if($description===''||mb_strlen($description)>500)throw new RuntimeException('Enter a charge description up to 500 characters.');
        if($this->toCents($amount)<=0)throw new RuntimeException('Charge amount must be greater than zero.');
        $this->db->beginTransaction();try{$r=$this->rentals->find($id,true);if(!$r)throw new RuntimeException('Rental agreement not found.');if(in_array($r['status'],['completed','cancelled','no_show'],true))throw new RuntimeException('Charges are locked for terminal agreements.');if($beforeAppend!==null)$beforeAppend();$this->charges->appendCharge($id,$type,$amount,$description,$actor,$damageDecisionId,$damageAdjustmentReason);$chargeId=(int)$this->db->lastInsertId();if($this->totalCents($id)<0)throw new RuntimeException('Discounts cannot make the rental total negative.');$this->db->commit();return $chargeId;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function reverseCharge(int $id,int $chargeId,string $reason,int $actor): void
    {
        $reason=trim($reason);if($reason===''||mb_strlen($reason)>490)throw new RuntimeException('A reason up to 490 characters is required.');
        $this->db->beginTransaction();try{$agreement=$this->rentals->find($id,true);if(!$agreement)throw new RuntimeException('Rental agreement not found.');if(in_array($agreement['status'],['completed','cancelled','no_show'],true))throw new RuntimeException('Charges are locked for terminal agreements.');$charge=$this->charges->lockOriginalForReversal($id,$chargeId);if(!$charge)throw new RuntimeException('Original charge not found or already reversed.');$this->charges->appendReversal($id,$charge,'Reversal: '.$reason,$actor);$this->db->commit();}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function setDeposit(int $id,string $status,string $amount,string $reason,int $actor): void
    {
        $allowed=['not_required','due','held','released','refunded','forfeited'];if(!in_array($status,$allowed,true))throw new RuntimeException('Choose a valid deposit status.');$amount=$this->money($amount,'deposit amount');$amountCents=$this->toCents($amount);if(($status==='not_required'&&$amountCents!==0)||($status!=='not_required'&&$amountCents<=0))throw new RuntimeException('Deposit amount must be zero only for not_required; all other deposit states require an amount.');$reason=trim($reason);if($reason===''||mb_strlen($reason)>500)throw new RuntimeException('A reason up to 500 characters is required.');
        $this->db->beginTransaction();try{$r=$this->rentals->find((int)$id,true);if(!$r)throw new RuntimeException('Rental agreement not found.');if(in_array($r['status'],['completed','cancelled','no_show'],true))throw new RuntimeException('Deposit is locked for terminal agreements.');$legal=['not_required'=>['due','held'],'due'=>['held','released','refunded','forfeited'],'held'=>['released','refunded','forfeited'],'released'=>[],'refunded'=>[],'forfeited'=>[]];if($status!==$r['deposit_status']&&!in_array($status,$legal[$r['deposit_status']],true))throw new RuntimeException('That deposit transition is not allowed.');$q=$this->db->prepare('UPDATE rental_agreements SET deposit_status=:status,security_deposit_amount=:amount WHERE agreement_id=:id AND deposit_status=:old');$q->execute(['status'=>$status,'amount'=>$amount,'id'=>$id,'old'=>$r['deposit_status']]);if($q->rowCount()!==1)throw new RuntimeException('Deposit changed in another request. Reload and try again.');$this->rentals->appendDeposit($id,$r['deposit_status'],$status,(string)$r['security_deposit_amount'],$amount,$reason,$actor);$this->db->commit();}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    /**
     * Finance records a downpayment received outside the system: cash handed over at the
     * counter, or GCash, Maya, GrabPay, a card or a bank transfer quoted with its reference.
     */
    public function recordDownpayment(int $id,string $reference,int $actor,string $method='gcash'): void
    {
        $this->applyDownpayment($id,$method,$reference,$actor,true,null,null);
    }

    /**
     * Finance accepts a GCash proof the customer submitted while the hold was running. The hold
     * clock no longer matters, and $markProof runs in the same transaction so the proof, the
     * payment and the agreement can never disagree.
     */
    public function recordDownpaymentFromProof(int $id,string $reference,int $actor,callable $markProof,int $proofId): void
    {
        $this->applyDownpayment($id,'gcash',$reference,$actor,false,$markProof,$proofId);
    }

    /** Upper case, no spaces; refused unless it is 6 to 40 letters, digits or dashes. */
    public static function normalizePaymentReference(string $reference,string $label='reference number'): string
    {
        $reference=strtoupper(preg_replace('/\s+/','',$reference)??'');
        if(preg_match('/^[A-Z0-9-]{6,40}$/',$reference)!==1)throw new RuntimeException('Enter the '.$label.': 6 to 40 letters, digits or dashes.');
        return $reference;
    }

    /** The method as staff may record it, with its reference: none for cash, required for everything else. */
    public static function staffPaymentReference(string $method,string $reference): ?string
    {
        $known=PaymentMethods::staff()[$method]??null;
        if($known===null)throw new RuntimeException('Choose how the payment was made.');
        if($known['reference_label']===null){if(trim($reference)!=='')throw new RuntimeException('A cash payment has no reference number. Leave it blank.');return null;}
        return self::normalizePaymentReference($reference,$known['reference_label']);
    }

    private function applyDownpayment(int $id,string $method,string $reference,int $actor,bool $requireLiveHold,?callable $inTransaction,?int $proofId): void
    {
        $reference=self::staffPaymentReference($method,$reference);
        $this->db->beginTransaction();
        try{
            $r=$this->rentals->find($id,true);if(!$r)throw new RuntimeException('Rental agreement not found.');
            if($r['downpayment_status']==='not_required')throw new RuntimeException('No downpayment is required for this agreement.');
            if($r['downpayment_status']==='received')throw new RuntimeException('The downpayment for this agreement has already been recorded.');
            if($r['status']!=='reserved')throw new RuntimeException('A downpayment can only be recorded while the agreement is reserved.');
            if($requireLiveHold&&($r['hold_expires_at']===null||new DateTimeImmutable((string)$r['hold_expires_at'],new DateTimeZone('UTC'))<=new DateTimeImmutable('now',new DateTimeZone('UTC'))))throw new RuntimeException('The reservation hold has run out, so the vehicle is no longer held. Make a new reservation before recording a payment.');
            if($this->payments->hasPending($id))throw new RuntimeException('The customer is paying online right now. Wait for that payment to finish, then reload.');
            if($inTransaction!==null)$inTransaction();
            // The payment first, then the agreement: the database marks a downpayment received only when its payment exists.
            $this->payments->insertStaffPayment(['agreement_id'=>$id,'purpose'=>'downpayment','method'=>$method,'amount'=>(string)$r['downpayment_amount'],'external_reference'=>$reference,'proof_id'=>$proofId,'recorded_by'=>$actor]);
            if(!$this->rentals->markDownpaymentReceived($id))throw new RuntimeException('The downpayment changed in another request. Reload and try again.');
            $this->db->commit();
        }catch(\PDOException $e){
            if($this->db->inTransaction())$this->db->rollBack();
            if((int)($e->errorInfo[1]??0)===1062)throw new RuntimeException(str_contains($e->getMessage(),'uq_payments_external_reference')?'That reference number is already recorded on another agreement.':'The downpayment for this agreement has already been recorded.',0,$e);
            throw $e;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    /** What is still owed, in centavos: the total to bill less every payment received. Never below zero. */
    public function outstandingCents(int $id): int
    {
        return max(0,$this->totalCents($id)-$this->payments->paidCents($id));
    }

    public function total(int $id): string
    {
        $cents=$this->totalCents($id);$absolute=abs($cents);return ($cents<0?'-':'').intdiv($absolute,100).'.'.str_pad((string)($absolute%100),2,'0',STR_PAD_LEFT);
    }

    public function bookingContext(int $id): array
    {
        $r=$this->rentals->find($id);if(!$r)throw new RuntimeException('Booking context unavailable.');
        return ['agreement_id'=>(int)$r['agreement_id'],'booking_reference'=>$r['booking_reference'],'status'=>$r['status'],'start_date'=>$r['start_date'],'end_date'=>$r['end_date'],'pickup_at'=>$r['scheduled_pickup_at'],'return_at'=>$r['scheduled_return_at'],'vehicle'=>trim($r['plate_number'].' '.$r['make'].' '.$r['model']),'base_amount'=>$r['base_amount'],'rental_days'=>(int)$r['rental_days'],'total_amount'=>$this->total($id),'downpayment_amount'=>$r['downpayment_amount'],'downpayment_status'=>$r['downpayment_status'],'balance_at_pickup'=>$this->centsToAmount(max(0,$this->totalCents($id)-$this->toCents((string)$r['downpayment_amount'])-$this->payments->paidCents($id,'balance')))];
    }

    public function issueManagementLink(int $id): void
    {
        $r=$this->rentals->find($id);if(!$r)throw new RuntimeException('Rental agreement not found.');if(in_array($r['status'],['completed','cancelled','no_show'],true))throw new RuntimeException('Management links cannot be issued for terminal agreements.');$phone=$this->primaryCustomerPhone((int)$r['customer_id']);if($phone===null)throw new RuntimeException('The customer has no primary phone contact.');
        $hold=$r['status']==='reserved'&&$r['hold_expires_at']!==null?new DateTimeImmutable((string)$r['hold_expires_at'],new DateTimeZone('UTC')):null;
        $this->magicLinks->issue($phone,null,'booking_manage',(int)$r['agreement_id'],$hold,null,(int)$r['customer_id']);
    }

    public function expireReservation(int $id,int $actor): bool
    {
        $this->db->beginTransaction();try{$snapshot=$this->rentals->find($id);if(!$snapshot){$this->db->commit();return false;}$this->rentals->lockVehicle((int)$snapshot['vehicle_id']);$this->rentals->lockCustomer((int)$snapshot['customer_id']);$r=$this->rentals->lockAgreement($id);if(!$r||$r['status']!=='reserved'||$r['downpayment_status']==='received'||$this->hasProofAwaitingReview($id)||$this->payments->hasPending($id)||$r['hold_expires_at']===null||new DateTimeImmutable((string)$r['hold_expires_at'],new DateTimeZone('UTC'))>new DateTimeImmutable('now',new DateTimeZone('UTC'))){$this->db->commit();return false;}if(!$this->rentals->setStatus($id,'reserved','cancelled','Reservation hold expired',$actor)){$this->db->commit();return false;}$this->invalidateLinks($id);$this->db->commit();return true;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function enqueueDueReminders(): int
    {
        $sql="SELECT agreement_id,customer_id,status FROM rental_agreements WHERE (status='confirmed' AND scheduled_pickup_at BETWEEN UTC_TIMESTAMP(6) AND DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 24 HOUR)) OR (status='active' AND scheduled_return_at BETWEEN UTC_TIMESTAMP(6) AND DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 24 HOUR)) ORDER BY agreement_id LIMIT 500";
        $rows=$this->db->query($sql)->fetchAll();$count=0;foreach($rows as $r){$pickup=$r['status']==='confirmed';$phone=$this->primaryCustomerPhone((int)$r['customer_id']);if($phone===null)continue;$kind=$pickup?'pickup':'return';$text=$pickup?'Reminder: your Triple R Gensan rental pickup is scheduled within 24 hours. Agreement #'.$r['agreement_id'].'.':'Reminder: your Triple R Gensan rental return is scheduled within 24 hours. Agreement #'.$r['agreement_id'].'.';try{$this->notifications->enqueue($phone,'rental.'.$kind.'_reminder',$text,'transactional','normal','rental-'.$r['agreement_id'].'-'.$kind.'-reminder',false,(int)$r['customer_id']);$count++;}catch(\Throwable $e){error_log('Rental reminder could not be queued for agreement '.$r['agreement_id'].': '.get_class($e));}}return $count;
    }

    /** The customer's primary mobile number, or null when they have none. */
    public function customerPhone(int $customerId): ?string { return $this->primaryCustomerPhone($customerId); }
    private function primaryCustomerPhone(int $customerId): ?string
    { foreach($this->customers->contacts($customerId) as $contact)if($contact['contact_type']==='phone'&&(int)$contact['is_primary']===1)return $this->customerCipher->decrypt($contact['contact_ciphertext'],'customer-contact:phone');return null; }
    /** Queues one message to the booking's customer. A queueing problem is logged, never raised. */
    public function notifyBooking(array $r,string $template,string $text,string $idempotencyKey): void
    { try{$phone=$this->primaryCustomerPhone((int)$r['customer_id']);if($phone===null)return;$this->notifications->enqueue($phone,$template,$text,'transactional','normal',$idempotencyKey,false,(int)$r['customer_id']);}catch(\Throwable $e){error_log('Booking message could not be queued for agreement '.$r['agreement_id'].': '.get_class($e));} }
    private function notifyCustomer(array $r,string $template,string $state): void
    { try{$phone=$this->primaryCustomerPhone((int)$r['customer_id']);if($phone===null)return;$text=match($state){'confirmed'=>'Your Triple R Gensan rental is confirmed. Agreement #'.$r['agreement_id'].'.'.$this->downpaymentSentence($r),'active'=>'Your rental pickup has been recorded. Agreement #'.$r['agreement_id'].'.','returned'=>'Your vehicle return has been recorded. Agreement #'.$r['agreement_id'].'.'};$this->notifications->enqueue($phone,$template,$text,'transactional','normal','rental-'.$r['agreement_id'].'-'.$state,false,(int)$r['customer_id']);}catch(\Throwable $e){error_log('Rental lifecycle SMS could not be queued for agreement '.$r['agreement_id'].': '.get_class($e));} }
    /** "Downpayment received: P3,000. Balance of P7,000 is due at pickup." Empty when no downpayment was taken. */
    private function downpaymentSentence(array $r): string
    {
        if(($r['downpayment_status']??'')!=='received')return '';
        $paid=$this->toCents((string)$r['downpayment_amount']);$balance=max(0,$this->totalCents((int)$r['agreement_id'])-$paid);
        return ' Downpayment received: '.$this->pesos($paid).'. Balance of '.$this->pesos($balance).' is due at pickup.';
    }
    /** A customer who has sent their proof, or is on the checkout right now, is not cancelled until that is settled. */
    private function hasProofAwaitingReview(int $id): bool
    { $q=$this->db->prepare("SELECT 1 FROM payment_proofs WHERE agreement_id=:id AND proof_status='submitted' LIMIT 1");$q->execute(['id'=>$id]);return $q->fetchColumn()!==false; }
    private function pesos(int $cents): string { return '₱'.number_format($cents/100,$cents%100===0?0:2); }
    private function centsToAmount(int $cents): string { return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT); }
    private function positiveId(mixed $v,string $name): int { $n=filter_var($v,FILTER_VALIDATE_INT);if($n===false||$n<1)throw new RuntimeException('Choose a valid '.$name.'.');return (int)$n; }
    private function totalCents(int $id): int{$r=$this->rentals->find($id);if(!$r)throw new RuntimeException('Rental agreement not found.');$total=$this->toCents((string)$r['base_amount']);foreach($this->charges->forAgreement($id) as $charge)$total+=$this->toCents((string)$charge['amount'])*self::CHARGE_SIGN[$charge['charge_type']]*($charge['entry_kind']==='reversal'?-1:1);return $total;}
    private function toCents(string $amount): int{[$whole,$fraction]=array_pad(explode('.', $amount,2),2,'0');return ((int)$whole*100)+(int)str_pad(substr($fraction,0,2),2,'0');}
    /** Shared fleet-status reconciliation after a rental releases its vehicle. */
    private function reconcileVehicleStatus(int $vehicleId,int $excludeAgreementId,int $actor): void
    {
        $vehicleQuery=$this->db->prepare('SELECT current_status FROM vehicles WHERE vehicle_id=:id FOR UPDATE');
        $vehicleQuery->execute(['id'=>$vehicleId]);
        $vehicle=$vehicleQuery->fetch();
        if(!$vehicle)throw new RuntimeException('Vehicle not found while reconciling rental status.');
        if(!in_array($vehicle['current_status'],['available','reserved','rented'],true))return;

        $active=$this->db->prepare("SELECT 1 FROM rental_agreements WHERE vehicle_id=:vehicle AND agreement_id<>:id AND status='active' LIMIT 1 FOR UPDATE");
        $active->execute(['vehicle'=>$vehicleId,'id'=>$excludeAgreementId]);
        if($active->fetchColumn()!==false)$target='rented';
        else{
            $confirmed=$this->db->prepare("SELECT 1 FROM rental_agreements WHERE vehicle_id=:vehicle AND agreement_id<>:id AND status='confirmed' AND end_date>=:today LIMIT 1 FOR UPDATE");
            $confirmed->execute(['vehicle'=>$vehicleId,'id'=>$excludeAgreementId,'today'=>(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d')]);
            $target=$confirmed->fetchColumn()!==false?'reserved':'available';
        }
        if($vehicle['current_status']!==$target)$this->vehicleService->transitionStatusInTransaction($vehicleId,$target,$actor);
    }
    private function invalidateLinks(int $id): void { $q=$this->db->prepare('UPDATE booking_access_tokens SET used_at=COALESCE(used_at,UTC_TIMESTAMP(6)),expires_at=LEAST(expires_at,UTC_TIMESTAMP(6)) WHERE booking_id=:id');$q->execute(['id'=>$id]); }
    private function date(string $value,string $label): string { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Asia/Manila'));if(!$d||$d->format('Y-m-d')!==$value)throw new RuntimeException('Enter a valid '.$label.'.');return $value; }
    private function localDateTime(string $value): ?string { if($value==='')return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('Asia/Manila'));if(!$d||$d->format('Y-m-d\TH:i')!==$value)throw new RuntimeException('Enter a valid scheduled pickup and return time.');return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'); }
    private function money(string $value,string $label): string { $value=trim($value);if(!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/',$value))throw new RuntimeException('Enter a valid '.$label.'.');[$a,$b]=array_pad(explode('.',$value,2),2,'');return $a.'.'.str_pad($b,2,'0'); }
}
