CREATE TABLE maintenance_schedules (
    schedule_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    schedule_name VARCHAR(100) NOT NULL,
    interval_time_days INT UNSIGNED NULL,
    interval_mileage INT UNSIGNED NULL,
    next_due_date DATE NULL,
    next_due_mileage INT UNSIGNED NULL,
    due_soon_days_override SMALLINT UNSIGNED NULL,
    due_soon_mileage_override INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (schedule_id),
    UNIQUE KEY uq_maintenance_vehicle_name (vehicle_id, schedule_name),
    UNIQUE KEY uq_maintenance_vehicle_schedule (vehicle_id, schedule_id),
    KEY idx_maintenance_due_date (is_active, next_due_date, vehicle_id),
    KEY idx_maintenance_due_mileage (is_active, next_due_mileage, vehicle_id),
    CONSTRAINT fk_maintenance_schedule_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_schedule_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_schedule_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_maintenance_schedule_intervals CHECK (
        (interval_time_days IS NULL OR interval_time_days > 0)
        AND (interval_mileage IS NULL OR interval_mileage > 0)
        AND (interval_time_days IS NOT NULL OR interval_mileage IS NOT NULL)
    ),
    CONSTRAINT chk_maintenance_schedule_due_date CHECK (
        (interval_time_days IS NULL AND next_due_date IS NULL)
        OR (interval_time_days IS NOT NULL AND next_due_date IS NOT NULL)
    ),
    CONSTRAINT chk_maintenance_schedule_due_mileage CHECK (
        (interval_mileage IS NULL AND next_due_mileage IS NULL)
        OR (interval_mileage IS NOT NULL AND next_due_mileage IS NOT NULL)
    ),
    CONSTRAINT chk_maintenance_schedule_overrides CHECK (
        (due_soon_days_override IS NULL OR due_soon_days_override > 0)
        AND (due_soon_mileage_override IS NULL OR due_soon_mileage_override > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE maintenance_services (
    service_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    schedule_id BIGINT UNSIGNED NULL,
    mechanic_id BIGINT UNSIGNED NOT NULL,
    status ENUM('in_progress','completed','cancelled') NOT NULL DEFAULT 'in_progress',
    active_vehicle_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status='in_progress' THEN vehicle_id ELSE NULL END) STORED,
    labor_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    parts_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    other_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_cost DECIMAL(11,2) GENERATED ALWAYS AS (labor_cost + parts_cost + other_cost) STORED,
    vehicle_status_before ENUM('available','rented','maintenance','reserved','cleaning','out_of_service','retired') NOT NULL,
    completion_mileage_log_id BIGINT UNSIGNED NULL,
    title VARCHAR(160) NOT NULL,
    notes VARCHAR(2000) NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) NULL,
    cancelled_at DATETIME(6) NULL,
    cancel_reason VARCHAR(500) NULL,
    needs_review TINYINT(1) NOT NULL DEFAULT 0,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME(6) NULL,
    review_reason VARCHAR(500) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (service_id),
    UNIQUE KEY uq_maintenance_one_active_service (active_vehicle_id),
    UNIQUE KEY uq_maintenance_completion_mileage (completion_mileage_log_id),
    KEY idx_maintenance_service_vehicle (vehicle_id, started_at, service_id),
    KEY idx_maintenance_service_schedule (schedule_id, status, completed_at),
    KEY idx_maintenance_needs_review (needs_review, vehicle_id),
    CONSTRAINT fk_maintenance_service_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_service_schedule FOREIGN KEY (vehicle_id, schedule_id) REFERENCES maintenance_schedules(vehicle_id, schedule_id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_service_mechanic FOREIGN KEY (mechanic_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_service_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_service_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_service_mileage FOREIGN KEY (vehicle_id, completion_mileage_log_id) REFERENCES vehicle_mileage_logs(vehicle_id, mileage_log_id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_service_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_maintenance_service_costs CHECK (labor_cost >= 0 AND parts_cost >= 0 AND other_cost >= 0),
    CONSTRAINT chk_maintenance_service_status_dates CHECK (
        (status='in_progress' AND completed_at IS NULL AND cancelled_at IS NULL AND completion_mileage_log_id IS NULL)
        OR (status='completed' AND completed_at IS NOT NULL AND cancelled_at IS NULL AND completion_mileage_log_id IS NOT NULL)
        OR (status='cancelled' AND cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL AND CHAR_LENGTH(TRIM(cancel_reason))>0 AND completed_at IS NULL AND completion_mileage_log_id IS NULL)
    ),
    CONSTRAINT chk_maintenance_service_review CHECK (
        (needs_review=0 AND reviewed_by IS NULL AND reviewed_at IS NULL AND review_reason IS NULL)
        OR (needs_review=1 AND reviewed_by IS NULL AND reviewed_at IS NULL AND review_reason IS NULL)
        OR (needs_review=0 AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND review_reason IS NOT NULL AND CHAR_LENGTH(TRIM(review_reason))>0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE maintenance_photos (
    photo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id BIGINT UNSIGNED NOT NULL,
    phase ENUM('before','after') NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime VARCHAR(40) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (photo_id),
    UNIQUE KEY uq_maintenance_photo_path (storage_path),
    KEY idx_maintenance_photo_phase (service_id, phase, photo_id),
    CONSTRAINT fk_maintenance_photo_service FOREIGN KEY (service_id) REFERENCES maintenance_services(service_id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_photo_actor FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_maintenance_photo_size CHECK (size_bytes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE maintenance_service_status_logs (
    status_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id BIGINT UNSIGNED NOT NULL,
    old_status ENUM('in_progress','completed','cancelled') NULL,
    new_status ENUM('in_progress','completed','cancelled') NOT NULL,
    reason VARCHAR(500) NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (status_log_id),
    KEY idx_maintenance_service_status_log (service_id, created_at, status_log_id),
    CONSTRAINT fk_maintenance_status_log_service FOREIGN KEY (service_id) REFERENCES maintenance_services(service_id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_status_log_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_maintenance_cancel_reason CHECK (new_status<>'cancelled' OR (reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason))>0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE maintenance_schedule_logs (
    schedule_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    schedule_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    old_schedule_name VARCHAR(100) NULL,
    new_schedule_name VARCHAR(100) NULL,
    old_interval_time_days INT UNSIGNED NULL,
    new_interval_time_days INT UNSIGNED NULL,
    old_interval_mileage INT UNSIGNED NULL,
    new_interval_mileage INT UNSIGNED NULL,
    old_next_due_date DATE NULL,
    new_next_due_date DATE NULL,
    old_next_due_mileage INT UNSIGNED NULL,
    new_next_due_mileage INT UNSIGNED NULL,
    old_due_soon_days_override SMALLINT UNSIGNED NULL,
    new_due_soon_days_override SMALLINT UNSIGNED NULL,
    old_due_soon_mileage_override INT UNSIGNED NULL,
    new_due_soon_mileage_override INT UNSIGNED NULL,
    old_is_active TINYINT(1) NULL,
    new_is_active TINYINT(1) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (schedule_log_id),
    KEY idx_maintenance_schedule_log (schedule_id, created_at, schedule_log_id),
    CONSTRAINT fk_maintenance_schedule_log_schedule FOREIGN KEY (schedule_id) REFERENCES maintenance_schedules(schedule_id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_schedule_log_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_maintenance_schedule_log_reason CHECK (CHAR_LENGTH(TRIM(reason))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE maintenance_cost_audit_logs (
    cost_audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    service_id BIGINT UNSIGNED NOT NULL,
    old_labor_cost DECIMAL(10,2) NOT NULL,
    new_labor_cost DECIMAL(10,2) NOT NULL,
    old_parts_cost DECIMAL(10,2) NOT NULL,
    new_parts_cost DECIMAL(10,2) NOT NULL,
    old_other_cost DECIMAL(10,2) NOT NULL,
    new_other_cost DECIMAL(10,2) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (cost_audit_id),
    KEY idx_maintenance_cost_audit (service_id, created_at, cost_audit_id),
    CONSTRAINT fk_maintenance_cost_audit_service FOREIGN KEY (service_id) REFERENCES maintenance_services(service_id) ON DELETE RESTRICT,
    CONSTRAINT fk_maintenance_cost_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_maintenance_cost_audit_reason CHECK (CHAR_LENGTH(TRIM(reason))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
CREATE TRIGGER maintenance_service_status_logs_no_update BEFORE UPDATE ON maintenance_service_status_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Maintenance service status logs are append-only'; END$$
CREATE TRIGGER maintenance_service_status_logs_no_delete BEFORE DELETE ON maintenance_service_status_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Maintenance service status logs are append-only'; END$$
CREATE TRIGGER maintenance_photos_no_update BEFORE UPDATE ON maintenance_photos FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Maintenance photos are append-only'; END$$
CREATE TRIGGER maintenance_photos_no_delete BEFORE DELETE ON maintenance_photos FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Maintenance photos are append-only'; END$$
CREATE TRIGGER maintenance_schedule_logs_no_update BEFORE UPDATE ON maintenance_schedule_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Maintenance schedule logs are append-only'; END$$
CREATE TRIGGER maintenance_schedule_logs_no_delete BEFORE DELETE ON maintenance_schedule_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Maintenance schedule logs are append-only'; END$$
CREATE TRIGGER maintenance_cost_audit_no_update BEFORE UPDATE ON maintenance_cost_audit_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Maintenance cost audit logs are append-only'; END$$
CREATE TRIGGER maintenance_cost_audit_no_delete BEFORE DELETE ON maintenance_cost_audit_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Maintenance cost audit logs are append-only'; END$$
DELIMITER ;
