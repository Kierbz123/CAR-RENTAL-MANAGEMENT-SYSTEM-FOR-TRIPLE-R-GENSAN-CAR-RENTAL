<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class RulesAcceptanceRepository
{
    public function __construct(private readonly PDO $db) {}

    /** Imports the immutable Feature E STOP ledger; replay is safe through DB uniqueness. */
    public function consumeStopEvents(int $limit=500): int
    {
        $limit=max(1,min(5000,$limit));
        $this->db->beginTransaction();try{$sql="SELECT e.id,e.sender_number,e.provider_message_id,e.received_at FROM inbound_sms_events e WHERE e.event_type='stop' AND NOT EXISTS(SELECT 1 FROM rules_acceptances a WHERE a.phone=e.sender_number AND a.provider_message_id=e.provider_message_id) ORDER BY e.id LIMIT {$limit}";$rows=$this->db->query($sql)->fetchAll();$insert=$this->db->prepare("INSERT IGNORE INTO rules_acceptances(phone,action,inbound_sms_event_id,provider_message_id,recorded_at) VALUES(:phone,'revoked',:event,:message,:at)");$count=0;foreach($rows as $row){$insert->execute(['phone'=>$row['sender_number'],'event'=>$row['id'],'message'=>$row['provider_message_id'],'at'=>$row['received_at']]);$count+=$insert->rowCount();}$this->db->commit();return $count;}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function hasStop(string $phone): bool
    {
        $q=$this->db->prepare("SELECT 1 FROM rules_acceptances WHERE phone=:phone AND action='revoked' LIMIT 1");$q->execute(['phone'=>$phone]);return $q->fetchColumn()!==false;
    }
}
