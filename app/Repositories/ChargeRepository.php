<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class ChargeRepository
{
    public function __construct(private readonly PDO $db){}
    public function forAgreement(int $id): array{$q=$this->db->prepare('SELECT c.*,u.email AS actor_email,(r.charge_id IS NOT NULL) AS is_reversed FROM rental_charges c JOIN users u ON u.id=c.created_by_user_id LEFT JOIN rental_charges r ON r.reverses_charge_id=c.charge_id WHERE c.agreement_id=:id ORDER BY c.created_at,c.charge_id');$q->execute(['id'=>$id]);return $q->fetchAll();}
    public function appendCharge(int $id,string $type,string $amount,string $description,int $actor): void{$q=$this->db->prepare("INSERT INTO rental_charges(agreement_id,charge_type,entry_kind,amount,description,created_by_user_id) VALUES(:id,:type,'charge',:amount,:description,:actor)");$q->execute(['id'=>$id,'type'=>$type,'amount'=>$amount,'description'=>$description,'actor'=>$actor]);}
    public function lockOriginalForReversal(int $id,int $chargeId): ?array{$q=$this->db->prepare("SELECT c.* FROM rental_charges c WHERE c.charge_id=:charge AND c.agreement_id=:agreement AND c.entry_kind='charge' AND NOT EXISTS(SELECT 1 FROM rental_charges r WHERE r.reverses_charge_id=c.charge_id) FOR UPDATE");$q->execute(['charge'=>$chargeId,'agreement'=>$id]);$row=$q->fetch();return $row?:null;}
    public function appendReversal(int $id,array $original,string $description,int $actor): void{$q=$this->db->prepare("INSERT INTO rental_charges(agreement_id,charge_type,entry_kind,amount,description,reverses_charge_id,created_by_user_id) VALUES(:agreement,:type,'reversal',:amount,:description,:reverses,:actor)");$q->execute(['agreement'=>$id,'type'=>$original['charge_type'],'amount'=>$original['amount'],'description'=>$description,'reverses'=>$original['charge_id'],'actor'=>$actor]);}
}
