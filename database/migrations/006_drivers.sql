CREATE TABLE drivers (
    driver_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(160) NOT NULL,
    license_number_ciphertext VARBINARY(512) NOT NULL,
    license_number_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    license_expiry DATE NOT NULL,
    address_ciphertext VARBINARY(4096) NULL,
    emergency_contact_name_ciphertext VARBINARY(1024) NULL,
    emergency_contact_phone_ciphertext VARBINARY(512) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (driver_id),
    UNIQUE KEY uq_drivers_license_fingerprint (license_number_fingerprint),
    KEY idx_drivers_selectable (status, deleted_at, license_expiry, full_name),
    KEY idx_drivers_name (full_name),
    CONSTRAINT chk_drivers_name CHECK (CHAR_LENGTH(TRIM(full_name)) > 0),
    CONSTRAINT chk_drivers_emergency_pair CHECK ((emergency_contact_name_ciphertext IS NULL AND emergency_contact_phone_ciphertext IS NULL) OR (emergency_contact_name_ciphertext IS NOT NULL AND emergency_contact_phone_ciphertext IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE driver_contacts (
    contact_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id BIGINT UNSIGNED NOT NULL,
    contact_type ENUM('phone','email') NOT NULL,
    contact_ciphertext VARBINARY(2048) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (contact_id),
    KEY idx_driver_contacts_owner (driver_id, contact_type, deleted_at, is_primary),
    CONSTRAINT fk_driver_contacts_driver FOREIGN KEY (driver_id) REFERENCES drivers (driver_id) ON DELETE RESTRICT,
    CONSTRAINT chk_driver_contact_primary CHECK (is_primary IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE driver_status_logs (
    status_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id BIGINT UNSIGNED NOT NULL,
    old_status ENUM('active','inactive') NULL,
    new_status ENUM('active','inactive') NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (status_log_id),
    KEY idx_driver_status_history (driver_id, created_at, status_log_id),
    CONSTRAINT fk_driver_status_driver FOREIGN KEY (driver_id) REFERENCES drivers (driver_id) ON DELETE RESTRICT,
    CONSTRAINT fk_driver_status_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
CREATE TRIGGER driver_status_logs_no_update BEFORE UPDATE ON driver_status_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'driver_status_logs is append-only';
END$$
CREATE TRIGGER driver_status_logs_no_delete BEFORE DELETE ON driver_status_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'driver_status_logs is append-only';
END$$
DELIMITER ;
