-- Three small hardening steps.
--
-- 1. Timestamps with microseconds, like every other table (the SMS queue, the rate counters and
--    the migration record still had whole seconds).
-- 2. A system account for what the system does on its own (online bookings, expired holds), so
--    those records no longer name the first administrator, who did nothing. It cannot sign in:
--    it is inactive, removed and locked, and its password hash matches no password.
-- 3. audit_seals: bin/audit-seal.php records, for each append-only history table, how many rows
--    it had and a hash over all of them; bin/audit-verify.php recomputes and compares. A history
--    row changed or removed by an account that can bypass the triggers (the migration account)
--    shows up as a mismatch. Append-only itself.

ALTER TABLE notifications
    MODIFY COLUMN next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    MODIFY COLUMN claimed_at DATETIME(6) NULL,
    MODIFY COLUMN sent_at DATETIME(6) NULL,
    MODIFY COLUMN created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    MODIFY COLUMN updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6);
ALTER TABLE rate_counters MODIFY COLUMN window_started_at DATETIME(6) NOT NULL;
ALTER TABLE schema_migrations MODIFY COLUMN applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6);

INSERT IGNORE INTO users (email, password_hash, role, is_active, must_change_password, locked_at, deleted_at)
VALUES ('system@triple-r.invalid', '!', 'front_desk', 0, 1, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6));

CREATE TABLE audit_seals (
    seal_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    table_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    last_row_id BIGINT UNSIGNED NOT NULL,
    row_count BIGINT UNSIGNED NOT NULL,
    chain_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sealed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (seal_id),
    KEY idx_audit_seals_table (table_name, seal_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
CREATE TRIGGER audit_seals_no_update BEFORE UPDATE ON audit_seals FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit_seals is append-only'; END$$
CREATE TRIGGER audit_seals_no_delete BEFORE DELETE ON audit_seals FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='audit_seals is append-only'; END$$
DELIMITER ;
