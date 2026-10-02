<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

/** GCash proofs of payment that customers submit for a booking's downpayment. */
final class PaymentProofRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function insert(int $agreementId, string $reference, array $file, ?string $ip): int
    {
        $statement = $this->db->prepare('INSERT INTO payment_proofs (agreement_id, reference_number, storage_path, original_filename, mime, size_bytes, submitted_ip) VALUES (:agreement, :reference, :path, :filename, :mime, :size, :ip)');
        $statement->execute(['agreement' => $agreementId, 'reference' => $reference, 'path' => $file['storage_path'], 'filename' => $file['original_filename'], 'mime' => $file['mime'], 'size' => $file['size_bytes'], 'ip' => $ip]);
        return (int) $this->db->lastInsertId();
    }

    public function find(int $proofId): ?array
    {
        $statement = $this->db->prepare('SELECT * FROM payment_proofs WHERE proof_id = :id');
        $statement->execute(['id' => $proofId]);
        return $statement->fetch() ?: null;
    }

    /** Every proof sent for one booking, oldest first, with who decided it. */
    public function forAgreement(int $agreementId): array
    {
        $statement = $this->db->prepare('SELECT p.*, u.email AS reviewed_by_email FROM payment_proofs p LEFT JOIN users u ON u.id = p.reviewed_by WHERE p.agreement_id = :id ORDER BY p.submitted_at, p.proof_id');
        $statement->execute(['id' => $agreementId]);
        return $statement->fetchAll();
    }

    public function hasPending(int $agreementId): bool
    {
        $statement = $this->db->prepare("SELECT 1 FROM payment_proofs WHERE pending_agreement_id = :id LIMIT 1");
        $statement->execute(['id' => $agreementId]);
        return $statement->fetchColumn() !== false;
    }

    /** True when this GCash reference already paid for a different booking. */
    public function referenceUsedElsewhere(string $reference, int $agreementId): bool
    {
        $statement = $this->db->prepare('SELECT 1 FROM payments WHERE external_reference = :reference AND agreement_id <> :id LIMIT 1');
        $statement->execute(['reference' => $reference, 'id' => $agreementId]);
        return $statement->fetchColumn() !== false;
    }

    public function markVerified(int $proofId, int $actor): bool
    {
        $statement = $this->db->prepare("UPDATE payment_proofs SET proof_status = 'verified', reviewed_by = :actor, reviewed_at = UTC_TIMESTAMP(6) WHERE proof_id = :id AND proof_status = 'submitted'");
        $statement->execute(['actor' => $actor, 'id' => $proofId]);
        return $statement->rowCount() === 1;
    }

    public function markRejected(int $proofId, int $actor, string $reason): bool
    {
        $statement = $this->db->prepare("UPDATE payment_proofs SET proof_status = 'rejected', reviewed_by = :actor, reviewed_at = UTC_TIMESTAMP(6), review_note = :reason WHERE proof_id = :id AND proof_status = 'submitted'");
        $statement->execute(['actor' => $actor, 'reason' => $reason, 'id' => $proofId]);
        return $statement->rowCount() === 1;
    }

    /** Proofs waiting for a decision, longest wait first, with what staff need to check them. */
    public function awaitingReview(): array
    {
        return $this->db->query("SELECT p.proof_id, p.agreement_id, p.reference_number, p.submitted_at, r.booking_reference, r.booking_source, r.downpayment_amount, r.start_date, r.end_date, r.hold_expires_at, c.full_name AS customer_name, v.plate_number, v.make, v.model FROM payment_proofs p JOIN rental_agreements r ON r.agreement_id = p.agreement_id JOIN customers c ON c.customer_id = r.customer_id JOIN vehicles v ON v.vehicle_id = r.vehicle_id WHERE p.proof_status = 'submitted' ORDER BY p.submitted_at, p.proof_id")->fetchAll();
    }

    public function awaitingReviewCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM payment_proofs WHERE proof_status = 'submitted'")->fetchColumn();
    }

    /** The latest decisions, for the lower half of the payments page. */
    public function recentlyDecided(int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->query("SELECT p.proof_id, p.agreement_id, p.reference_number, p.proof_status, p.reviewed_at, p.review_note, u.email AS reviewed_by_email, r.booking_reference, r.downpayment_amount, c.full_name AS customer_name FROM payment_proofs p JOIN rental_agreements r ON r.agreement_id = p.agreement_id JOIN customers c ON c.customer_id = r.customer_id LEFT JOIN users u ON u.id = p.reviewed_by WHERE p.proof_status <> 'submitted' ORDER BY p.reviewed_at DESC, p.proof_id DESC LIMIT {$limit}")->fetchAll();
    }
}
