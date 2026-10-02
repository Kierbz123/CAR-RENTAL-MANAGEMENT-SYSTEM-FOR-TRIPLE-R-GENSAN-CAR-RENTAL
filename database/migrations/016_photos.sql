-- Schema consolidation, step 4: one table for every stored photo.
-- vehicle_photos, damage_photos and maintenance_photos held the same facts about a file
-- (where it is stored, its original name, type, size, who uploaded it, when) and differed
-- only in what the photo belongs to. In photos, each kind of owner keeps its own column with
-- a real foreign key, and exactly one of them is set. Damage and maintenance photos stay
-- append-only. Photo numbers change, because the three tables numbered their rows separately;
-- nothing stores a photo number. See docs/SCHEMA_CONSOLIDATION_PLAN.md.

CREATE TABLE photos (
    photo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id BIGINT UNSIGNED NULL,
    damage_report_id BIGINT UNSIGNED NULL,
    maintenance_service_id BIGINT UNSIGNED NULL,
    phase ENUM('before','after') NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    storage_path VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (photo_id),
    UNIQUE KEY uq_photos_storage_path (storage_path),
    KEY idx_photos_vehicle (vehicle_id, sort_order, photo_id),
    KEY idx_photos_damage_report (damage_report_id, photo_id),
    KEY idx_photos_maintenance_service (maintenance_service_id, phase, photo_id),
    CONSTRAINT fk_photos_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE RESTRICT,
    CONSTRAINT fk_photos_damage_report FOREIGN KEY (damage_report_id) REFERENCES damage_reports(report_id) ON DELETE RESTRICT,
    CONSTRAINT fk_photos_maintenance_service FOREIGN KEY (maintenance_service_id) REFERENCES maintenance_services(service_id) ON DELETE RESTRICT,
    CONSTRAINT fk_photos_uploader FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_photos_one_owner CHECK ((vehicle_id IS NOT NULL) + (damage_report_id IS NOT NULL) + (maintenance_service_id IS NOT NULL) = 1),
    CONSTRAINT chk_photos_phase CHECK ((maintenance_service_id IS NOT NULL) = (phase IS NOT NULL)),
    CONSTRAINT chk_photos_size CHECK (size_bytes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO photos (vehicle_id, sort_order, storage_path, original_filename, mime, size_bytes, uploaded_by, created_at)
    SELECT vehicle_id, sort_order, storage_path, original_filename, mime, size_bytes, uploaded_by, created_at
    FROM vehicle_photos ORDER BY photo_id;

INSERT INTO photos (damage_report_id, storage_path, original_filename, mime, size_bytes, uploaded_by, created_at)
    SELECT report_id, storage_path, original_filename, mime, size_bytes, uploaded_by, created_at
    FROM damage_photos ORDER BY photo_id;

INSERT INTO photos (maintenance_service_id, phase, storage_path, original_filename, mime, size_bytes, uploaded_by, created_at)
    SELECT service_id, phase, storage_path, original_filename, mime, size_bytes, uploaded_by, created_at
    FROM maintenance_photos ORDER BY photo_id;

-- Stop here, before anything is dropped, unless every photo row was copied.
CREATE TABLE migration_016_check (
    copied_all TINYINT NOT NULL,
    CONSTRAINT chk_migration_016_copied_all CHECK (copied_all = 1)
) ENGINE=InnoDB;
INSERT INTO migration_016_check (copied_all)
    SELECT (SELECT COUNT(*) FROM vehicle_photos) = (SELECT COUNT(*) FROM photos WHERE vehicle_id IS NOT NULL)
       AND (SELECT COUNT(*) FROM damage_photos) = (SELECT COUNT(*) FROM photos WHERE damage_report_id IS NOT NULL)
       AND (SELECT COUNT(*) FROM maintenance_photos) = (SELECT COUNT(*) FROM photos WHERE maintenance_service_id IS NOT NULL)
       AND (SELECT COALESCE(SUM(size_bytes), 0) FROM vehicle_photos) + (SELECT COALESCE(SUM(size_bytes), 0) FROM damage_photos) + (SELECT COALESCE(SUM(size_bytes), 0) FROM maintenance_photos)
           = (SELECT COALESCE(SUM(size_bytes), 0) FROM photos);
DROP TABLE migration_016_check;

DROP TABLE vehicle_photos;
DROP TABLE damage_photos;
DROP TABLE maintenance_photos;

DELIMITER $$
-- Damage and maintenance photos are evidence and can never be changed. A vehicle photo may
-- be reordered, but it stays with its vehicle.
CREATE TRIGGER photos_guard_update BEFORE UPDATE ON photos FOR EACH ROW
BEGIN
    IF OLD.vehicle_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Damage and maintenance photos are append-only';
    END IF;
    IF NOT (NEW.vehicle_id <=> OLD.vehicle_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A photo cannot be moved to another record';
    END IF;
END$$
CREATE TRIGGER photos_guard_delete BEFORE DELETE ON photos FOR EACH ROW
BEGIN
    IF OLD.vehicle_id IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Damage and maintenance photos are append-only';
    END IF;
END$$
DELIMITER ;
