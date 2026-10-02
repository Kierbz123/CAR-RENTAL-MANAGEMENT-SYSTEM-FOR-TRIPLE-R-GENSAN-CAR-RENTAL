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

    /** The newest published version of one policy (for example 'downpayment_policy'), or null. */
    public function currentVersion(string $rulesKey): ?array
    {
        $q=$this->db->prepare('SELECT * FROM rules_versions WHERE rules_key=:key ORDER BY version_number DESC LIMIT 1');$q->execute(['key'=>$rulesKey]);$row=$q->fetch();return $row?:null;
    }

    /** Records that the customer accepted that exact version for that booking, with where the acceptance came from. */
    public function recordAcceptance(int $rulesVersionId,int $agreementId,string $phone,string $ip,string $userAgent): void
    {
        $q=$this->db->prepare("INSERT INTO rules_acceptances(phone,action,rules_version_id,agreement_id,ip_address,user_agent,recorded_at) VALUES(:phone,'accepted',:version,:agreement,:ip,:agent,UTC_TIMESTAMP(6))");
        $q->execute(['phone'=>$phone,'version'=>$rulesVersionId,'agreement'=>$agreementId,'ip'=>filter_var($ip,FILTER_VALIDATE_IP)?$ip:null,'agent'=>substr($userAgent,0,512)]);
    }

    /** What the customer accepted for this booking, with the text of that version; null for bookings made at the counter. */
    public function acceptanceForAgreement(int $agreementId): ?array
    {
        $q=$this->db->prepare("SELECT a.recorded_at,a.ip_address,v.rules_key,v.version_number,v.title,v.body FROM rules_acceptances a JOIN rules_versions v ON v.rules_version_id=a.rules_version_id WHERE a.agreement_id=:id AND a.action='accepted' ORDER BY a.acceptance_id DESC LIMIT 1");$q->execute(['id'=>$agreementId]);$row=$q->fetch();return $row?:null;
    }

    public function hasStop(string $phone): bool
    {
        $q=$this->db->prepare("SELECT 1 FROM rules_acceptances WHERE phone=:phone AND action='revoked' LIMIT 1");$q->execute(['phone'=>$phone]);return $q->fetchColumn()!==false;
    }
}
