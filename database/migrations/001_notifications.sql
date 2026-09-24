CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('system_admin', 'fleet_manager') NOT NULL DEFAULT 'fleet_manager',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    limiter_key CHAR(64) NOT NULL,
    window_started_at DATETIME NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (limiter_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS sms_daily_budgets (
    recipient_phone VARCHAR(20) NOT NULL,
    budget_date DATE NOT NULL,
    message_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (recipient_phone, budget_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_phone VARCHAR(20) NOT NULL,
    idempotency_key VARCHAR(191) NULL,
    channel ENUM('sms') NOT NULL DEFAULT 'sms',
    template_key VARCHAR(80) NOT NULL,
    rendered_message TEXT NOT NULL,
    message_class ENUM('transactional', 'non_transactional') NOT NULL,
    provider VARCHAR(40) NOT NULL,
    status ENUM('queued', 'sending', 'sent', 'failed', 'suppressed') NOT NULL DEFAULT 'queued',
    priority ENUM('normal', 'high') NOT NULL DEFAULT 'normal',
    provider_message_id VARCHAR(191) NULL,
    provider_status VARCHAR(80) NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    retry_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claim_token CHAR(36) NULL,
    claimed_at DATETIME NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(512) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_idempotency (idempotency_key),
    KEY idx_notifications_queue (status, next_attempt_at, priority, created_at),
    KEY idx_notifications_recipient_created (recipient_phone, created_at),
    UNIQUE KEY uq_notifications_provider_message_id (provider_message_id),
    KEY idx_notifications_class_status (message_class, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS inbound_sms_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_message_id VARCHAR(191) NOT NULL,
    provider VARCHAR(40) NOT NULL,
    raw_payload MEDIUMTEXT NOT NULL,
    received_at DATETIME(6) NOT NULL,
    sender_number VARCHAR(20) NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    message_text TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inbound_sms_provider_message_id (provider_message_id),
    KEY idx_inbound_sms_sender_type (sender_number, event_type, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
DROP TRIGGER IF EXISTS inbound_sms_events_no_update$$
CREATE TRIGGER inbound_sms_events_no_update
BEFORE UPDATE ON inbound_sms_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inbound_sms_events is append-only';
END$$

DROP TRIGGER IF EXISTS inbound_sms_events_no_delete$$
CREATE TRIGGER inbound_sms_events_no_delete
BEFORE DELETE ON inbound_sms_events
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inbound_sms_events is append-only';
END$$
DELIMITER ;
