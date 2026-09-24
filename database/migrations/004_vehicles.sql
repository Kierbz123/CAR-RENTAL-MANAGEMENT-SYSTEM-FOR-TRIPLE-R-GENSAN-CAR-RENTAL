CREATE TABLE vehicle_locations (
    location_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    -- Active/retired controls new selections; deleted_at is reserved for removal.
    location_status ENUM('active','retired') NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (location_id),
    UNIQUE KEY uq_vehicle_locations_name (name),
    KEY idx_vehicle_locations_selectable (location_status, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE vehicles (
    vehicle_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Engine/chassis may be NULL during onboarding; MySQL unique indexes allow multiple NULLs.
    plate_number VARCHAR(20) NOT NULL,
    engine_number VARCHAR(80) NULL,
    chassis_number VARCHAR(80) NULL,
    make VARCHAR(60) NOT NULL,
    model VARCHAR(80) NOT NULL,
    model_year SMALLINT NOT NULL,
    color VARCHAR(40) NOT NULL,
    body_type VARCHAR(30) NOT NULL,
    transmission ENUM('manual','automatic') NOT NULL,
    fuel_type ENUM('gasoline','diesel','hybrid') NOT NULL,
    seating_capacity TINYINT UNSIGNED NOT NULL,
    daily_rate DECIMAL(10,2) NOT NULL,
    chauffeur_daily_rate DECIMAL(10,2) NULL,
    current_status ENUM('available','rented','maintenance','reserved','cleaning','out_of_service','retired') NOT NULL DEFAULT 'available',
    current_mileage INT UNSIGNED NOT NULL DEFAULT 0,
    current_location_id BIGINT UNSIGNED NULL,
    registration_expiry DATE NULL,
    insurance_expiry DATE NULL,
    insurance_provider VARCHAR(80) NULL,
    notes TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (vehicle_id),
    UNIQUE KEY uq_vehicles_plate (plate_number),
    UNIQUE KEY uq_vehicles_engine (engine_number),
    UNIQUE KEY uq_vehicles_chassis (chassis_number),
    KEY idx_vehicles_fleet (current_status, deleted_at),
    KEY idx_vehicles_location (current_location_id),
    CONSTRAINT fk_vehicles_location FOREIGN KEY (current_location_id) REFERENCES vehicle_locations (location_id) ON DELETE RESTRICT,
    CONSTRAINT chk_vehicles_daily_rate CHECK (daily_rate >= 0),
    CONSTRAINT chk_vehicles_chauffeur_rate CHECK (chauffeur_daily_rate IS NULL OR chauffeur_daily_rate >= 0),
    CONSTRAINT chk_vehicles_seating CHECK (seating_capacity > 0),
    CONSTRAINT chk_vehicles_model_year CHECK (model_year > 0),
    CONSTRAINT chk_vehicles_body_type CHECK (body_type IN ('sedan','SUV','van','pickup','hatchback'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE vehicle_status_logs (
    status_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    old_status ENUM('available','rented','maintenance','reserved','cleaning','out_of_service','retired') NULL,
    new_status ENUM('available','rented','maintenance','reserved','cleaning','out_of_service','retired') NOT NULL,
    location_id BIGINT UNSIGNED NULL,
    mileage INT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (status_log_id),
    KEY idx_vehicle_status_history (vehicle_id, created_at, status_log_id),
    CONSTRAINT fk_vehicle_status_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (vehicle_id) ON DELETE RESTRICT,
    CONSTRAINT fk_vehicle_status_location FOREIGN KEY (location_id) REFERENCES vehicle_locations (location_id) ON DELETE RESTRICT,
    CONSTRAINT fk_vehicle_status_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE vehicle_mileage_logs (
    mileage_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    mileage INT UNSIGNED NOT NULL,
    recorded_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    location_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    correction_of_log_id BIGINT UNSIGNED NULL,
    correction_reason VARCHAR(500) NULL,
    PRIMARY KEY (mileage_log_id),
    UNIQUE KEY uq_vehicle_mileage_correction_target (correction_of_log_id),
    UNIQUE KEY uq_vehicle_mileage_vehicle_log (vehicle_id, mileage_log_id),
    KEY idx_vehicle_mileage_history (vehicle_id, recorded_at, mileage_log_id),
    CONSTRAINT fk_vehicle_mileage_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (vehicle_id) ON DELETE RESTRICT,
    CONSTRAINT fk_vehicle_mileage_location FOREIGN KEY (location_id) REFERENCES vehicle_locations (location_id) ON DELETE RESTRICT,
    CONSTRAINT fk_vehicle_mileage_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_vehicle_mileage_correction FOREIGN KEY (vehicle_id, correction_of_log_id) REFERENCES vehicle_mileage_logs (vehicle_id, mileage_log_id) ON DELETE RESTRICT,
    CONSTRAINT chk_vehicle_mileage_correction CHECK ((correction_of_log_id IS NULL AND correction_reason IS NULL) OR (correction_of_log_id IS NOT NULL AND correction_reason IS NOT NULL AND CHAR_LENGTH(TRIM(correction_reason)) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE vehicle_photos (
    photo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (photo_id),
    UNIQUE KEY uq_vehicle_photo_storage_path (storage_path),
    KEY idx_vehicle_photos_order (vehicle_id, sort_order, photo_id),
    CONSTRAINT fk_vehicle_photos_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles (vehicle_id) ON DELETE RESTRICT,
    CONSTRAINT fk_vehicle_photos_uploader FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_vehicle_photo_size CHECK (size_bytes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
CREATE TRIGGER vehicle_status_logs_no_update BEFORE UPDATE ON vehicle_status_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'vehicle_status_logs is append-only';
END$$
CREATE TRIGGER vehicle_status_logs_no_delete BEFORE DELETE ON vehicle_status_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'vehicle_status_logs is append-only';
END$$
CREATE TRIGGER vehicle_mileage_logs_no_update BEFORE UPDATE ON vehicle_mileage_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'vehicle_mileage_logs is append-only';
END$$
CREATE TRIGGER vehicle_mileage_logs_no_delete BEFORE DELETE ON vehicle_mileage_logs FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'vehicle_mileage_logs is append-only';
END$$
DELIMITER ;
