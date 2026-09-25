-- M5 rental lifecycle and costing.
-- start_date/end_date are intentionally Manila-calendar DATE values (not UTC DATETIME(6)):
-- billing is based on local calendar days and DATE avoids timezone conversion dependencies.
CREATE TABLE rental_agreements (
    agreement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    driver_id BIGINT UNSIGNED NULL,
    rental_type ENUM('self_drive','chauffeur') NOT NULL DEFAULT 'self_drive',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    scheduled_pickup_at DATETIME(6) NULL,
    scheduled_return_at DATETIME(6) NULL,
    actual_pickup_at DATETIME(6) NULL,
    actual_return_at DATETIME(6) NULL,
    daily_rate DECIMAL(10,2) NOT NULL,
    rental_days INT GENERATED ALWAYS AS (GREATEST(DATEDIFF(end_date,start_date),1)) STORED,
    base_amount DECIMAL(18,2) GENERATED ALWAYS AS (GREATEST(DATEDIFF(end_date,start_date),1) * daily_rate) STORED,
    security_deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    deposit_status ENUM('not_required','due','held','released','refunded','forfeited') NOT NULL DEFAULT 'not_required',
    hold_expires_at DATETIME(6) NULL,
    status ENUM('reserved','confirmed','active','returned','completed','cancelled','no_show') NOT NULL DEFAULT 'reserved',
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (agreement_id),
    KEY idx_rentals_vehicle_dates_status (vehicle_id,start_date,end_date,status),
    KEY idx_rentals_customer_status (customer_id,status),
    KEY idx_rentals_status_dates (status,start_date,end_date),
    KEY idx_rentals_driver_dates (driver_id,start_date,end_date,status),
    CONSTRAINT fk_rentals_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rentals_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rentals_driver FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rentals_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_rentals_dates CHECK (end_date >= start_date),
    CONSTRAINT chk_rentals_rate CHECK (daily_rate >= 0),
    CONSTRAINT chk_rentals_deposit CHECK (security_deposit_amount >= 0),
    CONSTRAINT chk_rentals_scheduled_times CHECK (scheduled_pickup_at IS NULL OR scheduled_return_at IS NULL OR scheduled_return_at >= scheduled_pickup_at),
    CONSTRAINT chk_rentals_actual_times CHECK (actual_pickup_at IS NULL OR actual_return_at IS NULL OR actual_return_at >= actual_pickup_at),
    CONSTRAINT chk_rentals_driver_type CHECK (rental_type <> 'self_drive' OR driver_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rental_charges (
    charge_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agreement_id BIGINT UNSIGNED NOT NULL,
    charge_type ENUM('fee','discount','tax','damage','chauffeur_fee','other') NOT NULL,
    entry_kind ENUM('charge','reversal') NOT NULL DEFAULT 'charge',
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(500) NOT NULL,
    reverses_charge_id BIGINT UNSIGNED NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (charge_id),
    UNIQUE KEY uq_rental_charge_reversal (reverses_charge_id),
    KEY idx_rental_charges_agreement (agreement_id,created_at,charge_id),
    CONSTRAINT fk_rental_charges_agreement FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rental_charges_reversal FOREIGN KEY (reverses_charge_id) REFERENCES rental_charges(charge_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rental_charges_actor FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_rental_charge_amount CHECK (amount > 0),
    CONSTRAINT chk_rental_charge_description CHECK (CHAR_LENGTH(TRIM(description))>0),
    CONSTRAINT chk_rental_charge_reversal CHECK ((entry_kind='charge' AND reverses_charge_id IS NULL) OR (entry_kind='reversal' AND reverses_charge_id IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rental_status_logs (
    status_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agreement_id BIGINT UNSIGNED NOT NULL,
    old_status ENUM('reserved','confirmed','active','returned','completed','cancelled','no_show') NULL,
    new_status ENUM('reserved','confirmed','active','returned','completed','cancelled','no_show') NOT NULL,
    reason VARCHAR(500) NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(status_log_id), KEY idx_rental_status_history(agreement_id,created_at,status_log_id),
    CONSTRAINT fk_rental_status_agreement FOREIGN KEY(agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rental_status_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_rental_status_reason CHECK ((new_status IN ('cancelled','no_show') AND reason IS NOT NULL AND CHAR_LENGTH(TRIM(reason))>0) OR (new_status NOT IN ('cancelled','no_show')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE deposit_status_logs (
    deposit_log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agreement_id BIGINT UNSIGNED NOT NULL,
    old_status ENUM('not_required','due','held','released','refunded','forfeited') NULL,
    new_status ENUM('not_required','due','held','released','refunded','forfeited') NOT NULL,
    old_amount DECIMAL(10,2) NULL,
    new_amount DECIMAL(10,2) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(deposit_log_id), KEY idx_deposit_history(agreement_id,created_at,deposit_log_id),
    CONSTRAINT fk_deposit_log_agreement FOREIGN KEY(agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    CONSTRAINT fk_deposit_log_actor FOREIGN KEY(actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_deposit_log_amount CHECK (new_amount>=0),
    CONSTRAINT chk_deposit_log_reason CHECK (CHAR_LENGTH(TRIM(reason))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rules_acceptances (
    acceptance_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone VARCHAR(20) NOT NULL,
    action ENUM('revoked') NOT NULL DEFAULT 'revoked',
    inbound_sms_event_id BIGINT UNSIGNED NOT NULL,
    provider_message_id VARCHAR(191) NOT NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY(acceptance_id),
    UNIQUE KEY uq_rules_provider_msg(phone,provider_message_id),
    UNIQUE KEY uq_rules_event(inbound_sms_event_id),
    KEY idx_rules_phone_action(phone,action,recorded_at),
    CONSTRAINT fk_rules_inbound_event FOREIGN KEY(inbound_sms_event_id) REFERENCES inbound_sms_events(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE booking_access_tokens
    ADD KEY idx_booking_tokens_booking (booking_id),
    ADD CONSTRAINT fk_booking_tokens_rental FOREIGN KEY (booking_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT;

ALTER TABLE notifications MODIFY status ENUM('queued','sending','sent','failed','suppressed','suppressed_by_policy') NOT NULL DEFAULT 'queued';

DELIMITER $$
CREATE TRIGGER rental_charges_no_update BEFORE UPDATE ON rental_charges FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rental_charges is append-only; record a reversal'; END$$
CREATE TRIGGER rental_charges_no_delete BEFORE DELETE ON rental_charges FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rental_charges is append-only'; END$$
CREATE TRIGGER rental_status_logs_no_update BEFORE UPDATE ON rental_status_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rental_status_logs is append-only'; END$$
CREATE TRIGGER rental_status_logs_no_delete BEFORE DELETE ON rental_status_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rental_status_logs is append-only'; END$$
CREATE TRIGGER deposit_status_logs_no_update BEFORE UPDATE ON deposit_status_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='deposit_status_logs is append-only'; END$$
CREATE TRIGGER deposit_status_logs_no_delete BEFORE DELETE ON deposit_status_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='deposit_status_logs is append-only'; END$$
CREATE TRIGGER rules_acceptances_no_update BEFORE UPDATE ON rules_acceptances FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rules_acceptances is append-only'; END$$
CREATE TRIGGER rules_acceptances_no_delete BEFORE DELETE ON rules_acceptances FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rules_acceptances is append-only'; END$$
DELIMITER ;
