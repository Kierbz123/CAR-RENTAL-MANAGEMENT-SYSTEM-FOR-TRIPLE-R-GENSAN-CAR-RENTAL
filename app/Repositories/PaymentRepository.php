<?php
declare(strict_types=1);

namespace TripleR\Repositories;

use PDO;

/**
 * The payments table: money finance recorded, and every attempt on the online checkout.
 * Rows are never deleted, and a row that has left "pending" is never changed (the database
 * enforces both), so this only inserts, settles a pending row once, and reads.
 */
final class PaymentRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Money staff already hold: recorded as paid in one step.
     *
     * @param array{agreement_id:int,purpose:string,method:string,amount:string,external_reference:?string,proof_id?:?int,recorded_by:int} $payment
     */
    public function insertStaffPayment(array $payment): int
    {
        $statement = $this->db->prepare("INSERT INTO payments (agreement_id, purpose, channel, method, amount, payment_status, receipt_number, external_reference, proof_id, recorded_by, settled_at) VALUES (:agreement, :purpose, 'staff', :method, :amount, 'paid', :receipt, :reference, :proof, :actor, UTC_TIMESTAMP(6))");
        $statement->execute(['agreement' => $payment['agreement_id'], 'purpose' => $payment['purpose'], 'method' => $payment['method'], 'amount' => $payment['amount'], 'receipt' => self::receiptNumber(), 'reference' => $payment['external_reference'], 'proof' => $payment['proof_id'] ?? null, 'actor' => $payment['recorded_by']]);
        return (int) $this->db->lastInsertId();
    }

    /** An online payment the customer has started and not yet finished. $expiresAt is UTC. */
    public function insertPending(int $agreementId, string $purpose, string $channel, string $method, string $amount, string $expiresAt): string
    {
        $receipt = self::receiptNumber();
        $statement = $this->db->prepare("INSERT INTO payments (agreement_id, purpose, channel, method, amount, payment_status, receipt_number, expires_at) VALUES (:agreement, :purpose, :channel, :method, :amount, 'pending', :receipt, :expires)");
        $statement->execute(['agreement' => $agreementId, 'purpose' => $purpose, 'channel' => $channel, 'method' => $method, 'amount' => $amount, 'receipt' => $receipt, 'expires' => $expiresAt]);
        return $receipt;
    }

    public function find(int $paymentId): ?array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE p.payment_id = :id');
        $statement->execute(['id' => $paymentId]);
        return $statement->fetch() ?: null;
    }

    public function findByReceipt(string $receipt, bool $lock = false): ?array
    {
        if ($lock) {
            $locked = $this->db->prepare('SELECT payment_id FROM payments WHERE receipt_number = :receipt FOR UPDATE');
            $locked->execute(['receipt' => $receipt]);
            if ($locked->fetchColumn() === false) {
                return null;
            }
        }
        $statement = $this->db->prepare(self::SELECT . ' WHERE p.receipt_number = :receipt');
        $statement->execute(['receipt' => $receipt]);
        return $statement->fetch() ?: null;
    }

    /**
     * Closes a pending payment. Returns false when it was already settled, so a result that
     * arrives twice changes nothing.
     */
    public function settle(int $paymentId, string $status, ?string $externalReference, ?string $methodDetail, ?string $failureReason): bool
    {
        $statement = $this->db->prepare("UPDATE payments SET payment_status = :status, external_reference = :reference, method_detail = COALESCE(:detail, method_detail), failure_reason = :reason, settled_at = UTC_TIMESTAMP(6) WHERE payment_id = :id AND payment_status = 'pending'");
        $statement->execute(['status' => $status, 'reference' => $externalReference, 'detail' => $methodDetail, 'reason' => $failureReason, 'id' => $paymentId]);
        return $statement->rowCount() === 1;
    }

    /** Pending payments whose time has run out become "expired". Returns how many. */
    public function expireStale(?int $agreementId = null): int
    {
        $sql = "UPDATE payments SET payment_status = 'expired', settled_at = UTC_TIMESTAMP(6) WHERE payment_status = 'pending' AND expires_at <= UTC_TIMESTAMP(6)";
        if ($agreementId === null) {
            return (int) $this->db->exec($sql);
        }
        $statement = $this->db->prepare($sql . ' AND agreement_id = :id');
        $statement->execute(['id' => $agreementId]);
        return $statement->rowCount();
    }

    /** The payment in progress for a booking, if its time has not run out. */
    public function pendingFor(int $agreementId): ?array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE p.pending_agreement_id = :id AND p.expires_at > UTC_TIMESTAMP(6)');
        $statement->execute(['id' => $agreementId]);
        return $statement->fetch() ?: null;
    }

    public function hasPending(int $agreementId): bool
    {
        return $this->pendingFor($agreementId) !== null;
    }

    /** Every payment and attempt for one booking, oldest first. */
    public function forAgreement(int $agreementId): array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE p.agreement_id = :id ORDER BY p.created_at, p.payment_id');
        $statement->execute(['id' => $agreementId]);
        return $statement->fetchAll();
    }

    /** The downpayment that was received for a booking, or null. */
    public function paidDownpayment(int $agreementId): ?array
    {
        $statement = $this->db->prepare(self::SELECT . ' WHERE p.paid_downpayment_agreement_id = :id');
        $statement->execute(['id' => $agreementId]);
        return $statement->fetch() ?: null;
    }

    /** Money received for a booking, in centavos; optionally for one purpose only. */
    public function paidCents(int $agreementId, ?string $purpose = null): int
    {
        $sql = "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE agreement_id = :id AND payment_status = 'paid'";
        $params = ['id' => $agreementId];
        if ($purpose !== null) {
            $sql .= ' AND purpose = :purpose';
            $params['purpose'] = $purpose;
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return (int) round(((float) $statement->fetchColumn()) * 100);
    }

    /** The newest payments and attempts across all bookings, for the finance page. */
    public function recent(int $limit = 30): array
    {
        $limit = max(1, min(200, $limit));
        return $this->db->query(self::SELECT . " ORDER BY COALESCE(p.settled_at, p.created_at) DESC, p.payment_id DESC LIMIT {$limit}")->fetchAll();
    }

    /**
     * Money received in the last $days days, per method and channel, largest first.
     *
     * @return list<array{method:string,channel:string,payments:int,total:string}>
     */
    public function receivedByMethod(int $days = 30): array
    {
        $days = max(1, min(366, $days));
        return $this->db->query("SELECT method, channel, COUNT(*) AS payments, SUM(amount) AS total FROM payments WHERE payment_status = 'paid' AND settled_at >= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL {$days} DAY) GROUP BY method, channel ORDER BY total DESC, method")->fetchAll();
    }

    private const SELECT = 'SELECT p.*, u.email AS recorded_by_email, r.booking_reference, c.full_name AS customer_name FROM payments p JOIN rental_agreements r ON r.agreement_id = p.agreement_id JOIN customers c ON c.customer_id = r.customer_id LEFT JOIN users u ON u.id = p.recorded_by';

    /** "TR" and ten characters with no 0/O or 1/I to misread, like a booking reference. */
    private static function receiptNumber(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = 'TR';
        for ($i = 0; $i < 10; $i++) {
            $code .= $alphabet[random_int(0, 31)];
        }
        return $code;
    }
}
