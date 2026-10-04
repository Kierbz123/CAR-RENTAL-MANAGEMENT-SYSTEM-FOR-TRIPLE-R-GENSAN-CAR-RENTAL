-- Who removed or restored a customer or a driver, when, and why. Append-only, like every history.
--
-- Customers and drivers are never deleted: removing one sets deleted_at, and it can now be
-- restored. Each removal and restoration is recorded here. A removed record keeps its identity
-- document or licence number, so the same person cannot be entered twice; staff restore the
-- removed record instead, which keeps its rental history attached.

CREATE TABLE record_lifecycle_logs (
    log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subject ENUM('customer','driver') NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    driver_id BIGINT UNSIGNED NULL,
    action ENUM('removed','restored') NOT NULL,
    reason VARCHAR(500) NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (log_id),
    KEY idx_record_lifecycle_customer (customer_id, created_at, log_id),
    KEY idx_record_lifecycle_driver (driver_id, created_at, log_id),
    CONSTRAINT fk_record_lifecycle_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_record_lifecycle_driver FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE RESTRICT,
    CONSTRAINT fk_record_lifecycle_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_record_lifecycle_owner CHECK (
        (subject = 'customer' AND customer_id IS NOT NULL AND driver_id IS NULL)
        OR (subject = 'driver' AND driver_id IS NOT NULL AND customer_id IS NULL)
    ),
    CONSTRAINT chk_record_lifecycle_reason CHECK (reason IS NULL OR CHAR_LENGTH(TRIM(reason)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

DELIMITER $$
CREATE TRIGGER record_lifecycle_logs_no_update BEFORE UPDATE ON record_lifecycle_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='record_lifecycle_logs is append-only'; END$$
CREATE TRIGGER record_lifecycle_logs_no_delete BEFORE DELETE ON record_lifecycle_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='record_lifecycle_logs is append-only'; END$$
DELIMITER ;
