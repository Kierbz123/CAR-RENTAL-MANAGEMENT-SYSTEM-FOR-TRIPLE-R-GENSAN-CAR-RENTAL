-- Enforce FR-05 at the database level:
ALTER TABLE rental_agreements ADD CONSTRAINT chk_rentals_chauffeur_driver 
    CHECK (rental_type<>'chauffeur' OR status IN ('reserved','cancelled','no_show') OR driver_id IS NOT NULL);

-- Protect driver_id from reassignment once active/returned/completed.
-- Uses NULL-safe <=> to ensure we only reject actual driver_id changes, 
-- allowing normal lifecycle updates (pickup, return, deposits) to proceed safely.
DELIMITER //
CREATE TRIGGER rental_agreements_driver_immutable
BEFORE UPDATE ON rental_agreements
FOR EACH ROW
BEGIN
    IF NOT (NEW.driver_id <=> OLD.driver_id) AND OLD.status NOT IN ('reserved', 'confirmed') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Rental agreement driver assignment is immutable after confirmation/pickup';
    END IF;
END//
DELIMITER ;
