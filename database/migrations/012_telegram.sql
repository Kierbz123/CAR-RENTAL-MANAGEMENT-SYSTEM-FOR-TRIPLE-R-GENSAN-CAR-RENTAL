-- Feature T: customer notifications through Telegram.
-- A customer connects once by pressing Start in the bot with a one-time code. Messages for a
-- connected customer are then delivered through the same notification queue on the 'telegram'
-- channel. See docs/TELEGRAM_NOTIFICATIONS_PLAN.md.

-- One-time connection codes. Only the SHA-256 hash of a code is stored.
CREATE TABLE telegram_link_codes (
    code_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    used_at DATETIME(6) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (code_id),
    UNIQUE KEY uq_telegram_link_codes_hash (code_hash),
    KEY idx_telegram_link_codes_customer (customer_id,used_at,expires_at),
    CONSTRAINT fk_telegram_link_codes_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_telegram_link_codes_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per connection. A connection is never edited: ending it marks the row revoked, and a
-- new connection is a new row. The chat id is stored like a phone number: encrypted, with a
-- keyed fingerprint for lookup. The two generated columns make "one active connection per
-- customer" and "one active customer per chat" database rules (NULL when revoked, so history
-- rows never collide).
CREATE TABLE customer_telegram_links (
    link_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    chat_id_ciphertext VARBINARY(255) NOT NULL,
    chat_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    link_status ENUM('active','revoked') NOT NULL DEFAULT 'active',
    code_id BIGINT UNSIGNED NULL,
    linked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    revoked_at DATETIME(6) NULL,
    revoked_reason ENUM('customer_stop','staff','bot_blocked','relinked') NULL,
    revoked_by_user_id BIGINT UNSIGNED NULL,
    active_customer_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN link_status = 'active' THEN customer_id END) STORED,
    active_chat_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN link_status = 'active' THEN chat_fingerprint END) STORED,
    PRIMARY KEY (link_id),
    UNIQUE KEY uq_telegram_links_active_customer (active_customer_id),
    UNIQUE KEY uq_telegram_links_active_chat (active_chat_fingerprint),
    KEY idx_telegram_links_customer (customer_id,link_status,linked_at),
    KEY idx_telegram_links_chat (chat_fingerprint,link_status),
    CONSTRAINT fk_telegram_links_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_telegram_links_code FOREIGN KEY (code_id) REFERENCES telegram_link_codes(code_id) ON DELETE RESTRICT,
    CONSTRAINT fk_telegram_links_revoker FOREIGN KEY (revoked_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_telegram_links_revocation CHECK (
        (link_status = 'active' AND revoked_at IS NULL AND revoked_reason IS NULL AND revoked_by_user_id IS NULL)
        OR (link_status = 'revoked' AND revoked_at IS NOT NULL AND revoked_reason IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Inbound bot updates, keyed by Telegram's own update_id so a repeated update is ignored.
-- Message text is deliberately not stored.
CREATE TABLE telegram_updates (
    update_id BIGINT UNSIGNED NOT NULL,
    chat_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    event_type VARCHAR(40) NOT NULL,
    outcome VARCHAR(40) NOT NULL DEFAULT 'received',
    received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (update_id),
    KEY idx_telegram_updates_chat (chat_fingerprint,received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The queue learns a second channel. recipient_phone stays, so the daily limit, the
-- idempotency key and the staff history keep working unchanged.
ALTER TABLE notifications
    MODIFY channel ENUM('sms','telegram') NOT NULL DEFAULT 'sms',
    ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER recipient_phone,
    ADD COLUMN telegram_link_id BIGINT UNSIGNED NULL AFTER customer_id,
    ADD KEY idx_notifications_customer (customer_id,created_at),
    ADD CONSTRAINT fk_notifications_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    ADD CONSTRAINT fk_notifications_telegram_link FOREIGN KEY (telegram_link_id) REFERENCES customer_telegram_links(link_id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_notifications_telegram_link CHECK (channel <> 'telegram' OR telegram_link_id IS NOT NULL);

DELIMITER $$
-- A connection's identity never changes, and a revoked connection stays revoked.
CREATE TRIGGER customer_telegram_links_guard BEFORE UPDATE ON customer_telegram_links FOR EACH ROW
BEGIN
    IF NEW.customer_id <> OLD.customer_id OR NEW.chat_fingerprint <> OLD.chat_fingerprint OR NEW.chat_id_ciphertext <> OLD.chat_id_ciphertext OR NEW.linked_at <> OLD.linked_at OR NOT (NEW.code_id <=> OLD.code_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Telegram connections are append-only; only revocation may be recorded';
    END IF;
    IF OLD.link_status = 'revoked' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A revoked Telegram connection cannot be changed';
    END IF;
END$$
CREATE TRIGGER customer_telegram_links_no_delete BEFORE DELETE ON customer_telegram_links FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Telegram connections are append-only'; END$$
DELIMITER ;
