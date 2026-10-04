-- Mobile numbers outside the customer record are no longer kept in plain text.
--
-- The SMS queue (notifications), the inbound SMS ledger (inbound_sms_events) and the consent
-- ledger (rules_acceptances) each gain, next to their phone column, the number encrypted with
-- CUSTOMER_PII_KEY and a keyed fingerprint (the same one customer_contacts uses). New rows keep
-- only a masked number ("••••4567") in the old column. Lookups (the daily SMS limit, STOP
-- replies) use the fingerprint. See app/Services/PhoneVault.php.
--
-- Rows written before this migration still hold the plain number until
-- `php bin/seal-phone-numbers.php` replaces it; the application reads both kinds meanwhile.
-- The two ledgers are append-only, so their guards are replaced by ones that allow exactly that
-- one change, once: a plain number swapped for its sealed form, with nothing else touched.

ALTER TABLE notifications
    ADD COLUMN recipient_ciphertext VARBINARY(255) NULL AFTER recipient_phone,
    ADD COLUMN recipient_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER recipient_ciphertext,
    ADD KEY idx_notifications_recipient_fingerprint (recipient_fingerprint, created_at);

ALTER TABLE inbound_sms_events
    ADD COLUMN sender_ciphertext VARBINARY(255) NULL AFTER sender_number,
    ADD COLUMN sender_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER sender_ciphertext,
    ADD KEY idx_inbound_sms_sender_fingerprint (sender_fingerprint, event_type, received_at);

ALTER TABLE rules_acceptances
    ADD COLUMN phone_ciphertext VARBINARY(255) NULL AFTER phone,
    ADD COLUMN phone_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER phone_ciphertext,
    ADD KEY idx_rules_phone_fingerprint (phone_fingerprint, action, recorded_at);

DROP TRIGGER IF EXISTS inbound_sms_events_no_update;
DROP TRIGGER IF EXISTS rules_acceptances_no_update;

DELIMITER $$
CREATE TRIGGER inbound_sms_events_no_update BEFORE UPDATE ON inbound_sms_events FOR EACH ROW
BEGIN
    -- Only sealing an unsealed row: the sender and the stored payload become their redacted forms.
    IF NOT (OLD.sender_fingerprint IS NULL AND NEW.sender_fingerprint IS NOT NULL AND NEW.sender_ciphertext IS NOT NULL
        AND NEW.id <=> OLD.id AND NEW.provider_message_id <=> OLD.provider_message_id AND NEW.provider <=> OLD.provider
        AND NEW.received_at <=> OLD.received_at AND NEW.event_type <=> OLD.event_type AND NEW.message_text <=> OLD.message_text) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='inbound_sms_events is append-only';
    END IF;
END$$
CREATE TRIGGER rules_acceptances_no_update BEFORE UPDATE ON rules_acceptances FOR EACH ROW
BEGIN
    -- Only sealing an unsealed row: the phone becomes its masked form.
    IF NOT (OLD.phone_fingerprint IS NULL AND NEW.phone_fingerprint IS NOT NULL AND NEW.phone_ciphertext IS NOT NULL
        AND NEW.acceptance_id <=> OLD.acceptance_id AND NEW.action <=> OLD.action AND NEW.rules_version_id <=> OLD.rules_version_id
        AND NEW.agreement_id <=> OLD.agreement_id AND NEW.inbound_sms_event_id <=> OLD.inbound_sms_event_id
        AND NEW.provider_message_id <=> OLD.provider_message_id AND NEW.ip_address <=> OLD.ip_address
        AND NEW.user_agent <=> OLD.user_agent AND NEW.recorded_at <=> OLD.recorded_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rules_acceptances is append-only';
    END IF;
END$$
DELIMITER ;
