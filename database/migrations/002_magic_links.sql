CREATE TABLE IF NOT EXISTS booking_access_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    purpose VARCHAR(64) NOT NULL,
    -- Reserved for Feature C / booking integration; intentionally nullable and no FK exists yet.
    booking_id BIGINT UNSIGNED NULL COMMENT 'Reserved for Feature C booking linkage; populated when hold expiry becomes available',
    expires_at DATETIME(6) NOT NULL,
    used_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_access_token_hash (token_hash),
    KEY idx_booking_access_token_expiry (expires_at, used_at),
    KEY idx_booking_access_token_booking_purpose (booking_id, purpose, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS magic_link_booking_limits (
    booking_id BIGINT UNSIGNED NOT NULL COMMENT 'Reserved booking reference; no FK before bookings exists',
    issue_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS token_usages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_id BIGINT UNSIGNED NOT NULL,
    used_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    action VARCHAR(64) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_token_usages_token_time (token_id, used_at),
    CONSTRAINT fk_token_usages_token FOREIGN KEY (token_id) REFERENCES booking_access_tokens (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
DROP TRIGGER IF EXISTS token_usages_no_update$$
CREATE TRIGGER token_usages_no_update
BEFORE UPDATE ON token_usages
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'token_usages is append-only';
END$$

DROP TRIGGER IF EXISTS token_usages_no_delete$$
CREATE TRIGGER token_usages_no_delete
BEFORE DELETE ON token_usages
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'token_usages is append-only';
END$$
DELIMITER ;
