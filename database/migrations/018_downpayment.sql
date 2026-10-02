-- The 30% GCash downpayment.
-- The amount is worked out when the booking is made and stored, so a later change to the
-- vehicle's rate never changes what the customer was asked to pay. Finance records the payment
-- with its GCash reference number, and a reservation cannot be confirmed until it is recorded.
-- The balance is paid in person at pickup.
--
-- downpayment_status follows the same wording as deposit_status:
--   not_required  nothing to pay (amount 0): agreements made before this rule, or a zero rate
--   due           an amount is set and has not been recorded as received
--   received      finance recorded the GCash payment: reference, who, when

ALTER TABLE rental_agreements
    ADD COLUMN downpayment_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00 AFTER deposit_status,
    ADD COLUMN downpayment_status ENUM('not_required','due','received') NOT NULL DEFAULT 'not_required' AFTER downpayment_amount,
    ADD COLUMN downpayment_reference VARCHAR(40) CHARACTER SET ascii COLLATE ascii_general_ci NULL AFTER downpayment_status,
    ADD COLUMN downpayment_received_by BIGINT UNSIGNED NULL AFTER downpayment_reference,
    ADD COLUMN downpayment_received_at DATETIME(6) NULL AFTER downpayment_received_by,
    ADD UNIQUE KEY uq_rentals_downpayment_reference (downpayment_reference),
    ADD CONSTRAINT fk_rentals_downpayment_receiver FOREIGN KEY (downpayment_received_by) REFERENCES users(id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_rentals_downpayment CHECK (
        (downpayment_status = 'not_required' AND downpayment_amount = 0 AND downpayment_reference IS NULL AND downpayment_received_by IS NULL AND downpayment_received_at IS NULL)
        OR (downpayment_status = 'due' AND downpayment_amount > 0 AND downpayment_reference IS NULL AND downpayment_received_by IS NULL AND downpayment_received_at IS NULL)
        OR (downpayment_status = 'received' AND downpayment_amount > 0 AND downpayment_reference IS NOT NULL AND CHAR_LENGTH(TRIM(downpayment_reference)) > 0 AND downpayment_received_by IS NOT NULL AND downpayment_received_at IS NOT NULL)
    );

-- Reservations still waiting to be confirmed come under the rule. Agreements already
-- confirmed or further along were made before it existed and stay "not required".
UPDATE rental_agreements
    SET downpayment_amount = ROUND(base_amount * 30 / 100, 2), downpayment_status = 'due'
    WHERE status = 'reserved' AND ROUND(base_amount * 30 / 100, 2) > 0;

DELIMITER $$
-- The amount is fixed at booking, and a recorded payment is never edited.
CREATE TRIGGER rental_agreements_downpayment_guard BEFORE UPDATE ON rental_agreements FOR EACH ROW
BEGIN
    IF NEW.downpayment_amount <> OLD.downpayment_amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The downpayment amount is fixed when the booking is made';
    END IF;
    IF OLD.downpayment_status = 'received' AND (NEW.downpayment_status <> 'received' OR NOT (NEW.downpayment_reference <=> OLD.downpayment_reference) OR NOT (NEW.downpayment_received_by <=> OLD.downpayment_received_by) OR NOT (NEW.downpayment_received_at <=> OLD.downpayment_received_at)) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A recorded downpayment cannot be changed';
    END IF;
END$$
DELIMITER ;
