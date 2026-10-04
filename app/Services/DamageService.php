<?php
declare(strict_types=1);

namespace TripleR\Services;

use PDO;
use RuntimeException;
use TripleR\Repositories\DamageReportRepository;
use TripleR\Repositories\RentalRepository;
use TripleR\Repositories\VehicleRepository;
use TripleR\Repositories\VehicleStatusLogRepository;

final class DamageService
{
    private const PHASES=['pre','during','post'];
    private const SEVERITIES=['minor','moderate','severe'];

    private readonly VehicleService $vehicles;
    /** The status the vehicle was moved to by the last record() call, or null when it was left alone. */
    private ?string $vehicleHeldAs = null;

    public function __construct(private readonly PDO $db,private readonly DamageReportRepository $reports,private readonly RentalRepository $rentals,private readonly VehiclePhotoService $photos,private readonly RentalService $rentalService,?VehicleService $vehicles=null)
    {
        $this->vehicles=$vehicles??new VehicleService($db,new VehicleRepository($db),new VehicleStatusLogRepository($db));
    }

    public function vehicleHeldAs(): ?string { return $this->vehicleHeldAs; }

    public function forAgreement(int $id): array { return $this->reports->forAgreement($id); }
    public function detail(int $reportId): ?array { return $this->reports->detail($reportId); }

    /** Facts and evidence are immutable; optional estimates are entered as a suggested amount for later review. */
    public function record(int $agreementId,string $phase,bool $hasDamage,array $input,array $uploads,int $actor): int
    {
        if(!in_array($phase,self::PHASES,true))throw new RuntimeException('Choose a valid inspection phase.');
        $location=trim((string)($input['location']??''));$type=trim((string)($input['damage_type']??''));$severity=(string)($input['severity']??'');$notes=trim((string)($input['notes']??''));
        $suggestion=trim((string)($input['repair_cost_suggestion']??''));
        if($hasDamage){if($location===''||mb_strlen($location)>120||$type===''||mb_strlen($type)>40||!in_array($severity,self::SEVERITIES,true))throw new RuntimeException('Damage location, type, and severity are required.');}
        else{$location=$type='';$severity='';$suggestion='';}
        if($notes!==''&&mb_strlen($notes)>1000)throw new RuntimeException('Notes must be 1,000 characters or fewer.');
        if($suggestion!==''&&!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/',$suggestion))throw new RuntimeException('Enter a valid repair cost suggestion.');
        $suggestion=$suggestion===''?null:$this->money($suggestion);
        $uploads=array_values(array_filter($uploads,static fn($f)=>is_array($f)&&($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE));
        if($hasDamage&&in_array($phase,['during','post'],true)&&count($uploads)<1)throw new RuntimeException('At least one photo is required when damage is recorded during or after the rental.');
        if(count($uploads)>8)throw new RuntimeException('Upload no more than 8 photos per report.');

        $this->db->beginTransaction();$stored=[];
        try{
            $agreement=$this->lockAgreementChain($agreementId);
            $valid=match($phase){'pre'=>$agreement['status']==='confirmed','during'=>$agreement['status']==='active','post'=>in_array($agreement['status'],['returned','completed'],true)};
            if(!$valid)throw new RuntimeException('This inspection phase is not available for the agreement’s current status.');
            $reportId=$this->reports->appendReport($agreementId,$phase,$hasDamage,$hasDamage?$location:null,$hasDamage?$type:null,$hasDamage?$severity:null,$suggestion,$notes===''?null:$notes,$actor);
            foreach($uploads as $upload){$photo=$this->photos->storeEvidence($upload,'damage/'.$agreementId.'/'.$reportId);$stored[]=$photo['storage_path'];$this->reports->appendPhoto($reportId,$photo,$actor);}
            // Damage found at return keeps the vehicle off the road until a fleet manager clears it.
            $this->vehicleHeldAs=$hasDamage&&$phase==='post'?$this->vehicles->holdForDamageInTransaction((int)$agreement['vehicle_id'],$severity,$actor):null;
            $this->db->commit();return $reportId;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();foreach($stored as $path)$this->photos->removeEvidence($path);throw $e;}
    }

    public function decide(int $reportId,bool $liable,string $amount,string $reason,int $actor,?int $supersedes=null): int
    {
        $amount=$this->money($amount);$reason=trim($reason);if($reason===''||mb_strlen($reason)>1000)throw new RuntimeException('A liability decision reason up to 1,000 characters is required.');
        if(!$liable&&$amount!=='0.00')throw new RuntimeException('A not-liable decision must have a zero amount.');
        $this->db->beginTransaction();try{
            $q=$this->db->prepare('SELECT agreement_id FROM damage_reports WHERE report_id=:id');$q->execute(['id'=>$reportId]);$agreementId=$q->fetchColumn();if(!$agreementId)throw new RuntimeException('Damage report not found.');
            $this->lockAgreementChain((int)$agreementId);$report=$this->reports->lockReport($reportId);if(!$report||!(bool)$report['has_damage'])throw new RuntimeException('Liability can only be determined for a damage report.');
            $current=$this->reports->currentDecision($reportId,true);
            if(($current===null&&$supersedes!==null)||($current!==null&&(int)$current['decision_id']!==$supersedes))throw new RuntimeException('The liability decision changed. Reload before correcting it.');
            if($liable&&$this->cents($amount)<=0)throw new RuntimeException('A liable decision requires a positive amount.');
            if($report['repair_cost_suggestion']!==null&&$this->cents($amount)>(int)$this->cents((string)$report['repair_cost_suggestion']))throw new RuntimeException('The liability amount cannot exceed the recorded repair cost suggestion.');
            $id=$this->reports->appendDecision($reportId,$liable,$amount,$reason,$supersedes,$actor);$this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    /** The RentalService owns the sole charge insertion path; the charge row itself records the decision and any adjustment reason. */
    public function postCharge(int $decisionId,string $amount,string $adjustmentReason,int $actor): int
    {
        $amount=$this->money($amount);$reason=trim($adjustmentReason);if($this->cents($amount)<=0)throw new RuntimeException('Charge amount must be positive.');if(mb_strlen($reason)>500)throw new RuntimeException('Adjustment reason must be 500 characters or fewer.');
        $q=$this->db->prepare('SELECT d.*,r.agreement_id,r.repair_cost_suggestion FROM damage_liability_decisions d JOIN damage_reports r ON r.report_id=d.report_id WHERE d.decision_id=:id');$q->execute(['id'=>$decisionId]);$decision=$q->fetch();
        if(!$decision||!(bool)$decision['customer_liable'])throw new RuntimeException('A current customer-liable decision is required before posting a damage charge.');
        if($this->reports->hasPosting($decisionId))throw new RuntimeException('A damage charge has already been posted for this liability decision.');
        if($this->cents($amount)>(int)$this->cents((string)$decision['liable_amount']))throw new RuntimeException('The charge cannot exceed the approved liability amount.');
        $adjusted=$this->cents($amount)!==$this->cents((string)$decision['liable_amount']);if($adjusted&&$reason==='')throw new RuntimeException('A reason is required when finance adjusts the approved amount.');
        try{return $this->rentalService->addCharge((int)$decision['agreement_id'],'damage',$amount,'Damage report #'.$decision['report_id'].' liability decision #'.$decisionId,$actor,function()use($decisionId):void{
            $current=$this->reports->currentDecisionForPosting($decisionId);
            if(!$current)throw new RuntimeException('A superseded liability decision cannot be charged.');
        },$decisionId,$reason===''?null:$reason);}catch(\PDOException $e){if($e->getCode()==='23000')throw new RuntimeException('A damage charge has already been posted for this liability decision.',0,$e);throw $e;}
    }

    public function streamPhoto(int $photoId): array
    {
        $photo=$this->reports->photo($photoId);if(!$photo)throw new RuntimeException('Damage photo not found.');return ['body'=>$this->photos->readEvidence((string)$photo['storage_path']),'mime'=>(string)$photo['mime']];
    }

    private function lockAgreementChain(int $id): array
    {
        $snapshot=$this->rentals->find($id);if(!$snapshot)throw new RuntimeException('Rental agreement not found.');
        $this->rentals->lockVehicle((int)$snapshot['vehicle_id']);$this->rentals->lockCustomer((int)$snapshot['customer_id']);
        $snapshot=$this->rentals->find($id);if(!$snapshot)throw new RuntimeException('Rental agreement not found.');
        if($snapshot['rental_type']==='chauffeur'&&$snapshot['driver_id']!==null)$this->rentals->lockDriver((int)$snapshot['driver_id']);
        $agreement=$this->rentals->lockAgreement($id);if(!$agreement)throw new RuntimeException('Rental agreement not found.');return $agreement;
    }
    private function money(string $v): string { $v=trim($v);if(!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/',$v))throw new RuntimeException('Enter a valid amount.');[$a,$b]=array_pad(explode('.',$v,2),2,'');return $a.'.'.str_pad($b,2,'0'); }
    private function cents(string $v): int { [$a,$b]=array_pad(explode('.',$v,2),2,'0');return (int)$a*100+(int)str_pad(substr($b,0,2),2,'0'); }
}
