-- Online booking: a customer books from the public site, accepts the downpayment policy,
-- pays by GCash and uploads the proof; finance checks it and the reservation is confirmed.
--
--   rental_agreements.booking_reference  the code a customer quotes (with their phone number)
--                                        to find their booking again without an account
--   rental_agreements.booking_source     who made the booking: staff at the counter, or the
--                                        customer online
--   rules_versions                       the text of each policy, one row per version, never edited
--   rules_acceptances                    now also records "accepted": which version, for which
--                                        booking, from which address, beside the SMS STOP rows
--   payment_proofs                       each GCash proof a customer submits and what finance decided

ALTER TABLE rental_agreements
    ADD COLUMN booking_reference CHAR(8) CHARACTER SET ascii COLLATE ascii_general_ci NULL AFTER agreement_id,
    ADD COLUMN booking_source ENUM('staff','online') NOT NULL DEFAULT 'staff' AFTER rental_type;

-- Existing agreements get a reference too, so every booking can be quoted the same way.
UPDATE rental_agreements
    SET booking_reference = UPPER(SUBSTRING(SHA2(CONCAT(agreement_id, ':', created_at, ':', RAND()), 256), 1, 8))
    WHERE booking_reference IS NULL;

ALTER TABLE rental_agreements
    MODIFY booking_reference CHAR(8) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    ADD UNIQUE KEY uq_rentals_booking_reference (booking_reference);

CREATE TABLE rules_versions (
    rules_version_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rules_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    published_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (rules_version_id),
    UNIQUE KEY uq_rules_versions_key_number (rules_key, version_number),
    CONSTRAINT chk_rules_versions_number CHECK (version_number > 0),
    CONSTRAINT chk_rules_versions_text CHECK (CHAR_LENGTH(TRIM(title)) > 0 AND CHAR_LENGTH(TRIM(body)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO rules_versions (rules_key, version_number, title, body) VALUES
('downpayment_policy', 1, 'Downpayment policy',
 'To reserve a vehicle you pay a downpayment of 30% of the rental cost by GCash. The downpayment is non-refundable, including when you cancel the booking or do not pick up the vehicle. Your reservation is confirmed only after the rental office has checked your payment. A reservation that is not paid within 24 hours is released. The remaining balance is paid in person when you pick up the vehicle.');

ALTER TABLE rules_acceptances
    MODIFY action ENUM('revoked','accepted') NOT NULL DEFAULT 'revoked',
    MODIFY inbound_sms_event_id BIGINT UNSIGNED NULL,
    MODIFY provider_message_id VARCHAR(191) NULL,
    ADD COLUMN rules_version_id BIGINT UNSIGNED NULL AFTER action,
    ADD COLUMN agreement_id BIGINT UNSIGNED NULL AFTER rules_version_id,
    ADD COLUMN ip_address VARCHAR(45) NULL AFTER provider_message_id,
    ADD COLUMN user_agent VARCHAR(512) NULL AFTER ip_address,
    ADD UNIQUE KEY uq_rules_acceptance_agreement_version (agreement_id, rules_version_id),
    ADD CONSTRAINT fk_rules_acceptance_version FOREIGN KEY (rules_version_id) REFERENCES rules_versions(rules_version_id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_rules_acceptance_agreement FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_rules_acceptance_kind CHECK (
        (action = 'revoked' AND inbound_sms_event_id IS NOT NULL AND provider_message_id IS NOT NULL AND rules_version_id IS NULL AND agreement_id IS NULL)
        OR (action = 'accepted' AND rules_version_id IS NOT NULL AND agreement_id IS NOT NULL AND inbound_sms_event_id IS NULL AND provider_message_id IS NULL)
    );

-- One row per proof a customer submits. The generated column lets the database allow only
-- one proof per booking to be waiting for a decision at a time.
CREATE TABLE payment_proofs (
    proof_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agreement_id BIGINT UNSIGNED NOT NULL,
    reference_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    proof_status ENUM('submitted','verified','rejected') NOT NULL DEFAULT 'submitted',
    submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    submitted_ip VARCHAR(45) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME(6) NULL,
    review_note VARCHAR(500) NULL,
    pending_agreement_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN proof_status = 'submitted' THEN agreement_id END) STORED,
    PRIMARY KEY (proof_id),
    UNIQUE KEY uq_payment_proofs_storage_path (storage_path),
    UNIQUE KEY uq_payment_proofs_one_pending (pending_agreement_id),
    KEY idx_payment_proofs_agreement (agreement_id, submitted_at, proof_id),
    KEY idx_payment_proofs_queue (proof_status, submitted_at),
    CONSTRAINT fk_payment_proofs_agreement FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_proofs_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_payment_proofs_size CHECK (size_bytes > 0),
    CONSTRAINT chk_payment_proofs_review CHECK (
        (proof_status = 'submitted' AND reviewed_by IS NULL AND reviewed_at IS NULL AND review_note IS NULL)
        OR (proof_status = 'verified' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)
        OR (proof_status = 'rejected' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND review_note IS NOT NULL AND CHAR_LENGTH(TRIM(review_note)) > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
CREATE TRIGGER rules_versions_no_update BEFORE UPDATE ON rules_versions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rules_versions is append-only; publish a new version'; END$$
CREATE TRIGGER rules_versions_no_delete BEFORE DELETE ON rules_versions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rules_versions is append-only'; END$$
-- What the customer submitted never changes, and a decision is made once.
CREATE TRIGGER payment_proofs_guard BEFORE UPDATE ON payment_proofs FOR EACH ROW
BEGIN
    IF NEW.agreement_id <> OLD.agreement_id OR NEW.reference_number <> OLD.reference_number OR NEW.storage_path <> OLD.storage_path OR NEW.submitted_at <> OLD.submitted_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A submitted payment proof cannot be edited';
    END IF;
    IF OLD.proof_status <> 'submitted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A payment proof that has been decided cannot be changed';
    END IF;
END$$
CREATE TRIGGER payment_proofs_no_delete BEFORE DELETE ON payment_proofs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='payment_proofs is append-only'; END$$
-- A booking keeps its reference and its source for life.
CREATE TRIGGER rental_agreements_booking_identity BEFORE UPDATE ON rental_agreements FOR EACH ROW
BEGIN
    IF NEW.booking_reference <> OLD.booking_reference OR NEW.booking_source <> OLD.booking_source THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A booking reference and source cannot be changed';
    END IF;
END$$
DELIMITER ;
