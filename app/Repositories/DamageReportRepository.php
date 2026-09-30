<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

final class DamageReportRepository
{
    public function __construct(private readonly PDO $db) {}

    public function forAgreement(int $agreementId): array
    {
        $q=$this->db->prepare("SELECT r.*,u.email AS recorded_by_email,d.decision_id,d.customer_liable,d.liable_amount,d.reason AS liability_reason,d.created_at AS decided_at FROM damage_reports r JOIN users u ON u.id=r.recorded_by LEFT JOIN damage_liability_decisions d ON d.report_id=r.report_id AND NOT EXISTS (SELECT 1 FROM damage_liability_decisions newer WHERE newer.supersedes_decision_id=d.decision_id) WHERE r.agreement_id=:id ORDER BY FIELD(r.phase,'pre','during','post'),r.created_at,r.report_id");
        $q->execute(['id'=>$agreementId]);$reports=$q->fetchAll();
        $photos=$this->db->prepare('SELECT photo_id,report_id,original_filename,mime,size_bytes FROM damage_photos WHERE report_id=:id ORDER BY photo_id');
        foreach($reports as &$report){$photos->execute(['id'=>$report['report_id']]);$report['photos']=$photos->fetchAll();}
        return $reports;
    }

    public function appendReport(int $agreementId,string $phase,bool $hasDamage,?string $location,?string $type,?string $severity,?string $suggestion,?string $notes,int $actor): int
    {
        $q=$this->db->prepare('INSERT INTO damage_reports(agreement_id,phase,has_damage,location,damage_type,severity,repair_cost_suggestion,notes,recorded_by) VALUES(:agreement,:phase,:has_damage,:location,:type,:severity,:suggestion,:notes,:actor)');
        $q->execute(['agreement'=>$agreementId,'phase'=>$phase,'has_damage'=>$hasDamage?1:0,'location'=>$location,'type'=>$type,'severity'=>$severity,'suggestion'=>$suggestion,'notes'=>$notes,'actor'=>$actor]);return (int)$this->db->lastInsertId();
    }

    public function appendPhoto(int $reportId,array $photo,int $actor): void
    {
        $q=$this->db->prepare('INSERT INTO damage_photos(report_id,storage_path,original_filename,mime,size_bytes,uploaded_by) VALUES(:report,:path,:filename,:mime,:size,:actor)');
        $q->execute(['report'=>$reportId,'path'=>$photo['storage_path'],'filename'=>$photo['original_filename'],'mime'=>$photo['mime'],'size'=>$photo['size_bytes'],'actor'=>$actor]);
    }

    public function lockReport(int $reportId): ?array
    {
        $q=$this->db->prepare('SELECT * FROM damage_reports WHERE report_id=:id FOR UPDATE');$q->execute(['id'=>$reportId]);$r=$q->fetch();return $r?:null;
    }

    public function currentDecision(int $reportId,bool $lock=false): ?array
    {
        $q=$this->db->prepare('SELECT * FROM damage_liability_decisions WHERE report_id=:id AND NOT EXISTS (SELECT 1 FROM damage_liability_decisions newer WHERE newer.supersedes_decision_id=damage_liability_decisions.decision_id) ORDER BY decision_id DESC LIMIT 1'.($lock?' FOR UPDATE':''));
        $q->execute(['id'=>$reportId]);$r=$q->fetch();return $r?:null;
    }

    public function currentDecisionForPosting(int $decisionId): ?array
    {
        $q=$this->db->prepare('SELECT d.* FROM damage_liability_decisions d WHERE d.decision_id=:id AND NOT EXISTS (SELECT 1 FROM damage_liability_decisions newer WHERE newer.supersedes_decision_id=d.decision_id) FOR UPDATE');$q->execute(['id'=>$decisionId]);$r=$q->fetch();return $r?:null;
    }

    public function appendDecision(int $reportId,bool $liable,string $amount,string $reason,?int $supersedes,int $actor): int
    {
        $q=$this->db->prepare('INSERT INTO damage_liability_decisions(report_id,customer_liable,liable_amount,reason,supersedes_decision_id,decided_by) VALUES(:report,:liable,:amount,:reason,:supersedes,:actor)');
        $q->execute(['report'=>$reportId,'liable'=>$liable?1:0,'amount'=>$amount,'reason'=>$reason,'supersedes'=>$supersedes,'actor'=>$actor]);return (int)$this->db->lastInsertId();
    }

    public function appendPosting(int $decisionId,int $chargeId,string $amount,?string $adjustmentReason,int $actor): void
    {
        $q=$this->db->prepare('INSERT INTO damage_charge_postings(decision_id,charge_id,approved_amount,adjustment_reason,posted_by) VALUES(:decision,:charge,:amount,:reason,:actor)');
        $q->execute(['decision'=>$decisionId,'charge'=>$chargeId,'amount'=>$amount,'reason'=>$adjustmentReason,'actor'=>$actor]);
    }

    public function hasPosting(int $decisionId): bool
    {
        $q=$this->db->prepare('SELECT 1 FROM damage_charge_postings WHERE decision_id=:id');$q->execute(['id'=>$decisionId]);return $q->fetchColumn()!==false;
    }

    public function photo(int $photoId): ?array
    {
        $q=$this->db->prepare('SELECT p.*,r.agreement_id FROM damage_photos p JOIN damage_reports r ON r.report_id=p.report_id WHERE p.photo_id=:id');$q->execute(['id'=>$photoId]);$r=$q->fetch();return $r?:null;
    }

    public function detail(int $reportId): ?array
    {
        $q=$this->db->prepare('SELECT r.*,u.email AS recorded_by_email FROM damage_reports r JOIN users u ON u.id=r.recorded_by WHERE r.report_id=:id');$q->execute(['id'=>$reportId]);$report=$q->fetch();if(!$report)return null;
        $q=$this->db->prepare('SELECT photo_id,original_filename,mime,size_bytes FROM damage_photos WHERE report_id=:id ORDER BY photo_id');$q->execute(['id'=>$reportId]);$report['photos']=$q->fetchAll();
        $q=$this->db->prepare('SELECT d.*,u.email AS actor_email FROM damage_liability_decisions d JOIN users u ON u.id=d.decided_by WHERE d.report_id=:id ORDER BY d.created_at,d.decision_id');$q->execute(['id'=>$reportId]);$report['decisions']=$q->fetchAll();
        $q=$this->db->prepare('SELECT p.*,c.amount AS charge_amount,c.description AS charge_description,u.email AS actor_email FROM damage_charge_postings p JOIN rental_charges c ON c.charge_id=p.charge_id JOIN users u ON u.id=p.posted_by WHERE p.decision_id IN (SELECT decision_id FROM damage_liability_decisions WHERE report_id=:id) ORDER BY p.created_at,p.posting_id');$q->execute(['id'=>$reportId]);$report['postings']=$q->fetchAll();return $report;
    }
}
