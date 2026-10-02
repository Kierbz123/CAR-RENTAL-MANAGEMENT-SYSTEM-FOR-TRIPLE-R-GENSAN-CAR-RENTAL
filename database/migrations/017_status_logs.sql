-- Schema consolidation, step 5: one history table for every status change.
-- vehicle_status_logs, driver_status_logs, rental_status_logs, deposit_status_logs and
-- maintenance_service_status_logs all recorded "this record went from one status to another,
-- changed by this person at this time". In status_logs, subject says which kind of status it
-- is, each kind of record keeps its own column with a real foreign key, and CHECK rules keep
-- what the separate tables guaranteed: the allowed statuses of each kind, the reasons that
-- are mandatory, and the facts only one kind carries (deposit amounts, a vehicle's location
-- and mileage). The table is append-only. Log numbers change, because the five tables
-- numbered their rows separately; nothing stores a log number.
-- See docs/SCHEMA_CONSOLIDATION_PLAN.md.

CREATE TABLE status_logs (
    status_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject ENUM('vehicle','driver','rental','deposit','maintenance_service') NOT NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    driver_id BIGINT UNSIGNED NULL,
    agreement_id BIGINT UNSIGNED NULL,
    maintenance_service_id BIGINT UNSIGNED NULL,
    old_status VARCHAR(20) NULL,
    new_status VARCHAR(20) NOT NULL,
    reason VARCHAR(500) NULL,
    old_amount DECIMAL(10,2) NULL,
    new_amount DECIMAL(10,2) NULL,
    location_id BIGINT UNSIGNED NULL,
    mileage INT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (status_log_id),
    KEY idx_status_logs_vehicle (vehicle_id, created_at, status_log_id),
    KEY idx_status_logs_driver (driver_id, created_at, status_log_id),
    KEY idx_status_logs_agreement (agreement_id, subject, created_at, status_log_id),
    KEY idx_status_logs_maintenance_service (maintenance_service_id, created_at, status_log_id),
    CONSTRAINT fk_status_logs_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE RESTRICT,
    CONSTRAINT fk_status_logs_driver FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE RESTRICT,
    CONSTRAINT fk_status_logs_agreement FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    CONSTRAINT fk_status_logs_maintenance_service FOREIGN KEY (maintenance_service_id) REFERENCES maintenance_services(service_id) ON DELETE RESTRICT,
    CONSTRAINT fk_status_logs_location FOREIGN KEY (location_id) REFERENCES vehicle_locations(location_id) ON DELETE RESTRICT,
    CONSTRAINT fk_status_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_status_logs_owner CHECK (
        (subject = 'vehicle' AND vehicle_id IS NOT NULL AND driver_id IS NULL AND agreement_id IS NULL AND maintenance_service_id IS NULL)
        OR (subject = 'driver' AND driver_id IS NOT NULL AND vehicle_id IS NULL AND agreement_id IS NULL AND maintenance_service_id IS NULL)
        OR (subject IN ('rental','deposit') AND agreement_id IS NOT NULL AND vehicle_id IS NULL AND driver_id IS NULL AND maintenance_service_id IS NULL)
        OR (subject = 'maintenance_service' AND maintenance_service_id IS NOT NULL AND vehicle_id IS NULL AND driver_id IS NULL AND agreement_id IS NULL)
    ),
    CONSTRAINT chk_status_logs_statuses CHECK (
        (subject = 'vehicle' AND new_status IN ('available','rented','maintenance','reserved','cleaning','out_of_service','retired')
            AND (old_status IS NULL OR old_status IN ('available','rented','maintenance','reserved','cleaning','out_of_service','retired')))
        OR (subject = 'driver' AND new_status IN ('active','inactive')
            AND (old_status IS NULL OR old_status IN ('active','inactive')))
        OR (subject = 'rental' AND new_status IN ('reserved','confirmed','active','returned','completed','cancelled','no_show')
            AND (old_status IS NULL OR old_status IN ('reserved','confirmed','active','returned','completed','cancelled','no_show')))
        OR (subject = 'deposit' AND new_status IN ('not_required','due','held','released','refunded','forfeited')
            AND (old_status IS NULL OR old_status IN ('not_required','due','held','released','refunded','forfeited')))
        OR (subject = 'maintenance_service' AND new_status IN ('in_progress','completed','cancelled')
            AND (old_status IS NULL OR old_status IN ('in_progress','completed','cancelled')))
    ),
    CONSTRAINT chk_status_logs_reason CHECK (
        (subject IN ('vehicle','driver') AND reason IS NULL)
        OR (subject = 'rental' AND (new_status NOT IN ('cancelled','no_show') OR (reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0)))
        OR (subject = 'deposit' AND reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0)
        OR (subject = 'maintenance_service' AND (new_status <> 'cancelled' OR (reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason)) > 0)))
    ),
    CONSTRAINT chk_status_logs_amounts CHECK (
        (subject = 'deposit' AND new_amount IS NOT NULL AND new_amount >= 0)
        OR (subject <> 'deposit' AND old_amount IS NULL AND new_amount IS NULL)
    ),
    CONSTRAINT chk_status_logs_vehicle_facts CHECK (subject = 'vehicle' OR (location_id IS NULL AND mileage IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Copied in time order, so the new log numbers follow the order things happened.
INSERT INTO status_logs (subject, vehicle_id, driver_id, agreement_id, maintenance_service_id, old_status, new_status, reason, old_amount, new_amount, location_id, mileage, actor_user_id, created_at)
    SELECT subject, vehicle_id, driver_id, agreement_id, maintenance_service_id, old_status, new_status, reason, old_amount, new_amount, location_id, mileage, actor_user_id, created_at
    FROM (
        SELECT 'vehicle' AS subject, 1 AS source_order, status_log_id AS source_id, vehicle_id, NULL AS driver_id, NULL AS agreement_id, NULL AS maintenance_service_id,
               CAST(old_status AS CHAR) AS old_status, CAST(new_status AS CHAR) AS new_status, NULL AS reason, NULL AS old_amount, NULL AS new_amount, location_id, mileage, actor_user_id, created_at
            FROM vehicle_status_logs
        UNION ALL
        SELECT 'driver', 2, status_log_id, NULL, driver_id, NULL, NULL, CAST(old_status AS CHAR), CAST(new_status AS CHAR), NULL, NULL, NULL, NULL, NULL, actor_user_id, created_at
            FROM driver_status_logs
        UNION ALL
        SELECT 'rental', 3, status_log_id, NULL, NULL, agreement_id, NULL, CAST(old_status AS CHAR), CAST(new_status AS CHAR), reason, NULL, NULL, NULL, NULL, actor_user_id, created_at
            FROM rental_status_logs
        UNION ALL
        SELECT 'deposit', 4, deposit_log_id, NULL, NULL, agreement_id, NULL, CAST(old_status AS CHAR), CAST(new_status AS CHAR), reason, old_amount, new_amount, NULL, NULL, actor_user_id, created_at
            FROM deposit_status_logs
        UNION ALL
        SELECT 'maintenance_service', 5, status_log_id, NULL, NULL, NULL, service_id, CAST(old_status AS CHAR), CAST(new_status AS CHAR), reason, NULL, NULL, NULL, NULL, actor_user_id, created_at
            FROM maintenance_service_status_logs
    ) AS every_log
    ORDER BY created_at, source_order, source_id;

-- Stop here, before anything is dropped, unless every log row was copied.
CREATE TABLE migration_017_check (
    copied_all TINYINT NOT NULL,
    CONSTRAINT chk_migration_017_copied_all CHECK (copied_all = 1)
) ENGINE=InnoDB;
INSERT INTO migration_017_check (copied_all)
    SELECT (SELECT COUNT(*) FROM vehicle_status_logs) = (SELECT COUNT(*) FROM status_logs WHERE subject = 'vehicle')
       AND (SELECT COUNT(*) FROM driver_status_logs) = (SELECT COUNT(*) FROM status_logs WHERE subject = 'driver')
       AND (SELECT COUNT(*) FROM rental_status_logs) = (SELECT COUNT(*) FROM status_logs WHERE subject = 'rental')
       AND (SELECT COUNT(*) FROM deposit_status_logs) = (SELECT COUNT(*) FROM status_logs WHERE subject = 'deposit')
       AND (SELECT COUNT(*) FROM maintenance_service_status_logs) = (SELECT COUNT(*) FROM status_logs WHERE subject = 'maintenance_service')
       AND (SELECT COALESCE(SUM(new_amount), 0) FROM deposit_status_logs) = (SELECT COALESCE(SUM(new_amount), 0) FROM status_logs);
DROP TABLE migration_017_check;

DROP TABLE vehicle_status_logs;
DROP TABLE driver_status_logs;
DROP TABLE rental_status_logs;
DROP TABLE deposit_status_logs;
DROP TABLE maintenance_service_status_logs;

DELIMITER $$
CREATE TRIGGER status_logs_no_update BEFORE UPDATE ON status_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='status_logs is append-only'; END$$
CREATE TRIGGER status_logs_no_delete BEFORE DELETE ON status_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='status_logs is append-only'; END$$
DELIMITER ;
