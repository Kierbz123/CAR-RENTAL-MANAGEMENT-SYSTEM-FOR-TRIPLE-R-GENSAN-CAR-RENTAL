-- The chauffeur rate is fixed on the agreement when it is booked, as the vehicle rate already is.
--
-- The 30% downpayment is worked out at booking from the whole rental cost: the vehicle rate and,
-- for a chauffeur rental, the chauffeur rate, for every day billed. The chauffeur fee is posted
-- later, when a driver is assigned. Keeping the rate on the agreement makes both use the same
-- number even if the vehicle's chauffeur rate changes in between.
--
-- Agreements booked before this migration keep the downpayment they were given; their chauffeur
-- rate is filled in from the vehicle as it is now.

ALTER TABLE rental_agreements
    ADD COLUMN chauffeur_daily_rate DECIMAL(10,2) NULL AFTER daily_rate,
    ADD CONSTRAINT chk_rentals_chauffeur_rate CHECK (chauffeur_daily_rate IS NULL OR chauffeur_daily_rate >= 0);

UPDATE rental_agreements r
    JOIN vehicles v ON v.vehicle_id = r.vehicle_id
    SET r.chauffeur_daily_rate = v.chauffeur_daily_rate
    WHERE r.rental_type = 'chauffeur';

-- Neither rate changes after booking.
DELIMITER $$
CREATE TRIGGER rental_agreements_rates_fixed BEFORE UPDATE ON rental_agreements FOR EACH ROW
BEGIN
    IF NOT (NEW.daily_rate <=> OLD.daily_rate) OR NOT (NEW.chauffeur_daily_rate <=> OLD.chauffeur_daily_rate) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The rates of a booking are fixed when it is made';
    END IF;
END$$
DELIMITER ;
