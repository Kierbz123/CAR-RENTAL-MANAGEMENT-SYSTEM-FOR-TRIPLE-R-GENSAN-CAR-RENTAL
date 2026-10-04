-- Booking guards in the database, and the damage-photo guards restored.
--
-- 1. A vehicle can be out with one customer at a time, and a driver can drive one rental at a
--    time: at most one 'active' agreement per vehicle and per driver. The application already
--    keeps to this; the database now refuses anything that does not, whatever writes it.
-- 2. A rental is at most 366 days long. A typing mistake can no longer block a vehicle for years.
-- 3. Indexes for the conflict check, which now compares scheduled pickup and return times as
--    well as dates.
-- 4. The two triggers that keep damage photos unchangeable, recreated. Migration 022 drops and
--    recreates them; on a server where 022 stopped partway they may be missing.
--
-- bin/migrate.php runs each precheck below first and refuses to start while any returns a row.
-- precheck: SELECT CONCAT('vehicle ', vehicle_id, ' has ', COUNT(*), ' active agreements (', GROUP_CONCAT(agreement_id), ')') FROM rental_agreements WHERE status = 'active' GROUP BY vehicle_id HAVING COUNT(*) > 1
-- precheck: SELECT CONCAT('driver ', driver_id, ' has ', COUNT(*), ' active agreements (', GROUP_CONCAT(agreement_id), ')') FROM rental_agreements WHERE status = 'active' AND driver_id IS NOT NULL GROUP BY driver_id HAVING COUNT(*) > 1
-- precheck: SELECT CONCAT('agreement ', agreement_id, ' runs ', DATEDIFF(end_date, start_date), ' days') FROM rental_agreements WHERE DATEDIFF(end_date, start_date) > 366

ALTER TABLE rental_agreements
    ADD COLUMN active_vehicle_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN vehicle_id END) STORED,
    ADD COLUMN active_driver_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'active' THEN driver_id END) STORED,
    ADD UNIQUE KEY uq_rentals_one_active_per_vehicle (active_vehicle_id),
    ADD UNIQUE KEY uq_rentals_one_active_per_driver (active_driver_id),
    ADD KEY idx_rentals_vehicle_times (vehicle_id, status, scheduled_pickup_at, scheduled_return_at),
    ADD KEY idx_rentals_driver_times (driver_id, status, scheduled_pickup_at, scheduled_return_at),
    ADD CONSTRAINT chk_rentals_length CHECK (DATEDIFF(end_date, start_date) <= 366);

DROP TRIGGER IF EXISTS photos_guard_update;
DROP TRIGGER IF EXISTS photos_guard_delete;

DELIMITER $$
CREATE TRIGGER photos_guard_update BEFORE UPDATE ON photos FOR EACH ROW
BEGIN
    IF OLD.vehicle_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Damage photos are append-only';
    END IF;
    IF NOT (NEW.vehicle_id <=> OLD.vehicle_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A photo cannot be moved to another record';
    END IF;
END$$
CREATE TRIGGER photos_guard_delete BEFORE DELETE ON photos FOR EACH ROW
BEGIN
    IF OLD.vehicle_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Damage photos are append-only';
    END IF;
END$$
DELIMITER ;
