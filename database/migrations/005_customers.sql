CREATE TABLE customers (
    customer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_type ENUM('walk_in','online','corporate','repeat','referral') NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    company_name VARCHAR(160) NULL,
    referral_source VARCHAR(160) NULL,
    is_blacklisted TINYINT(1) NOT NULL DEFAULT 0,
    blacklist_reason VARCHAR(500) NULL,
    blacklisted_at DATETIME(6) NULL,
    blacklisted_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (customer_id),
    KEY idx_customers_eligibility (is_blacklisted, deleted_at, customer_type),
    KEY idx_customers_name (full_name),
    CONSTRAINT fk_customers_blacklisted_by FOREIGN KEY (blacklisted_by_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_customers_blacklist CHECK ((is_blacklisted = 0 AND blacklist_reason IS NULL AND blacklisted_at IS NULL AND blacklisted_by_user_id IS NULL) OR (is_blacklisted = 1 AND blacklist_reason IS NOT NULL AND CHAR_LENGTH(TRIM(blacklist_reason)) > 0 AND blacklisted_at IS NOT NULL AND blacklisted_by_user_id IS NOT NULL)),
    CONSTRAINT chk_customers_corporate_name CHECK (customer_type <> 'corporate' OR (company_name IS NOT NULL AND CHAR_LENGTH(TRIM(company_name)) > 0)),
    CONSTRAINT chk_customers_referral_source CHECK (customer_type <> 'referral' OR (referral_source IS NOT NULL AND CHAR_LENGTH(TRIM(referral_source)) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE customer_contacts (
    contact_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    contact_type ENUM('phone','email') NOT NULL,
    contact_ciphertext VARBINARY(512) NOT NULL,
    contact_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (contact_id),
    KEY idx_customer_contacts_owner (customer_id, contact_type, deleted_at, is_primary),
    KEY idx_customer_contacts_fingerprint (contact_type, contact_fingerprint),
    CONSTRAINT fk_customer_contacts_customer FOREIGN KEY (customer_id) REFERENCES customers (customer_id) ON DELETE RESTRICT,
    CONSTRAINT chk_customer_contact_primary CHECK (is_primary IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE customer_identity_documents (
    document_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    document_ciphertext VARBINARY(512) NOT NULL,
    document_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_on DATE NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (document_id),
    UNIQUE KEY uq_customer_identity_fingerprint (document_fingerprint),
    KEY idx_customer_documents_owner (customer_id, document_type),
    CONSTRAINT fk_customer_documents_customer FOREIGN KEY (customer_id) REFERENCES customers (customer_id) ON DELETE RESTRICT,
    CONSTRAINT chk_customer_document_type CHECK (document_type IN ('ph_driver_license','passport','national_id','other_government_id'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE customer_notes (
    note_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    note_type ENUM('general','blacklist','unblacklist') NOT NULL DEFAULT 'general',
    note_text TEXT NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (note_id),
    KEY idx_customer_notes_history (customer_id, created_at, note_id),
    CONSTRAINT fk_customer_notes_customer FOREIGN KEY (customer_id) REFERENCES customers (customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_notes_actor FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE customer_identity_document_audit_logs (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    old_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    new_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation ENUM('insert','update') NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (audit_id),
    KEY idx_customer_document_audit_customer (customer_id, created_at, audit_id),
    KEY idx_customer_document_audit_document (document_id, created_at),
    CONSTRAINT fk_customer_document_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_document_audit_customer FOREIGN KEY (customer_id) REFERENCES customers (customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_document_audit_document FOREIGN KEY (document_id) REFERENCES customer_identity_documents (document_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
CREATE TRIGGER customer_notes_no_update BEFORE UPDATE ON customer_notes FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'customer_notes is append-only';
END$$
CREATE TRIGGER customer_notes_no_delete BEFORE DELETE ON customer_notes FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'customer_notes is append-only';
END$$
CREATE TRIGGER customer_identity_documents_audit_insert AFTER INSERT ON customer_identity_documents FOR EACH ROW
BEGIN
    IF @triple_r_actor_user_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'customer identity writes require an authenticated actor';
    END IF;
    INSERT INTO customer_identity_document_audit_logs (actor_user_id, customer_id, document_id, document_type, old_fingerprint, new_fingerprint, operation)
    VALUES (@triple_r_actor_user_id, NEW.customer_id, NEW.document_id, NEW.document_type, NULL, NEW.document_fingerprint, 'insert');
END$$
CREATE TRIGGER customer_identity_documents_audit_update AFTER UPDATE ON customer_identity_documents FOR EACH ROW
BEGIN
    IF @triple_r_actor_user_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'customer identity writes require an authenticated actor';
    END IF;
    INSERT INTO customer_identity_document_audit_logs (actor_user_id, customer_id, document_id, document_type, old_fingerprint, new_fingerprint, operation)
    VALUES (@triple_r_actor_user_id, NEW.customer_id, NEW.document_id, NEW.document_type, OLD.document_fingerprint, NEW.document_fingerprint, 'update');
END$$
CREATE TRIGGER customer_identity_document_audit_logs_no_update BEFORE UPDATE ON customer_identity_document_audit_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'customer_identity_document_audit_logs is append-only';
END$$
CREATE TRIGGER customer_identity_document_audit_logs_no_delete BEFORE DELETE ON customer_identity_document_audit_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'customer_identity_document_audit_logs is append-only';
END$$
DELIMITER ;
