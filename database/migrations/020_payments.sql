-- Payments: every payment received and every online attempt, for any method, in one table.
-- Until now only a GCash downpayment could be recorded, on the agreement itself. From here a
-- booking can be paid by GCash, Maya, GrabPay, card, online banking or cash:
--
--   channel 'staff'        finance recorded it: money handed over at the counter, or a customer's
--                          GCash proof that finance verified (proof_id then names that proof)
--   channel 'online_demo'  the customer paid on the simulated checkout. No real money moves; the
--                          channel is stored so a simulated payment is never mistaken for a real one
--
--   purpose 'downpayment'  the 30% that must be in before a reservation is confirmed
--   purpose 'balance'      the rest, received at the counter
--
-- Who received a downpayment, when, and its reference now live here, so the three columns that
-- said the same thing on rental_agreements are removed. The agreement keeps the amount (fixed at
-- booking) and the status (the gate that confirming checks).

CREATE TABLE payments (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agreement_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('downpayment','balance') NOT NULL,
    channel ENUM('staff','online_demo') NOT NULL,
    method ENUM('cash','gcash','maya','grabpay','card','online_banking') NOT NULL,
    method_detail VARCHAR(60) NULL,
    amount DECIMAL(18,2) NOT NULL,
    payment_status ENUM('pending','paid','failed','cancelled','expired') NOT NULL,
    receipt_number CHAR(12) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    external_reference VARCHAR(40) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    failure_reason VARCHAR(160) NULL,
    proof_id BIGINT UNSIGNED NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NULL,
    settled_at DATETIME(6) NULL,
    pending_agreement_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN payment_status = 'pending' THEN agreement_id END) STORED,
    paid_downpayment_agreement_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN payment_status = 'paid' AND purpose = 'downpayment' THEN agreement_id END) STORED,
    PRIMARY KEY (payment_id),
    UNIQUE KEY uq_payments_receipt_number (receipt_number),
    UNIQUE KEY uq_payments_external_reference (external_reference),
    UNIQUE KEY uq_payments_proof (proof_id),
    UNIQUE KEY uq_payments_one_pending (pending_agreement_id),
    UNIQUE KEY uq_payments_one_paid_downpayment (paid_downpayment_agreement_id),
    KEY idx_payments_agreement (agreement_id, created_at, payment_id),
    KEY idx_payments_status_settled (payment_status, settled_at),
    CONSTRAINT fk_payments_agreement FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_proof FOREIGN KEY (proof_id) REFERENCES payment_proofs(proof_id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_payments_amount CHECK (amount > 0),
    -- Staff record money they already hold; an online payment has no staff member and is never cash.
    CONSTRAINT chk_payments_channel CHECK (
        (channel = 'staff' AND payment_status = 'paid' AND recorded_by IS NOT NULL AND expires_at IS NULL)
        OR (channel = 'online_demo' AND method <> 'cash' AND recorded_by IS NULL AND proof_id IS NULL)
    ),
    CONSTRAINT chk_payments_state CHECK (
        (payment_status = 'pending' AND settled_at IS NULL AND failure_reason IS NULL AND external_reference IS NULL AND expires_at IS NOT NULL)
        OR (payment_status = 'paid' AND settled_at IS NOT NULL AND failure_reason IS NULL AND ((method = 'cash' AND external_reference IS NULL) OR (method <> 'cash' AND external_reference IS NOT NULL AND CHAR_LENGTH(TRIM(external_reference)) > 0)))
        OR (payment_status = 'failed' AND settled_at IS NOT NULL AND external_reference IS NULL AND failure_reason IS NOT NULL AND CHAR_LENGTH(TRIM(failure_reason)) > 0)
        OR (payment_status IN ('cancelled','expired') AND settled_at IS NOT NULL AND external_reference IS NULL AND failure_reason IS NULL)
    ),
    CONSTRAINT chk_payments_proof CHECK (proof_id IS NULL OR (channel = 'staff' AND method = 'gcash' AND purpose = 'downpayment'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Downpayments already recorded were GCash payments recorded by finance. They keep their
-- reference, receiver and time, and are tied to the proof that was verified, where there was one.
INSERT INTO payments (agreement_id, purpose, channel, method, amount, payment_status, receipt_number, external_reference, proof_id, recorded_by, created_at, settled_at)
SELECT r.agreement_id, 'downpayment', 'staff', 'gcash', r.downpayment_amount, 'paid',
       CONCAT('TR', UPPER(SUBSTRING(SHA2(CONCAT('payment:', r.agreement_id, ':', r.downpayment_reference, ':', RAND()), 256), 1, 10))),
       r.downpayment_reference,
       (SELECT p.proof_id FROM payment_proofs p WHERE p.agreement_id = r.agreement_id AND p.proof_status = 'verified' AND p.reference_number = r.downpayment_reference ORDER BY p.proof_id DESC LIMIT 1),
       r.downpayment_received_by, r.downpayment_received_at, r.downpayment_received_at
FROM rental_agreements r
WHERE r.downpayment_status = 'received';

DROP TRIGGER rental_agreements_downpayment_guard;

ALTER TABLE rental_agreements
    DROP CHECK chk_rentals_downpayment,
    DROP FOREIGN KEY fk_rentals_downpayment_receiver,
    DROP KEY uq_rentals_downpayment_reference;

ALTER TABLE rental_agreements
    DROP COLUMN downpayment_reference,
    DROP COLUMN downpayment_received_by,
    DROP COLUMN downpayment_received_at,
    ADD CONSTRAINT chk_rentals_downpayment CHECK (
        (downpayment_status = 'not_required' AND downpayment_amount = 0)
        OR (downpayment_status IN ('due','received') AND downpayment_amount > 0)
    );

-- The policy named GCash as the only way to pay. Published policy text is never edited, so the
-- wording that fits every payment method is a new version; bookings made under version 1 keep it.
INSERT INTO rules_versions (rules_key, version_number, title, body) VALUES
('downpayment_policy', 2, 'Downpayment policy',
 'To reserve a vehicle you pay a downpayment of 30% of the rental cost, by any payment method the rental office accepts. The downpayment is non-refundable, including when you cancel the booking or do not pick up the vehicle. Your reservation is confirmed only after the rental office has received your payment. A reservation that is not paid within 24 hours is released. The remaining balance is paid when you pick up the vehicle.');

DELIMITER $$
-- The amount is fixed at booking. A downpayment is marked received only when its payment is in
-- the payments table, and is never taken back.
CREATE TRIGGER rental_agreements_downpayment_guard BEFORE UPDATE ON rental_agreements FOR EACH ROW
BEGIN
    IF NEW.downpayment_amount <> OLD.downpayment_amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The downpayment amount is fixed when the booking is made';
    END IF;
    IF OLD.downpayment_status = 'received' AND NEW.downpayment_status <> 'received' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A recorded downpayment cannot be changed';
    END IF;
    IF NEW.downpayment_status = 'received' AND OLD.downpayment_status <> 'received' AND NOT EXISTS (SELECT 1 FROM payments WHERE paid_downpayment_agreement_id = NEW.agreement_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A downpayment is received only when its payment is recorded';
    END IF;
END$$
-- A downpayment is paid in full, at the amount stored on the booking: nothing typed in a
-- browser can change what is charged.
CREATE TRIGGER payments_amount_guard BEFORE INSERT ON payments FOR EACH ROW
BEGIN
    IF NEW.purpose = 'downpayment' AND NEW.amount <> (SELECT downpayment_amount FROM rental_agreements WHERE agreement_id = NEW.agreement_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A downpayment is paid at the amount fixed when the booking was made';
    END IF;
END$$
-- What was asked for never changes, and a payment is settled once.
CREATE TRIGGER payments_guard BEFORE UPDATE ON payments FOR EACH ROW
BEGIN
    IF NEW.agreement_id <> OLD.agreement_id OR NEW.purpose <> OLD.purpose OR NEW.channel <> OLD.channel OR NEW.method <> OLD.method OR NEW.amount <> OLD.amount OR NEW.receipt_number <> OLD.receipt_number OR NEW.created_at <> OLD.created_at OR NOT (NEW.proof_id <=> OLD.proof_id) OR NOT (NEW.recorded_by <=> OLD.recorded_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The details of a payment cannot be edited';
    END IF;
    IF OLD.payment_status <> 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A payment that has been settled cannot be changed';
    END IF;
END$$
CREATE TRIGGER payments_no_delete BEFORE DELETE ON payments FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='payments is append-only'; END$$
DELIMITER ;
