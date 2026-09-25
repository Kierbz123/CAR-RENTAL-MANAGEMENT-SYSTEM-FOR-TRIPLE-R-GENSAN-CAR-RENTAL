<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;
use RuntimeException;
use TripleR\Services\BookingOverlapService;
use TripleR\Services\VehicleService;

final class RentalRepository
{
    public function __construct(private readonly PDO $db, private readonly BookingOverlapService $overlaps) {}

    /** Canonical lock order: vehicle -> customer -> driver (M6). */
    public function createInTransaction(array $data, int $actor): int
    {
        $this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->db->beginTransaction();
        try {
            $vehicle = $this->db->prepare('SELECT * FROM vehicles WHERE vehicle_id=:id AND deleted_at IS NULL FOR UPDATE');
            $vehicle->execute(['id'=>$data['vehicle_id']]); $v=$vehicle->fetch();
            if (!$v || !in_array($v['current_status'],['available','reserved'],true)) throw new RuntimeException('Choose an available vehicle.');
            $customer = $this->db->prepare('SELECT customer_id,is_blacklisted,deleted_at FROM customers WHERE customer_id=:id FOR UPDATE');
            $customer->execute(['id'=>$data['customer_id']]); $c=$customer->fetch();
            if (!$c || (int)$c['is_blacklisted']===1 || $c['deleted_at']!==null) throw new RuntimeException('Choose an eligible customer.');
            if ($data['rental_type'] !== 'self_drive') throw new RuntimeException('Chauffeur rentals are unavailable until M6 adds driver assignment and conflict protection.');
            if ($this->overlaps->vehicleConflicts((int)$v['vehicle_id'],$data['start_date'],$data['end_date'])) throw new RuntimeException('This vehicle already has an overlapping rental.');
            $holdMinutes=max(1,min(1440,(int)$data['hold_minutes']));
            $stmt=$this->db->prepare("INSERT INTO rental_agreements (customer_id,vehicle_id,rental_type,start_date,end_date,scheduled_pickup_at,scheduled_return_at,daily_rate,security_deposit_amount,deposit_status,hold_expires_at,status,created_by_user_id) VALUES (:customer,:vehicle,'self_drive',:start_date,:end_date,:pickup,:return_at,:rate,:deposit,:deposit_status,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL {$holdMinutes} MINUTE),'reserved',:actor)");
            $stmt->execute(['customer'=>$data['customer_id'],'vehicle'=>$data['vehicle_id'],'start_date'=>$data['start_date'],'end_date'=>$data['end_date'],'pickup'=>$data['scheduled_pickup_at'],'return_at'=>$data['scheduled_return_at'],'rate'=>$v['daily_rate'],'deposit'=>$data['deposit_amount'],'deposit_status'=>$data['deposit_amount']>0?'due':'not_required','actor'=>$actor]);
            $id=(int)$this->db->lastInsertId();
            $this->appendStatus($id,null,'reserved',null,$actor);
            $this->appendDeposit($id,null,$data['deposit_amount']>0?'due':'not_required',null,(string)$data['deposit_amount'],'Initial deposit state',$actor);
            $this->db->commit(); return $id;
        } catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    public function list(array $filters=[]): array
    {
        $sql='SELECT r.*,c.full_name AS customer_name,v.plate_number,v.make,v.model FROM rental_agreements r JOIN customers c ON c.customer_id=r.customer_id JOIN vehicles v ON v.vehicle_id=r.vehicle_id WHERE 1=1'; $params=[];
        if (!empty($filters['status'])) { $sql.=' AND r.status=:status'; $params['status']=$filters['status']; }
        $sql.=' ORDER BY FIELD(r.status,\'reserved\',\'confirmed\',\'active\',\'returned\',\'completed\',\'cancelled\',\'no_show\'),r.start_date,r.agreement_id';
        $q=$this->db->prepare($sql);$q->execute($params);return $q->fetchAll();
    }

    public function find(int $id,bool $lock=false): ?array
    {
        $base=null;if($lock){$lockQuery=$this->db->prepare('SELECT * FROM rental_agreements WHERE agreement_id=:id FOR UPDATE');$lockQuery->execute(['id'=>$id]);$base=$lockQuery->fetch();if(!$base)return null;}
        $q=$this->db->prepare('SELECT r.*,c.full_name AS customer_name,c.customer_type,v.plate_number,v.make,v.model,v.current_status AS vehicle_status FROM rental_agreements r JOIN customers c ON c.customer_id=r.customer_id JOIN vehicles v ON v.vehicle_id=r.vehicle_id WHERE r.agreement_id=:id');$q->execute(['id'=>$id]);$row=$q->fetch();return $row?($base===null?$row:array_merge($row,$base)) : null;
    }

    public function lockVehicle(int $id): ?array { $q=$this->db->prepare('SELECT * FROM vehicles WHERE vehicle_id=:id FOR UPDATE');$q->execute(['id'=>$id]);$r=$q->fetch();return $r?:null; }
    public function lockCustomer(int $id): ?array { $q=$this->db->prepare('SELECT * FROM customers WHERE customer_id=:id FOR UPDATE');$q->execute(['id'=>$id]);$r=$q->fetch();return $r?:null; }
    public function lockAgreement(int $id): ?array { return $this->find($id,true); }

    public function setStatus(int $id,string $expected,string $next,?string $reason,int $actor,?string $actualColumn=null): bool
    {
        $allowed=['actual_pickup_at','actual_return_at']; if ($actualColumn!==null&&!in_array($actualColumn,$allowed,true)) throw new \InvalidArgumentException('Invalid lifecycle timestamp field.');
        $sql='UPDATE rental_agreements SET status=:next'.($actualColumn!==null?", {$actualColumn}=UTC_TIMESTAMP(6)":'').' WHERE agreement_id=:id AND status=:expected';
        $q=$this->db->prepare($sql);$q->execute(['next'=>$next,'id'=>$id,'expected'=>$expected]);
        if($q->rowCount()!==1)return false;$this->appendStatus($id,$expected,$next,$reason,$actor);return true;
    }

    public function statusHistory(int $id): array { $q=$this->db->prepare('SELECT l.*,u.email AS actor_email FROM rental_status_logs l JOIN users u ON u.id=l.actor_user_id WHERE agreement_id=:id ORDER BY created_at,status_log_id');$q->execute(['id'=>$id]);return $q->fetchAll(); }
    public function depositHistory(int $id): array { $q=$this->db->prepare('SELECT l.*,u.email AS actor_email FROM deposit_status_logs l JOIN users u ON u.id=l.actor_user_id WHERE agreement_id=:id ORDER BY created_at,deposit_log_id');$q->execute(['id'=>$id]);return $q->fetchAll(); }
    public function appendStatus(int $id,?string $old,string $new,?string $reason,int $actor): void
    { $q=$this->db->prepare('INSERT INTO rental_status_logs(agreement_id,old_status,new_status,reason,actor_user_id) VALUES(:id,:old,:new,:reason,:actor)');$q->execute(['id'=>$id,'old'=>$old,'new'=>$new,'reason'=>$reason,'actor'=>$actor]); }
    public function appendDeposit(int $id,?string $old, string $new,?string $oldAmount,string $newAmount,string $reason,int $actor): void
    { $q=$this->db->prepare('INSERT INTO deposit_status_logs(agreement_id,old_status,new_status,old_amount,new_amount,reason,actor_user_id) VALUES(:id,:old,:new,:old_amount,:new_amount,:reason,:actor)');$q->execute(['id'=>$id,'old'=>$old,'new'=>$new,'old_amount'=>$oldAmount,'new_amount'=>$newAmount,'reason'=>$reason,'actor'=>$actor]); }
}
