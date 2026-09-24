-- Migration 001 values system_admin and fleet_manager map to themselves.
-- Rebuild the ENUM so its final legal-value set is exactly these eight roles.
ALTER TABLE users
    MODIFY COLUMN role ENUM(
        'system_admin',
        'fleet_manager',
        'front_desk',
        'driver_coordinator',
        'mechanic',
        'finance_staff',
        'auditor',
        'support_staff'
    ) NOT NULL DEFAULT 'fleet_manager',
    MODIFY COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    MODIFY COLUMN updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    ADD COLUMN failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN locked_at DATETIME(6) NULL,
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN deleted_at DATETIME(6) NULL,
    ADD KEY idx_users_active_role (is_active, role, deleted_at);

-- Existing credentials are treated as temporary after the auth-policy migration.
UPDATE users SET must_change_password = 1 WHERE must_change_password = 0;

CREATE TABLE IF NOT EXISTS sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    invalidated_at DATETIME(6) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_hash (session_hash),
    KEY idx_sessions_user_active (user_id, invalidated_at, expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS security_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,
    subject_user_id BIGINT UNSIGNED NULL,
    email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    event_type VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_security_logs_actor_time (actor_user_id, created_at),
    KEY idx_security_logs_subject_time (subject_user_id, created_at),
    KEY idx_security_logs_event_time (event_type, created_at),
    CONSTRAINT fk_security_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_security_logs_subject FOREIGN KEY (subject_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
DROP TRIGGER IF EXISTS security_logs_no_update$$
CREATE TRIGGER security_logs_no_update
BEFORE UPDATE ON security_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_logs is append-only';
END$$

DROP TRIGGER IF EXISTS security_logs_no_delete$$
CREATE TRIGGER security_logs_no_delete
BEFORE DELETE ON security_logs
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'security_logs is append-only';
END$$
DELIMITER ;
