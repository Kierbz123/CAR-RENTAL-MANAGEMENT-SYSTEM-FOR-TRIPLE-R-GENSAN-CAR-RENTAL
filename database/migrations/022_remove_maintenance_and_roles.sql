-- Removes the Maintenance module and three staff roles: mechanic, auditor and support_staff.
-- A vehicle can still be put in the 'maintenance' status by hand from its page, and that
-- status stays in vehicles and in the vehicle history. What goes is the schedules, the service
-- records with their costs and photos, and the history of those. Odometer readings recorded
-- when a service was completed stay in vehicle_mileage_logs. Photo files already saved under
-- storage/maintenance are not touched.

-- 1. Staff roles. A staff account that has signed in cannot be deleted (its sessions and
-- security log rows point at it), so mechanics become fleet managers, who could already do
-- every maintenance step, and auditor and support accounts are deactivated the same way the
-- Staff accounts page does it. Everyone affected is signed out.
UPDATE sessions s JOIN users u ON u.id = s.user_id
    SET s.invalidated_at = UTC_TIMESTAMP(6)
    WHERE u.role IN ('mechanic','auditor','support_staff') AND s.invalidated_at IS NULL;
UPDATE users SET role = 'fleet_manager' WHERE role = 'mechanic';
UPDATE users SET role = 'driver_coordinator', is_active = 0, deleted_at = COALESCE(deleted_at, UTC_TIMESTAMP(6))
    WHERE role IN ('auditor','support_staff');
ALTER TABLE users
    MODIFY COLUMN role ENUM('system_admin','fleet_manager','front_desk','driver_coordinator','finance_staff') NOT NULL DEFAULT 'fleet_manager';

-- 2. photos: a photo now belongs to a vehicle or to a damage report.
DROP TRIGGER photos_guard_update;
DROP TRIGGER photos_guard_delete;
DELETE FROM photos WHERE maintenance_service_id IS NOT NULL;
ALTER TABLE photos
    DROP CHECK chk_photos_one_owner,
    DROP CHECK chk_photos_phase,
    DROP FOREIGN KEY fk_photos_maintenance_service;
ALTER TABLE photos
    DROP INDEX idx_photos_maintenance_service,
    DROP COLUMN maintenance_service_id,
    DROP COLUMN phase;
ALTER TABLE photos
    ADD CONSTRAINT chk_photos_one_owner CHECK ((vehicle_id IS NOT NULL) + (damage_report_id IS NOT NULL) = 1);

-- 3. status_logs: the history of vehicles, drivers, rentals and deposits.
DROP TRIGGER status_logs_no_update;
DROP TRIGGER status_logs_no_delete;
DELETE FROM status_logs WHERE subject = 'maintenance_service';
ALTER TABLE status_logs
    DROP CHECK chk_status_logs_owner,
    DROP CHECK chk_status_logs_statuses,
    DROP CHECK chk_status_logs_reason,
    DROP FOREIGN KEY fk_status_logs_maintenance_service;
ALTER TABLE status_logs
    DROP INDEX idx_status_logs_maintenance_service,
    DROP COLUMN maintenance_service_id,
    MODIFY COLUMN subject ENUM('vehicle','driver','rental','deposit') NOT NULL;
ALTER TABLE status_logs
    ADD CONSTRAINT chk_status_logs_owner CHECK (
        (subject = 'vehicle' AND vehicle_id IS NOT NULL AND driver_id IS NULL AND agreement_id IS NULL)
        OR (subject = 'driver' AND driver_id IS NOT NULL AND vehicle_id IS NULL AND agreement_id IS NULL)
        OR (subject IN ('rental','deposit') AND agreement_id IS NOT NULL AND vehicle_id IS NULL AND driver_id IS NULL)
    ),
    ADD CONSTRAINT chk_status_logs_statuses CHECK (
        (subject = 'vehicle' AND new_status IN ('available','rented','maintenance','reserved','cleaning','out_of_service','retired')
            AND (old_status IS NULL OR old_status IN ('available','rented','maintenance','reserved','cleaning','out_of_service','retired')))
        OR (subject = 'driver' AND new_status IN ('active','inactive')
            AND (old_status IS NULL OR old_status IN ('active','inactive')))
        OR (subject = 'rental' AND new_status IN ('reserved','confirmed','active','returned','completed','cancelled','no_show')
            AND (old_status IS NULL OR old_status IN ('reserved','confirmed','active','returned','completed','cancelled','no_show')))
        OR (subject = 'deposit' AND new_status IN ('not_required','due','held','released','refunded','forfeited')
            AND (old_status IS NULL OR old_status IN ('not_required','due','held','released','refunded','forfeited')))
    ),
    ADD CONSTRAINT chk_status_logs_reason CHECK (
        (subject IN ('vehicle','driver') AND reason IS NULL)
        OR (subject = 'rental' AND (new_status NOT IN ('cancelled','no_show') OR (reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0)))
        OR (subject = 'deposit' AND reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0)
    );

-- 4. The maintenance tables, children first. Their own triggers go with them.
DROP TABLE maintenance_cost_audit_logs;
DROP TABLE maintenance_schedule_logs;
DROP TABLE maintenance_services;
DROP TABLE maintenance_schedules;

DELIMITER $$
-- Damage photos are evidence and can never be changed. A vehicle photo may be reordered, but
-- it stays with its vehicle.
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
CREATE TRIGGER status_logs_no_update BEFORE UPDATE ON status_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='status_logs is append-only'; END$$
CREATE TRIGGER status_logs_no_delete BEFORE DELETE ON status_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='status_logs is append-only'; END$$
DELIMITER ;
