<?php
declare(strict_types=1);

namespace TripleR\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use TripleR\Config;
use TripleR\Repositories\MaintenanceRepository;

final class MaintenanceService
{
    public function __construct(private readonly PDO $db,private readonly MaintenanceRepository $repository,private readonly VehicleService $vehicles,private readonly VehiclePhotoService $photos) {}

    public function defaults(): array
    {
        return ['days'=>$this->positiveConfig('MAINTENANCE_DUE_SOON_DAYS',30,36500),'kilometers'=>$this->positiveConfig('MAINTENANCE_DUE_SOON_KM',500,4294967295)];
    }

    public function createSchedule(array $input,int $actor): int
    {
        $data=$this->scheduleData($input);$reason=$this->reason($input['reason']??null,'Schedule creation reason is required.');
        $this->db->beginTransaction();try{
            $vehicle=$this->lockVehicle($data['vehicle_id']);
            if(!$vehicle||$vehicle['current_status']==='retired')throw new RuntimeException('Choose an active vehicle.');
            $data=$this->initialDue($data,(int)$vehicle['current_mileage']);
            $q=$this->db->prepare('INSERT INTO maintenance_schedules (vehicle_id,schedule_name,interval_time_days,interval_mileage,next_due_date,next_due_mileage,due_soon_days_override,due_soon_mileage_override,is_active,created_by,updated_by) VALUES (:vehicle,:name,:days,:km,:due_date,:due_km,:days_override,:km_override,1,:actor_created,:actor_updated)');
            $q->execute(['vehicle'=>$data['vehicle_id'],'name'=>$data['name'],'days'=>$data['days'],'km'=>$data['km'],'due_date'=>$data['due_date'],'due_km'=>$data['due_km'],'days_override'=>$data['days_override'],'km_override'=>$data['km_override'],'actor_created'=>$actor,'actor_updated'=>$actor]);$id=(int)$this->db->lastInsertId();$this->appendScheduleLog($id,null,$this->readSchedule($id),$reason,$actor);$this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function updateSchedule(int $id,array $input,int $actor): void
    {
        $data=$this->scheduleData($input);$reason=$this->reason($input['reason']??null,'A reason is required for schedule changes.');
        $this->db->beginTransaction();try{
            $snapshot=$this->db->prepare('SELECT vehicle_id FROM maintenance_schedules WHERE schedule_id=:id');$snapshot->execute(['id'=>$id]);$vehicleId=$snapshot->fetchColumn();if($vehicleId===false)throw new RuntimeException('Maintenance schedule not found.');
            $vehicle=$this->lockVehicle((int)$vehicleId);if(!$vehicle)throw new RuntimeException('The schedule vehicle is unavailable.');$q=$this->db->prepare('SELECT * FROM maintenance_schedules WHERE schedule_id=:id FOR UPDATE');$q->execute(['id'=>$id]);$old=$q->fetch();if(!$old)throw new RuntimeException('Maintenance schedule not found.');
            if((int)$old['vehicle_id']!==$data['vehicle_id'])throw new RuntimeException('A schedule cannot be moved to another vehicle.');
            $data=$this->initialDue($data,(int)$vehicle['current_mileage']);
            $stmt=$this->db->prepare('UPDATE maintenance_schedules SET schedule_name=:name,interval_time_days=:days,interval_mileage=:km,next_due_date=:due_date,next_due_mileage=:due_km,due_soon_days_override=:days_override,due_soon_mileage_override=:km_override,is_active=:active,updated_by=:actor WHERE schedule_id=:id');
            $stmt->execute(['name'=>$data['name'],'days'=>$data['days'],'km'=>$data['km'],'due_date'=>$data['due_date'],'due_km'=>$data['due_km'],'days_override'=>$data['days_override'],'km_override'=>$data['km_override'],'active'=>($input['is_active']??'1')==='1'?1:0,'actor'=>$actor,'id'=>$id]);$new=$this->readSchedule($id);$this->appendScheduleLog($id,$old,$new,$reason,$actor);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function start(array $input,int $actor): int
    {
        $vehicleId=$this->positiveInt($input['vehicle_id']??null,'vehicle');$scheduleId=$this->optionalPositiveInt($input['schedule_id']??null,'schedule');
        $title=$this->text($input['title']??null,160,'Enter a service title.');$notes=$this->optionalText($input['notes']??null,2000);
        $this->db->beginTransaction();try{
            $vehicle=$this->lockVehicle($vehicleId);if(!$vehicle)throw new RuntimeException('Vehicle not found.');
            if(in_array($vehicle['current_status'],['retired','rented','maintenance'],true))throw new RuntimeException('This vehicle cannot start another maintenance service in its current status.');
            $this->assertNoRentalConflict($vehicleId);
            if($scheduleId!==null){$q=$this->db->prepare('SELECT * FROM maintenance_schedules WHERE schedule_id=:id AND vehicle_id=:vehicle AND is_active=1 FOR UPDATE');$q->execute(['id'=>$scheduleId,'vehicle'=>$vehicleId]);if(!$q->fetch())throw new RuntimeException('Choose an active schedule for this vehicle.');}
            $this->assertNoActiveService($vehicleId);
            $this->vehicles->transitionStatusInTransaction($vehicleId,'maintenance',$actor);
            $q=$this->db->prepare("INSERT INTO maintenance_services (vehicle_id,schedule_id,mechanic_id,status,vehicle_status_before,title,notes,created_by,updated_by) VALUES (:vehicle,:schedule,:mechanic,'in_progress',:prior,:title,:notes,:actor_created,:actor_updated)");
            $q->execute(['vehicle'=>$vehicleId,'schedule'=>$scheduleId,'mechanic'=>$actor,'prior'=>$vehicle['current_status'],'title'=>$title,'notes'=>$notes,'actor_created'=>$actor,'actor_updated'=>$actor]);$id=(int)$this->db->lastInsertId();
            $this->appendStatusLog($id,null,'in_progress',null,$actor);$this->db->commit();return $id;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function complete(int $serviceId,int $mileage,int $actor): void
    {
        if($mileage<0||$mileage>4294967295)throw new RuntimeException('Enter a valid whole-kilometer odometer reading.');
        $this->db->beginTransaction();try{
            $service=$this->lockServiceAfterVehicle($serviceId);if($service['status']!=='in_progress')throw new RuntimeException('Only an in-progress service can be completed.');
            $this->vehicles->recordMileageInTransaction((int)$service['vehicle_id'],$mileage,null,$actor);
            $mileageLog=$this->db->prepare('SELECT mileage_log_id FROM vehicle_mileage_logs WHERE vehicle_id=:vehicle ORDER BY recorded_at DESC,mileage_log_id DESC LIMIT 1');$mileageLog->execute(['vehicle'=>$service['vehicle_id']]);$logId=(int)$mileageLog->fetchColumn();
            $now=new DateTimeImmutable('now',new DateTimeZone('UTC'));$manila=$now->setTimezone(new DateTimeZone('Asia/Manila'));
            if($service['schedule_id']!==null){$scheduleQuery=$this->db->prepare('SELECT * FROM maintenance_schedules WHERE schedule_id=:id AND vehicle_id=:vehicle FOR UPDATE');$scheduleQuery->execute(['id'=>$service['schedule_id'],'vehicle'=>$service['vehicle_id']]);$schedule=$scheduleQuery->fetch();if(!$schedule)throw new RuntimeException('The linked maintenance schedule no longer exists.');
            $dueDate=$schedule['interval_time_days']===null?null:$this->dateAfterDays($manila->format('Y-m-d'),(int)$schedule['interval_time_days']);
                $nextMileage=null;if($schedule['interval_mileage']!==null){$nextMileage=$mileage+(int)$schedule['interval_mileage'];if($nextMileage>4294967295)throw new RuntimeException('The next mileage threshold exceeds the supported range.');}
                $update=$this->db->prepare('UPDATE maintenance_schedules SET next_due_date=:date,next_due_mileage=:mileage,updated_by=:actor WHERE schedule_id=:id');$update->execute(['date'=>$dueDate,'mileage'=>$nextMileage,'actor'=>$actor,'id'=>$service['schedule_id']]);$newSchedule=$this->readSchedule((int)$service['schedule_id']);$this->appendScheduleLog((int)$service['schedule_id'],$schedule,$newSchedule,'Advanced after completion of service #'.$serviceId,$actor);
            }
            $q=$this->db->prepare("UPDATE maintenance_services SET status='completed',completed_at=UTC_TIMESTAMP(6),completion_mileage_log_id=:log,updated_by=:actor WHERE service_id=:id AND status='in_progress'");$q->execute(['log'=>$logId,'actor'=>$actor,'id'=>$serviceId]);if($q->rowCount()!==1)throw new RuntimeException('The service changed in another request. Reload and try again.');
            $this->appendStatusLog($serviceId,'in_progress','completed',null,$actor);$this->restoreOrReview($service,$actor);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function cancel(int $serviceId,string $reason,int $actor): void
    {
        $reason=$this->reason($reason,'A cancellation reason is required.');
        $this->db->beginTransaction();try{$service=$this->lockServiceAfterVehicle($serviceId);if($service['status']!=='in_progress')throw new RuntimeException('Only an in-progress service can be cancelled.');
            $q=$this->db->prepare("UPDATE maintenance_services SET status='cancelled',cancelled_at=UTC_TIMESTAMP(6),cancel_reason=:reason,updated_by=:actor WHERE service_id=:id AND status='in_progress'");$q->execute(['reason'=>$reason,'actor'=>$actor,'id'=>$serviceId]);if($q->rowCount()!==1)throw new RuntimeException('The service changed in another request. Reload and try again.');
            $this->appendStatusLog($serviceId,'in_progress','cancelled',$reason,$actor);$this->restoreOrReview($service,$actor);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function updateCosts(int $serviceId,array $input,string $reason,int $actor,bool $manager): void
    {
        $reason=$this->reason($reason,'A reason is required for cost changes.');$costs=[];foreach(['labor_cost','parts_cost','other_cost'] as $key)$costs[$key]=$this->money($input[$key]??'0',$key);
        $this->db->beginTransaction();try{$service=$this->lockServiceAfterVehicle($serviceId);if($service['status']==='completed'&&!$manager)throw new RuntimeException('Only a fleet manager or system administrator can correct completed service costs.');if($service['status']==='cancelled')throw new RuntimeException('Costs on a cancelled service cannot be changed.');
            $q=$this->db->prepare('UPDATE maintenance_services SET labor_cost=:labor,parts_cost=:parts,other_cost=:other,updated_by=:actor WHERE service_id=:id');$q->execute(['labor'=>$costs['labor_cost'],'parts'=>$costs['parts_cost'],'other'=>$costs['other_cost'],'actor'=>$actor,'id'=>$serviceId]);
            $audit=$this->db->prepare('INSERT INTO maintenance_cost_audit_logs (service_id,old_labor_cost,new_labor_cost,old_parts_cost,new_parts_cost,old_other_cost,new_other_cost,reason,actor_user_id) VALUES (:service,:old_labor,:new_labor,:old_parts,:new_parts,:old_other,:new_other,:reason,:actor)');$audit->execute(['service'=>$serviceId,'old_labor'=>$service['labor_cost'],'new_labor'=>$costs['labor_cost'],'old_parts'=>$service['parts_cost'],'new_parts'=>$costs['parts_cost'],'old_other'=>$service['other_cost'],'new_other'=>$costs['other_cost'],'reason'=>$reason,'actor'=>$actor]);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function resolveReview(int $serviceId,string $target,string $reason,int $actor): void
    {
        $reason=$this->reason($reason,'A review resolution reason is required.');$allowed=['available','reserved','rented','cleaning','out_of_service'];if(!in_array($target,$allowed,true))throw new RuntimeException('Choose a valid restored vehicle status.');
        $this->db->beginTransaction();try{$service=$this->lockServiceAfterVehicle($serviceId);if((int)$service['needs_review']!==1)throw new RuntimeException('This service has no unresolved status review.');
            if(in_array($target,['available','reserved','rented'],true)){$derived=$this->agreementStatus((int)$service['vehicle_id']);if($target!==$derived)throw new RuntimeException('That status does not match current rental agreements. Current agreement status is '.$derived.'.');}
            $v=$this->db->prepare('SELECT current_status FROM vehicles WHERE vehicle_id=:id FOR UPDATE');$v->execute(['id'=>$service['vehicle_id']]);$current=$v->fetchColumn();if($current==='retired')throw new RuntimeException('A retired vehicle status cannot be changed.');if($current!==$target)$this->vehicles->transitionStatusInTransaction((int)$service['vehicle_id'],$target,$actor);
            $q=$this->db->prepare('UPDATE maintenance_services SET needs_review=0,reviewed_by=:reviewer,reviewed_at=UTC_TIMESTAMP(6),review_reason=:reason,updated_by=:updater WHERE service_id=:id AND needs_review=1');$q->execute(['reviewer'=>$actor,'reason'=>$reason,'updater'=>$actor,'id'=>$serviceId]);if($q->rowCount()!==1)throw new RuntimeException('The review changed in another request.');$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function addPhoto(int $serviceId,string $phase,array $file,int $actor): void
    {
        if(!in_array($phase,['before','after'],true))throw new RuntimeException('Choose a valid photo phase.');
        $service=$this->repository->service($serviceId);if(!$service)throw new RuntimeException('Maintenance service not found.');
        if(($phase==='before'&&$service['status']!=='in_progress')||($phase==='after'&&$service['status']!=='completed'))throw new RuntimeException('Before photos belong to an in-progress service; after photos belong to a completed service.');
        $stored=$this->photos->storeEvidence($file,'maintenance/'.$service['vehicle_id'].'/'.$serviceId);
        try{$this->db->beginTransaction();$q=$this->db->prepare('SELECT status FROM maintenance_services WHERE service_id=:id AND vehicle_id=:vehicle FOR UPDATE');$q->execute(['id'=>$serviceId,'vehicle'=>$service['vehicle_id']]);$status=$q->fetchColumn();if(($phase==='before'&&$status!=='in_progress')||($phase==='after'&&$status!=='completed'))throw new RuntimeException('Service status changed before the photo could be attached.');$count=$this->db->prepare('SELECT COUNT(*) FROM photos WHERE maintenance_service_id=:id AND phase=:phase');$count->execute(['id'=>$serviceId,'phase'=>$phase]);if((int)$count->fetchColumn()>=10)throw new RuntimeException('A maximum of 10 photos is allowed for each service phase.');$insert=$this->db->prepare('INSERT INTO photos (maintenance_service_id,phase,storage_path,original_filename,mime,size_bytes,uploaded_by) VALUES (:service,:phase,:path,:name,:mime,:size,:actor)');$insert->execute(['service'=>$serviceId,'phase'=>$phase,'path'=>$stored['storage_path'],'name'=>$stored['original_filename'],'mime'=>$stored['mime'],'size'=>$stored['size_bytes'],'actor'=>$actor]);$this->db->commit();}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();$this->photos->removeEvidence($stored['storage_path']);throw $e;}
    }

    public function streamPhoto(int $photoId): array
    {
        $q=$this->db->prepare('SELECT storage_path,mime FROM photos WHERE photo_id=:id AND maintenance_service_id IS NOT NULL');$q->execute(['id'=>$photoId]);$row=$q->fetch();if(!$row)throw new RuntimeException('Photo not found.');return ['body'=>$this->photos->readEvidence((string)$row['storage_path']),'mime'=>(string)$row['mime']];
    }

    public function scheduleData(array $input): array
    {
        $vehicle=$this->positiveInt($input['vehicle_id']??null,'vehicle');$name=$this->text($input['schedule_name']??null,100,'Enter a schedule name.');
        $days=$this->optionalPositiveInt($input['interval_time_days']??null,'time interval');$km=$this->optionalPositiveInt($input['interval_mileage']??null,'mileage interval');if($days===null&&$km===null)throw new RuntimeException('Enter a time interval, mileage interval, or both.');
        $rawDate=trim((string)($input['next_due_date']??''));$rawMileage=trim((string)($input['next_due_mileage']??''));if($days===null&&$rawDate!=='')throw new RuntimeException('A due date requires a time interval.');if($km===null&&$rawMileage!=='')throw new RuntimeException('A due mileage requires a mileage interval.');
        $date=$days===null?null:$this->validDate($rawDate);$nextKm=$km===null?null:($rawMileage===''?null:$this->nonNegativeInt($rawMileage,'next due mileage'));
        $daysOverride=$this->optionalPositiveInt($input['due_soon_days_override']??null,'due-soon days');$kmOverride=$this->optionalPositiveInt($input['due_soon_mileage_override']??null,'due-soon mileage');if($daysOverride!==null&&$daysOverride>65535)throw new RuntimeException('Due-soon day overrides cannot exceed 65,535.');
        return ['vehicle_id'=>$vehicle,'name'=>$name,'days'=>$days,'km'=>$km,'due_date'=>$date,'due_km'=>$nextKm,'days_override'=>$daysOverride,'km_override'=>$kmOverride];
    }

    private function initialDue(array $data,int $mileage): array
    {
        if($data['days']!==null&&$data['due_date']===null)$data['due_date']=$this->dateAfterDays((new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->format('Y-m-d'),$data['days']);
        if($data['km']!==null&&$data['due_km']===null){$value=$mileage+$data['km'];if($value>4294967295)throw new RuntimeException('The next mileage threshold exceeds the supported range.');$data['due_km']=$value;}
        return $data;
    }

    private function restoreOrReview(array $service,int $actor): void
    {
        $q=$this->db->prepare('SELECT current_status FROM vehicles WHERE vehicle_id=:id FOR UPDATE');$q->execute(['id'=>$service['vehicle_id']]);$current=$q->fetchColumn();$prior=(string)$service['vehicle_status_before'];$needsReview=false;$target=$prior;
        if($current!=='maintenance'){$needsReview=true;}
        elseif(in_array($prior,['available','reserved','rented'],true)){$target=$this->agreementStatus((int)$service['vehicle_id']);if(($prior==='reserved'&&$target!=='reserved')||($prior==='rented'&&$target!=='rented'))$needsReview=true;}
        if($needsReview){$this->db->prepare('UPDATE maintenance_services SET needs_review=1 WHERE service_id=:id')->execute(['id'=>$service['service_id']]);return;}
        if($current==='maintenance')$this->vehicles->transitionStatusInTransaction((int)$service['vehicle_id'],$target,$actor);
    }

    private function agreementStatus(int $vehicleId): string
    {
        $q=$this->db->prepare("SELECT status FROM rental_agreements WHERE vehicle_id=:vehicle AND status IN ('active','confirmed') AND (status='active' OR end_date>=:today) ORDER BY CASE status WHEN 'active' THEN 0 ELSE 1 END FOR UPDATE");$q->execute(['vehicle'=>$vehicleId,'today'=>(new DateTimeImmutable('today',new DateTimeZone('Asia/Manila')))->format('Y-m-d')]);$rows=$q->fetchAll();foreach($rows as $r)if($r['status']==='active')return 'rented';foreach($rows as $r)if($r['status']==='confirmed')return 'reserved';return 'available';
    }

    private function assertNoRentalConflict(int $vehicleId): void
    {
        $q=$this->db->prepare("SELECT agreement_id,status FROM rental_agreements WHERE vehicle_id=:vehicle AND (status='active' OR (status='reserved' AND hold_expires_at>UTC_TIMESTAMP(6))) ORDER BY agreement_id FOR UPDATE");$q->execute(['vehicle'=>$vehicleId]);if($q->fetch())throw new RuntimeException('An active rental or unexpired reservation prevents maintenance from starting.');
    }

    private function assertNoActiveService(int $vehicleId): void
    {
        $q=$this->db->prepare("SELECT service_id FROM maintenance_services WHERE vehicle_id=:vehicle AND status='in_progress' LIMIT 1 FOR UPDATE");$q->execute(['vehicle'=>$vehicleId]);if($q->fetchColumn()!==false)throw new RuntimeException('This vehicle already has an in-progress maintenance service.');
    }

    private function lockServiceAfterVehicle(int $id): array
    {
        $q=$this->db->prepare('SELECT vehicle_id FROM maintenance_services WHERE service_id=:id');$q->execute(['id'=>$id]);$vehicleId=$q->fetchColumn();if($vehicleId===false)throw new RuntimeException('Maintenance service not found.');$this->lockVehicle((int)$vehicleId);
        $q=$this->db->prepare('SELECT * FROM maintenance_services WHERE service_id=:id FOR UPDATE');$q->execute(['id'=>$id]);$row=$q->fetch();if(!$row)throw new RuntimeException('Maintenance service not found.');return $row;
    }

    private function lockVehicle(int $id): ?array
    {
        $q=$this->db->prepare('SELECT * FROM vehicles WHERE vehicle_id=:id AND deleted_at IS NULL FOR UPDATE');$q->execute(['id'=>$id]);$row=$q->fetch();return $row?:null;
    }

    private function appendStatusLog(int $id,?string $old,string $new,?string $reason,int $actor): void
    {
        $q=$this->db->prepare('INSERT INTO status_logs (subject,maintenance_service_id,old_status,new_status,reason,actor_user_id) VALUES (\'maintenance_service\',:service,:old,:new,:reason,:actor)');$q->execute(['service'=>$id,'old'=>$old,'new'=>$new,'reason'=>$reason,'actor'=>$actor]);
    }

    private function appendScheduleLog(int $id,?array $old,array $new,string $reason,int $actor): void
    {
        $columns=['schedule_name'=>'schedule_name','interval_time_days'=>'interval_time_days','interval_mileage'=>'interval_mileage','next_due_date'=>'next_due_date','next_due_mileage'=>'next_due_mileage','due_soon_days_override'=>'due_soon_days_override','due_soon_mileage_override'=>'due_soon_mileage_override','is_active'=>'is_active'];$insert=['schedule_id'=>$id,'actor_user_id'=>$actor,'reason'=>$reason];foreach($columns as $column=>$field){$insert['old_'.$field]=$old[$column]??null;$insert['new_'.$field]=$new[$column]??null;}$names=array_keys($insert);$sql='INSERT INTO maintenance_schedule_logs ('.implode(',',$names).') VALUES (:'.implode(',:',$names).')';$this->db->prepare($sql)->execute($insert);
    }

    private function readSchedule(int $id): array
    {
        $q=$this->db->prepare('SELECT * FROM maintenance_schedules WHERE schedule_id=:id');$q->execute(['id'=>$id]);$r=$q->fetch();if(!$r)throw new RuntimeException('Maintenance schedule not found.');return $r;
    }

    private function positiveConfig(string $key,int $default,int $max): int
    {
        $raw=Config::get($key,(string)$default);if($raw===null||$raw===''||!ctype_digit($raw)||(int)$raw<1||(int)$raw>$max)throw new RuntimeException('Invalid maintenance due-soon configuration.');return (int)$raw;
    }

    private function money(mixed $raw,string $label): string
    {
        $value=trim((string)$raw);if(!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/',$value))throw new RuntimeException('Enter a valid '.$label.'.');[$whole,$fraction]=array_pad(explode('.',$value,2),2,'');return $whole.'.'.str_pad($fraction,2,'0');
    }
    private function reason(mixed $raw,string $message): string{$value=trim((string)$raw);if($value===''||mb_strlen($value)>500)throw new RuntimeException($message);return $value;}
    private function text(mixed $raw,int $max,string $message): string{$value=trim((string)$raw);if($value===''||mb_strlen($value)>$max)throw new RuntimeException($message);return $value;}
    private function optionalText(mixed $raw,int $max): ?string{$value=trim((string)$raw);if($value==='' )return null;if(mb_strlen($value)>$max)throw new RuntimeException('Notes are too long.');return $value;}
    private function positiveInt(mixed $raw,string $name): int{$value=filter_var($raw,FILTER_VALIDATE_INT);if($value===false||$value<1)throw new RuntimeException('Choose a valid '.$name.'.');return (int)$value;}
    private function optionalPositiveInt(mixed $raw,string $name): ?int{if($raw===null||trim((string)$raw)==='')return null;$value=filter_var($raw,FILTER_VALIDATE_INT);if($value===false||$value<1||$value>4294967295)throw new RuntimeException('Enter a valid '.$name.'.');return (int)$value;}
    private function nonNegativeInt(mixed $raw,string $name): int{$value=filter_var($raw,FILTER_VALIDATE_INT);if($value===false||$value<0||$value>4294967295)throw new RuntimeException('Enter a valid '.$name.'.');return (int)$value;}
    private function validDate(mixed $raw): ?string{$value=trim((string)$raw);if($value==='')return null;$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Asia/Manila'));if(!$date||$date->format('Y-m-d')!==$value)throw new RuntimeException('Enter a valid next due date.');return $value;}
    private function dateAfterDays(string $base,int $days): string{$date=(new DateTimeImmutable($base,new DateTimeZone('Asia/Manila')))->modify('+'.$days.' days');if(!$date||(int)$date->format('Y')>9999)throw new RuntimeException('The next due date exceeds the supported calendar range.');return $date->format('Y-m-d');}
}
