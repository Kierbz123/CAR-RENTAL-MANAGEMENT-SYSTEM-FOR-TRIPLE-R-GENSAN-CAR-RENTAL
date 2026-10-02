-- Schema consolidation, step 1: secure-link usage joins the security event log.
-- token_usages and security_logs were both append-only logs of security events with an IP
-- address and a browser. Each usage row becomes a security_logs row that still points at its
-- token through a foreign key. See docs/SCHEMA_CONSOLIDATION_PLAN.md.

ALTER TABLE security_logs
    ADD COLUMN token_id BIGINT UNSIGNED NULL AFTER subject_user_id,
    ADD KEY idx_security_logs_token_time (token_id, created_at),
    ADD CONSTRAINT fk_security_logs_token FOREIGN KEY (token_id) REFERENCES booking_access_tokens(id) ON DELETE RESTRICT;

INSERT INTO security_logs (token_id, event_type, ip_address, user_agent, created_at)
    SELECT token_id, CONCAT('magic_link_', action), ip_address, user_agent, used_at
    FROM token_usages
    ORDER BY id;

-- Stop here, before anything is dropped, unless every usage row was copied.
CREATE TABLE migration_013_check (
    copied_all TINYINT NOT NULL,
    CONSTRAINT chk_migration_013_copied_all CHECK (copied_all = 1)
) ENGINE=InnoDB;
INSERT INTO migration_013_check (copied_all)
    SELECT (SELECT COUNT(*) FROM token_usages) = (SELECT COUNT(*) FROM security_logs WHERE token_id IS NOT NULL);
DROP TABLE migration_013_check;

DROP TABLE token_usages;
