-- Agreement vehicle/customer identity is used to discover the canonical row-lock order.
-- These foreign-key identities are immutable after creation; changing either requires
-- an explicit future business workflow and migration, not an ad-hoc reassignment.
DELIMITER //
CREATE TRIGGER rental_agreements_identity_immutable
BEFORE UPDATE ON rental_agreements
FOR EACH ROW
BEGIN
    IF NOT (NEW.vehicle_id <=> OLD.vehicle_id) OR NOT (NEW.customer_id <=> OLD.customer_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rental agreement vehicle/customer identity is immutable';
    END IF;
END//
DELIMITER ;
