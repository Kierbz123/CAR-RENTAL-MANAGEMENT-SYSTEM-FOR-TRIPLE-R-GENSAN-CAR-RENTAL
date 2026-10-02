-- Triple R Gensan Car Rental: canonical clean-install schema.
-- Target: MySQL 8.0+ / InnoDB. Import into an empty database, for example:
--   mysql --host=127.0.0.1 --port=3306 --user=triple_r_migrate --database=triple_r_rental < database/schema.sql
-- Select/create the target database before importing; this file never switches databases.
-- No data-bearing legacy object is dropped by this file. Historical migrations remain
-- immutable and are checksum-recorded below so bin/migrate.php verifies and skips them.

-- ==========================================
-- USERS, AUTHENTICATION, AND SECURITY
-- ==========================================

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('system_admin','fleet_manager','front_desk','driver_coordinator','mechanic','finance_staff','auditor','support_staff') NOT NULL DEFAULT 'fleet_manager',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_at DATETIME(6) NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_active (role,is_active),
    KEY idx_users_active_role (is_active,role,deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Every counter that limits how often something may happen, told apart by scope:
--   throttle            counter_key = the limiter's hashed key; the window restarts in place
--   message_daily       counter_key = "<phone>|<date>"; one row per phone per day
--   magic_link_booking  counter_key = the agreement id; counts secure links for the booking's life
CREATE TABLE rate_counters (
    scope VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    counter_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_started_at DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, counter_key),
    CONSTRAINT chk_rate_counters_scope CHECK (scope IN ('throttle','message_daily','magic_link_booking'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NOT NULL,
    invalidated_at DATETIME(6) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sessions_hash (session_hash),
    KEY idx_sessions_user_active (user_id,invalidated_at,expires_at),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE security_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NULL,
    subject_user_id BIGINT UNSIGNED NULL,
    token_id BIGINT UNSIGNED NULL,
    email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    event_type VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_security_logs_actor_time (actor_user_id,created_at),
    KEY idx_security_logs_subject_time (subject_user_id,created_at),
    KEY idx_security_logs_event_time (event_type,created_at),
    KEY idx_security_logs_token_time (token_id,created_at),
    CONSTRAINT fk_security_logs_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_security_logs_subject FOREIGN KEY (subject_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ==========================================
-- VEHICLE LOCATIONS AND FLEET
-- ==========================================

CREATE TABLE vehicle_locations (
    location_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    location_status ENUM('active','retired') NOT NULL DEFAULT 'active',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (location_id),
    UNIQUE KEY uq_vehicle_locations_name (name),
    KEY idx_vehicle_locations_selectable (location_status,deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE vehicles (
    vehicle_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
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
    KEY idx_vehicles_fleet (current_status,deleted_at),
    KEY idx_vehicles_location (current_location_id),
    CONSTRAINT fk_vehicles_location FOREIGN KEY (current_location_id) REFERENCES vehicle_locations(location_id) ON DELETE RESTRICT,
    CONSTRAINT chk_vehicles_daily_rate CHECK (daily_rate >= 0),
    CONSTRAINT chk_vehicles_chauffeur_rate CHECK (chauffeur_daily_rate IS NULL OR chauffeur_daily_rate >= 0),
    CONSTRAINT chk_vehicles_seating CHECK (seating_capacity > 0),
    CONSTRAINT chk_vehicles_model_year CHECK (model_year > 0),
    CONSTRAINT chk_vehicles_body_type CHECK (body_type IN ('sedan','SUV','van','pickup','hatchback'))
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
    UNIQUE KEY uq_vehicle_mileage_vehicle_log (vehicle_id,mileage_log_id),
    KEY idx_vehicle_mileage_history (vehicle_id,recorded_at,mileage_log_id),
    CONSTRAINT fk_vehicle_mileage_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE RESTRICT,
    CONSTRAINT fk_vehicle_mileage_location FOREIGN KEY (location_id) REFERENCES vehicle_locations(location_id) ON DELETE RESTRICT,
    CONSTRAINT fk_vehicle_mileage_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_vehicle_mileage_correction FOREIGN KEY (vehicle_id,correction_of_log_id) REFERENCES vehicle_mileage_logs(vehicle_id,mileage_log_id) ON DELETE RESTRICT,
    CONSTRAINT chk_vehicle_mileage_correction CHECK ((correction_of_log_id IS NULL AND correction_reason IS NULL) OR (correction_of_log_id IS NOT NULL AND correction_reason IS NOT NULL AND CHAR_LENGTH(TRIM(correction_reason)) > 0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ==========================================
-- CUSTOMERS AND CUSTOMER PII
-- ==========================================

CREATE TABLE customers (
    customer_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_type ENUM('walk_in','online','corporate','repeat','referral') NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    company_name VARCHAR(160) NULL,
    referral_source VARCHAR(160) NULL,
    is_blacklisted TINYINT(1) NOT NULL DEFAULT 0,
    blacklist_reason VARCHAR(500) NULL,
    blacklisted_at DATETIME(6) NULL,
    blacklisted_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (customer_id),
    KEY idx_customers_eligibility (is_blacklisted,deleted_at,customer_type),
    KEY idx_customers_name (full_name),
    CONSTRAINT fk_customers_blacklisted_by FOREIGN KEY (blacklisted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_customers_blacklist CHECK ((is_blacklisted=0 AND blacklist_reason IS NULL AND blacklisted_at IS NULL AND blacklisted_by_user_id IS NULL) OR (is_blacklisted=1 AND blacklist_reason IS NOT NULL AND CHAR_LENGTH(TRIM(blacklist_reason))>0 AND blacklisted_at IS NOT NULL AND blacklisted_by_user_id IS NOT NULL)),
    CONSTRAINT chk_customers_corporate_name CHECK (customer_type<>'corporate' OR (company_name IS NOT NULL AND CHAR_LENGTH(TRIM(company_name))>0)),
    CONSTRAINT chk_customers_referral_source CHECK (customer_type<>'referral' OR (referral_source IS NOT NULL AND CHAR_LENGTH(TRIM(referral_source))>0))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE customer_contacts (
    contact_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    contact_type ENUM('phone','email') NOT NULL,
    contact_ciphertext VARBINARY(512) NOT NULL,
    contact_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (contact_id),
    KEY idx_customer_contacts_owner (customer_id,contact_type,deleted_at,is_primary),
    KEY idx_customer_contacts_fingerprint (contact_type,contact_fingerprint),
    CONSTRAINT fk_customer_contacts_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT chk_customer_contact_primary CHECK (is_primary IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE customer_identity_documents (
    document_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    document_ciphertext VARBINARY(512) NOT NULL,
    document_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_on DATE NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (document_id),
    UNIQUE KEY uq_customer_identity_fingerprint (document_fingerprint),
    KEY idx_customer_documents_owner (customer_id,document_type),
    CONSTRAINT fk_customer_documents_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT chk_customer_document_type CHECK (document_type IN ('ph_driver_license','passport','national_id','other_government_id'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE customer_notes (
    note_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    note_type ENUM('general','blacklist','unblacklist') NOT NULL DEFAULT 'general',
    note_text TEXT NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (note_id),
    KEY idx_customer_notes_history (customer_id,created_at,note_id),
    CONSTRAINT fk_customer_notes_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_notes_actor FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE customer_identity_document_audit_logs (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    document_id BIGINT UNSIGNED NOT NULL,
    document_type VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    old_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    new_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    operation ENUM('insert','update') NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (audit_id),
    KEY idx_customer_document_audit_customer (customer_id,created_at,audit_id),
    KEY idx_customer_document_audit_document (document_id,created_at),
    CONSTRAINT fk_customer_document_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_document_audit_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_customer_document_audit_document FOREIGN KEY (document_id) REFERENCES customer_identity_documents(document_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ==========================================
-- DRIVERS AND CHAUFFEUR MASTER DATA
-- ==========================================

CREATE TABLE drivers (
    driver_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(160) NOT NULL,
    license_number_ciphertext VARBINARY(512) NOT NULL,
    license_number_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    license_expiry DATE NOT NULL,
    address_ciphertext VARBINARY(4096) NULL,
    emergency_contact_name_ciphertext VARBINARY(1024) NULL,
    emergency_contact_phone_ciphertext VARBINARY(512) NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (driver_id),
    UNIQUE KEY uq_drivers_license_fingerprint (license_number_fingerprint),
    KEY idx_drivers_selectable (status,deleted_at,license_expiry,full_name),
    KEY idx_drivers_name (full_name),
    CONSTRAINT chk_drivers_name CHECK (CHAR_LENGTH(TRIM(full_name))>0),
    CONSTRAINT chk_drivers_emergency_pair CHECK ((emergency_contact_name_ciphertext IS NULL AND emergency_contact_phone_ciphertext IS NULL) OR (emergency_contact_name_ciphertext IS NOT NULL AND emergency_contact_phone_ciphertext IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE driver_contacts (
    contact_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    driver_id BIGINT UNSIGNED NOT NULL,
    contact_type ENUM('phone','email') NOT NULL,
    contact_ciphertext VARBINARY(2048) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    deleted_at DATETIME(6) NULL,
    PRIMARY KEY (contact_id),
    KEY idx_driver_contacts_owner (driver_id,contact_type,deleted_at,is_primary),
    CONSTRAINT fk_driver_contacts_driver FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE RESTRICT,
    CONSTRAINT chk_driver_contact_primary CHECK (is_primary IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ==========================================
-- RENTAL AGREEMENTS, CHARGES, AND HISTORIES
-- ==========================================

CREATE TABLE rental_agreements (
    agreement_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_reference CHAR(8) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    driver_id BIGINT UNSIGNED NULL,
    rental_type ENUM('self_drive','chauffeur') NOT NULL DEFAULT 'self_drive',
    booking_source ENUM('staff','online') NOT NULL DEFAULT 'staff',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    scheduled_pickup_at DATETIME(6) NULL,
    scheduled_return_at DATETIME(6) NULL,
    actual_pickup_at DATETIME(6) NULL,
    actual_return_at DATETIME(6) NULL,
    daily_rate DECIMAL(10,2) NOT NULL,
    rental_days INT GENERATED ALWAYS AS (GREATEST(DATEDIFF(end_date,start_date),1)) STORED,
    base_amount DECIMAL(18,2) GENERATED ALWAYS AS (GREATEST(DATEDIFF(end_date,start_date),1)*daily_rate) STORED,
    security_deposit_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    deposit_status ENUM('not_required','due','held','released','refunded','forfeited') NOT NULL DEFAULT 'not_required',
    downpayment_amount DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    downpayment_status ENUM('not_required','due','received') NOT NULL DEFAULT 'not_required',
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
    UNIQUE KEY uq_rentals_booking_reference (booking_reference),
    CONSTRAINT fk_rentals_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rentals_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rentals_driver FOREIGN KEY (driver_id) REFERENCES drivers(driver_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rentals_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_rentals_dates CHECK (end_date >= start_date),
    CONSTRAINT chk_rentals_rate CHECK (daily_rate >= 0),
    CONSTRAINT chk_rentals_deposit CHECK (security_deposit_amount >= 0),
    CONSTRAINT chk_rentals_scheduled_times CHECK (scheduled_pickup_at IS NULL OR scheduled_return_at IS NULL OR scheduled_return_at >= scheduled_pickup_at),
    CONSTRAINT chk_rentals_actual_times CHECK (actual_pickup_at IS NULL OR actual_return_at IS NULL OR actual_return_at >= actual_pickup_at),
    CONSTRAINT chk_rentals_driver_type CHECK (rental_type <> 'self_drive' OR driver_id IS NULL),
    CONSTRAINT chk_rentals_chauffeur_driver CHECK (rental_type <> 'chauffeur' OR status IN ('reserved','cancelled','no_show') OR driver_id IS NOT NULL),
    CONSTRAINT chk_rentals_downpayment CHECK (
        (downpayment_status = 'not_required' AND downpayment_amount = 0)
        OR (downpayment_status IN ('due','received') AND downpayment_amount > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE rental_charges (
    charge_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agreement_id BIGINT UNSIGNED NOT NULL,
    charge_type ENUM('fee','discount','tax','damage','chauffeur_fee','other') NOT NULL,
    entry_kind ENUM('charge','reversal') NOT NULL DEFAULT 'charge',
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(500) NOT NULL,
    reverses_charge_id BIGINT UNSIGNED NULL,
    damage_decision_id BIGINT UNSIGNED NULL,
    damage_adjustment_reason VARCHAR(500) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (charge_id),
    UNIQUE KEY uq_rental_charge_reversal (reverses_charge_id),
    UNIQUE KEY uq_rental_charge_damage_decision (damage_decision_id),
    KEY idx_rental_charges_agreement (agreement_id,created_at,charge_id),
    CONSTRAINT fk_rental_charges_agreement FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rental_charges_reversal FOREIGN KEY (reverses_charge_id) REFERENCES rental_charges(charge_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rental_charges_actor FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_rental_charge_amount CHECK (amount > 0),
    CONSTRAINT chk_rental_charge_description CHECK (CHAR_LENGTH(TRIM(description)) > 0),
    CONSTRAINT chk_rental_charge_reversal CHECK ((entry_kind='charge' AND reverses_charge_id IS NULL) OR (entry_kind='reversal' AND reverses_charge_id IS NOT NULL)),
    CONSTRAINT chk_rental_charge_damage_decision CHECK (damage_decision_id IS NULL OR (charge_type = 'damage' AND entry_kind = 'charge')),
    CONSTRAINT chk_rental_charge_damage_reason CHECK (damage_adjustment_reason IS NULL OR damage_decision_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ==========================================
-- SMS, TELEGRAM, NOTIFICATIONS, AND MAGIC LINKS
-- ==========================================

-- One-time Telegram connection codes. Only the SHA-256 hash of a code is stored.
CREATE TABLE telegram_link_codes (
    code_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    used_at DATETIME(6) NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (code_id),
    UNIQUE KEY uq_telegram_link_codes_hash (code_hash),
    KEY idx_telegram_link_codes_customer (customer_id,used_at,expires_at),
    CONSTRAINT fk_telegram_link_codes_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_telegram_link_codes_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per Telegram connection; ending one marks it revoked and a new connection is a new
-- row. The generated columns make "one active connection per customer" and "one active
-- customer per chat" database rules (NULL when revoked, so history rows never collide).
CREATE TABLE customer_telegram_links (
    link_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    chat_id_ciphertext VARBINARY(255) NOT NULL,
    chat_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    link_status ENUM('active','revoked') NOT NULL DEFAULT 'active',
    code_id BIGINT UNSIGNED NULL,
    linked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    revoked_at DATETIME(6) NULL,
    revoked_reason ENUM('customer_stop','staff','bot_blocked','relinked') NULL,
    revoked_by_user_id BIGINT UNSIGNED NULL,
    active_customer_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN link_status = 'active' THEN customer_id END) STORED,
    active_chat_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin GENERATED ALWAYS AS (CASE WHEN link_status = 'active' THEN chat_fingerprint END) STORED,
    PRIMARY KEY (link_id),
    UNIQUE KEY uq_telegram_links_active_customer (active_customer_id),
    UNIQUE KEY uq_telegram_links_active_chat (active_chat_fingerprint),
    KEY idx_telegram_links_customer (customer_id,link_status,linked_at),
    KEY idx_telegram_links_chat (chat_fingerprint,link_status),
    CONSTRAINT fk_telegram_links_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_telegram_links_code FOREIGN KEY (code_id) REFERENCES telegram_link_codes(code_id) ON DELETE RESTRICT,
    CONSTRAINT fk_telegram_links_revoker FOREIGN KEY (revoked_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_telegram_links_revocation CHECK (
        (link_status = 'active' AND revoked_at IS NULL AND revoked_reason IS NULL AND revoked_by_user_id IS NULL)
        OR (link_status = 'revoked' AND revoked_at IS NOT NULL AND revoked_reason IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Inbound bot updates, keyed by Telegram's own update_id. Message text is deliberately not stored.
CREATE TABLE telegram_updates (
    update_id BIGINT UNSIGNED NOT NULL,
    chat_fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    event_type VARCHAR(40) NOT NULL,
    outcome VARCHAR(40) NOT NULL DEFAULT 'received',
    received_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (update_id),
    KEY idx_telegram_updates_chat (chat_fingerprint,received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_phone VARCHAR(20) NOT NULL,
    customer_id BIGINT UNSIGNED NULL,
    telegram_link_id BIGINT UNSIGNED NULL,
    idempotency_key VARCHAR(191) NULL,
    channel ENUM('sms','telegram') NOT NULL DEFAULT 'sms',
    template_key VARCHAR(80) NOT NULL,
    rendered_message TEXT NOT NULL,
    message_class ENUM('transactional','non_transactional') NOT NULL,
    provider VARCHAR(40) NOT NULL,
    status ENUM('queued','sending','sent','failed','suppressed','suppressed_by_policy') NOT NULL DEFAULT 'queued',
    priority ENUM('normal','high') NOT NULL DEFAULT 'normal',
    provider_message_id VARCHAR(191) NULL,
    provider_status VARCHAR(80) NULL,
    attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    retry_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claim_token CHAR(36) NULL,
    claimed_at DATETIME NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(512) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_notifications_idempotency (idempotency_key),
    UNIQUE KEY uq_notifications_provider_message_id (provider_message_id),
    KEY idx_notifications_queue (status,next_attempt_at,priority,created_at),
    KEY idx_notifications_recipient_created (recipient_phone,created_at),
    KEY idx_notifications_class_status (message_class,status),
    KEY idx_notifications_customer (customer_id,created_at),
    CONSTRAINT fk_notifications_customer FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE RESTRICT,
    CONSTRAINT fk_notifications_telegram_link FOREIGN KEY (telegram_link_id) REFERENCES customer_telegram_links(link_id) ON DELETE RESTRICT,
    CONSTRAINT chk_notifications_telegram_link CHECK (channel <> 'telegram' OR telegram_link_id IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE inbound_sms_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider_message_id VARCHAR(191) NOT NULL,
    provider VARCHAR(40) NOT NULL,
    raw_payload MEDIUMTEXT NOT NULL,
    received_at DATETIME(6) NOT NULL,
    sender_number VARCHAR(20) NOT NULL,
    event_type VARCHAR(40) NOT NULL,
    message_text TEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inbound_sms_provider_message_id (provider_message_id),
    KEY idx_inbound_sms_sender_type (sender_number,event_type,received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- The text of each policy a customer can be asked to accept, one row per version, never edited.
CREATE TABLE rules_versions (
    rules_version_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rules_key VARCHAR(40) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    title VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    published_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (rules_version_id),
    UNIQUE KEY uq_rules_versions_key_number (rules_key, version_number),
    CONSTRAINT chk_rules_versions_number CHECK (version_number > 0),
    CONSTRAINT chk_rules_versions_text CHECK (CHAR_LENGTH(TRIM(title)) > 0 AND CHAR_LENGTH(TRIM(body)) > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO rules_versions (rules_key, version_number, title, body) VALUES
('downpayment_policy', 1, 'Downpayment policy',
 'To reserve a vehicle you pay a downpayment of 30% of the rental cost by GCash. The downpayment is non-refundable, including when you cancel the booking or do not pick up the vehicle. Your reservation is confirmed only after the rental office has checked your payment. A reservation that is not paid within 24 hours is released. The remaining balance is paid in person when you pick up the vehicle.');
-- Version 2 no longer names GCash as the only way to pay. Bookings made under version 1 keep it.
INSERT INTO rules_versions (rules_key, version_number, title, body) VALUES
('downpayment_policy', 2, 'Downpayment policy',
 'To reserve a vehicle you pay a downpayment of 30% of the rental cost, by any payment method the rental office accepts. The downpayment is non-refundable, including when you cancel the booking or do not pick up the vehicle. Your reservation is confirmed only after the rental office has received your payment. A reservation that is not paid within 24 hours is released. The remaining balance is paid when you pick up the vehicle.');

-- Consent evidence, append-only. 'revoked' rows are SMS STOP replies; 'accepted' rows record
-- which policy version a customer accepted for which booking, and from where.
CREATE TABLE rules_acceptances (
    acceptance_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    phone VARCHAR(20) NOT NULL,
    action ENUM('revoked','accepted') NOT NULL DEFAULT 'revoked',
    rules_version_id BIGINT UNSIGNED NULL,
    agreement_id BIGINT UNSIGNED NULL,
    inbound_sms_event_id BIGINT UNSIGNED NULL,
    provider_message_id VARCHAR(191) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(512) NULL,
    recorded_at DATETIME(6) NOT NULL,
    PRIMARY KEY (acceptance_id),
    UNIQUE KEY uq_rules_provider_msg (phone,provider_message_id),
    UNIQUE KEY uq_rules_event (inbound_sms_event_id),
    UNIQUE KEY uq_rules_acceptance_agreement_version (agreement_id,rules_version_id),
    KEY idx_rules_phone_action (phone,action,recorded_at),
    CONSTRAINT fk_rules_inbound_event FOREIGN KEY (inbound_sms_event_id) REFERENCES inbound_sms_events(id) ON DELETE RESTRICT,
    CONSTRAINT fk_rules_acceptance_version FOREIGN KEY (rules_version_id) REFERENCES rules_versions(rules_version_id) ON DELETE RESTRICT,
    CONSTRAINT fk_rules_acceptance_agreement FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    CONSTRAINT chk_rules_acceptance_kind CHECK (
        (action = 'revoked' AND inbound_sms_event_id IS NOT NULL AND provider_message_id IS NOT NULL AND rules_version_id IS NULL AND agreement_id IS NULL)
        OR (action = 'accepted' AND rules_version_id IS NOT NULL AND agreement_id IS NOT NULL AND inbound_sms_event_id IS NULL AND provider_message_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per proof a customer submits. The generated column lets the database allow only
-- one proof per booking to be waiting for a decision at a time.
CREATE TABLE payment_proofs (
    proof_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agreement_id BIGINT UNSIGNED NOT NULL,
    reference_number VARCHAR(40) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    mime VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    proof_status ENUM('submitted','verified','rejected') NOT NULL DEFAULT 'submitted',
    submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    submitted_ip VARCHAR(45) NULL,
    reviewed_by BIGINT UNSIGNED NULL,
    reviewed_at DATETIME(6) NULL,
    review_note VARCHAR(500) NULL,
    pending_agreement_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN proof_status = 'submitted' THEN agreement_id END) STORED,
    PRIMARY KEY (proof_id),
    UNIQUE KEY uq_payment_proofs_storage_path (storage_path),
    UNIQUE KEY uq_payment_proofs_one_pending (pending_agreement_id),
    KEY idx_payment_proofs_agreement (agreement_id, submitted_at, proof_id),
    KEY idx_payment_proofs_queue (proof_status, submitted_at),
    CONSTRAINT fk_payment_proofs_agreement FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    CONSTRAINT fk_payment_proofs_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_payment_proofs_size CHECK (size_bytes > 0),
    CONSTRAINT chk_payment_proofs_review CHECK (
        (proof_status = 'submitted' AND reviewed_by IS NULL AND reviewed_at IS NULL AND review_note IS NULL)
        OR (proof_status = 'verified' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL)
        OR (proof_status = 'rejected' AND reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL AND review_note IS NOT NULL AND CHAR_LENGTH(TRIM(review_note)) > 0)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Every payment received and every online attempt, for any method.
--   channel 'staff'        finance recorded it: money at the counter, or a customer's GCash proof
--                          that finance verified (proof_id then names that proof)
--   channel 'online_demo'  paid on the simulated checkout; no real money moves, and the channel
--                          is stored so a simulated payment is never mistaken for a real one
--   purpose 'downpayment'  the 30% that must be in before a reservation is confirmed
--   purpose 'balance'      the rest, received at the counter
-- The generated columns let the database allow one payment in progress per booking, and one
-- paid downpayment per booking, ever.
CREATE TABLE payments (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agreement_id BIGINT UNSIGNED NOT NULL,
    purpose ENUM('downpayment','balance') NOT NULL,
    channel ENUM('staff','online_demo') NOT NULL,
    method ENUM('cash','gcash','maya','grabpay','card','online_banking') NOT NULL,
    method_detail VARCHAR(60) NULL,
    amount DECIMAL(18,2) NOT NULL,
    payment_status ENUM('pending','paid','failed','cancelled','expired') NOT NULL,
    receipt_number CHAR(12) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    external_reference VARCHAR(40) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    failure_reason VARCHAR(160) NULL,
    proof_id BIGINT UNSIGNED NULL,
    recorded_by BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    expires_at DATETIME(6) NULL,
    settled_at DATETIME(6) NULL,
    pending_agreement_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN payment_status = 'pending' THEN agreement_id END) STORED,
    paid_downpayment_agreement_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN payment_status = 'paid' AND purpose = 'downpayment' THEN agreement_id END) STORED,
    PRIMARY KEY (payment_id),
    UNIQUE KEY uq_payments_receipt_number (receipt_number),
    UNIQUE KEY uq_payments_external_reference (external_reference),
    UNIQUE KEY uq_payments_proof (proof_id),
    UNIQUE KEY uq_payments_one_pending (pending_agreement_id),
    UNIQUE KEY uq_payments_one_paid_downpayment (paid_downpayment_agreement_id),
    KEY idx_payments_agreement (agreement_id, created_at, payment_id),
    KEY idx_payments_status_settled (payment_status, settled_at),
    CONSTRAINT fk_payments_agreement FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_proof FOREIGN KEY (proof_id) REFERENCES payment_proofs(proof_id) ON DELETE RESTRICT,
    CONSTRAINT fk_payments_recorder FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_payments_amount CHECK (amount > 0),
    -- Staff record money they already hold; an online payment has no staff member and is never cash.
    CONSTRAINT chk_payments_channel CHECK (
        (channel = 'staff' AND payment_status = 'paid' AND recorded_by IS NOT NULL AND expires_at IS NULL)
        OR (channel = 'online_demo' AND method <> 'cash' AND recorded_by IS NULL AND proof_id IS NULL)
    ),
    CONSTRAINT chk_payments_state CHECK (
        (payment_status = 'pending' AND settled_at IS NULL AND failure_reason IS NULL AND external_reference IS NULL AND expires_at IS NOT NULL)
        OR (payment_status = 'paid' AND settled_at IS NOT NULL AND failure_reason IS NULL AND ((method = 'cash' AND external_reference IS NULL) OR (method <> 'cash' AND external_reference IS NOT NULL AND CHAR_LENGTH(TRIM(external_reference)) > 0)))
        OR (payment_status = 'failed' AND settled_at IS NOT NULL AND external_reference IS NULL AND failure_reason IS NOT NULL AND CHAR_LENGTH(TRIM(failure_reason)) > 0)
        OR (payment_status IN ('cancelled','expired') AND settled_at IS NOT NULL AND external_reference IS NULL AND failure_reason IS NULL)
    ),
    CONSTRAINT chk_payments_proof CHECK (proof_id IS NULL OR (channel = 'staff' AND method = 'gcash' AND purpose = 'downpayment'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE booking_access_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    purpose VARCHAR(64) NOT NULL,
    booking_id BIGINT UNSIGNED NULL,
    expires_at DATETIME(6) NOT NULL,
    used_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_access_token_hash (token_hash),
    KEY idx_booking_access_token_expiry (expires_at,used_at),
    KEY idx_booking_access_token_booking_purpose (booking_id,purpose,created_at),
    KEY idx_booking_tokens_booking (booking_id),
    CONSTRAINT fk_booking_tokens_rental FOREIGN KEY (booking_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Each attempt to open a secure link is recorded in security_logs, tied to its token.
-- (security_logs is created before booking_access_tokens, so the reference is added here.)
ALTER TABLE security_logs
    ADD CONSTRAINT fk_security_logs_token FOREIGN KEY (token_id) REFERENCES booking_access_tokens(id) ON DELETE RESTRICT;

-- ==========================================
-- DAMAGE REPORTING
-- ==========================================

CREATE TABLE damage_reports (
    report_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    agreement_id BIGINT UNSIGNED NOT NULL,
    phase ENUM('pre','during','post') NOT NULL,
    phase_slot VARCHAR(10) GENERATED ALWAYS AS (CASE WHEN phase='during' THEN NULL ELSE phase END) STORED,
    has_damage TINYINT(1) NOT NULL,
    location VARCHAR(120) NULL,
    damage_type VARCHAR(40) NULL,
    severity ENUM('minor','moderate','severe') NULL,
    repair_cost_suggestion DECIMAL(10,2) NULL,
    notes VARCHAR(1000) NULL,
    recorded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (report_id),
    UNIQUE KEY uq_damage_phase_slot (agreement_id,phase_slot),
    KEY ix_damage_agreement (agreement_id,created_at),
    CONSTRAINT fk_damage_agreement FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    CONSTRAINT fk_damage_actor FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_damage_fields CHECK ((has_damage=0 AND location IS NULL AND damage_type IS NULL AND severity IS NULL AND repair_cost_suggestion IS NULL) OR (has_damage=1 AND location IS NOT NULL AND CHAR_LENGTH(TRIM(location))>0 AND damage_type IS NOT NULL AND CHAR_LENGTH(TRIM(damage_type))>0 AND severity IS NOT NULL AND (repair_cost_suggestion IS NULL OR repair_cost_suggestion>=0)))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE damage_liability_decisions (
    decision_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    customer_liable TINYINT(1) NOT NULL,
    liable_amount DECIMAL(10,2) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    supersedes_decision_id BIGINT UNSIGNED NULL,
    current_root_report_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN supersedes_decision_id IS NULL THEN report_id ELSE NULL END) STORED,
    decided_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (decision_id),
    UNIQUE KEY uq_damage_liability_root (current_root_report_id),
    UNIQUE KEY uq_damage_decision_same_report (report_id,decision_id),
    UNIQUE KEY uq_damage_supersedes_once (supersedes_decision_id),
    KEY ix_damage_decision_report (report_id,decision_id),
    CONSTRAINT fk_damage_decision_report FOREIGN KEY (report_id) REFERENCES damage_reports(report_id) ON DELETE RESTRICT,
    CONSTRAINT fk_damage_decision_parent FOREIGN KEY (report_id,supersedes_decision_id) REFERENCES damage_liability_decisions(report_id,decision_id) ON DELETE RESTRICT,
    CONSTRAINT fk_damage_decision_actor FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT chk_damage_decision_amount CHECK (liable_amount>=0 AND (customer_liable=1 OR liable_amount=0)),
    CONSTRAINT chk_damage_decision_reason CHECK (CHAR_LENGTH(TRIM(reason))>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- A damage charge posted from a liability decision is a rental_charges row that carries the
-- decision. (rental_charges is created before the damage tables, so the reference is added here.)
ALTER TABLE rental_charges
    ADD CONSTRAINT fk_rental_charges_damage_decision FOREIGN KEY (damage_decision_id) REFERENCES damage_liability_decisions(decision_id) ON DELETE RESTRICT;

-- ==========================================
-- APPEND-ONLY AND IDENTITY GUARD TRIGGERS
-- ==========================================

DELIMITER $$
CREATE TRIGGER inbound_sms_events_no_update BEFORE UPDATE ON inbound_sms_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='inbound_sms_events is append-only'; END$$
CREATE TRIGGER inbound_sms_events_no_delete BEFORE DELETE ON inbound_sms_events FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='inbound_sms_events is append-only'; END$$
CREATE TRIGGER security_logs_no_update BEFORE UPDATE ON security_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='security_logs is append-only'; END$$
CREATE TRIGGER security_logs_no_delete BEFORE DELETE ON security_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='security_logs is append-only'; END$$
CREATE TRIGGER vehicle_mileage_logs_no_update BEFORE UPDATE ON vehicle_mileage_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='vehicle_mileage_logs is append-only'; END$$
CREATE TRIGGER vehicle_mileage_logs_no_delete BEFORE DELETE ON vehicle_mileage_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='vehicle_mileage_logs is append-only'; END$$
CREATE TRIGGER customer_notes_no_update BEFORE UPDATE ON customer_notes FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='customer_notes is append-only'; END$$
CREATE TRIGGER customer_notes_no_delete BEFORE DELETE ON customer_notes FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='customer_notes is append-only'; END$$
CREATE TRIGGER customer_identity_documents_audit_insert AFTER INSERT ON customer_identity_documents FOR EACH ROW
BEGIN
    IF @triple_r_actor_user_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='customer identity writes require an authenticated actor'; END IF;
    INSERT INTO customer_identity_document_audit_logs(actor_user_id,customer_id,document_id,document_type,old_fingerprint,new_fingerprint,operation)
    VALUES(@triple_r_actor_user_id,NEW.customer_id,NEW.document_id,NEW.document_type,NULL,NEW.document_fingerprint,'insert');
END$$
CREATE TRIGGER customer_identity_documents_audit_update AFTER UPDATE ON customer_identity_documents FOR EACH ROW
BEGIN
    IF @triple_r_actor_user_id IS NULL THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='customer identity writes require an authenticated actor'; END IF;
    INSERT INTO customer_identity_document_audit_logs(actor_user_id,customer_id,document_id,document_type,old_fingerprint,new_fingerprint,operation)
    VALUES(@triple_r_actor_user_id,NEW.customer_id,NEW.document_id,NEW.document_type,OLD.document_fingerprint,NEW.document_fingerprint,'update');
END$$
CREATE TRIGGER customer_identity_document_audit_logs_no_update BEFORE UPDATE ON customer_identity_document_audit_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='customer_identity_document_audit_logs is append-only'; END$$
CREATE TRIGGER customer_identity_document_audit_logs_no_delete BEFORE DELETE ON customer_identity_document_audit_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='customer_identity_document_audit_logs is append-only'; END$$
CREATE TRIGGER rental_charges_no_update BEFORE UPDATE ON rental_charges FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rental_charges is append-only; record a reversal'; END$$
CREATE TRIGGER rental_charges_no_delete BEFORE DELETE ON rental_charges FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rental_charges is append-only'; END$$
CREATE TRIGGER rules_acceptances_no_update BEFORE UPDATE ON rules_acceptances FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rules_acceptances is append-only'; END$$
CREATE TRIGGER rules_acceptances_no_delete BEFORE DELETE ON rules_acceptances FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rules_acceptances is append-only'; END$$
CREATE TRIGGER rental_agreements_identity_immutable BEFORE UPDATE ON rental_agreements FOR EACH ROW
BEGIN
    IF NOT (NEW.vehicle_id <=> OLD.vehicle_id) OR NOT (NEW.customer_id <=> OLD.customer_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rental agreement vehicle/customer identity is immutable'; END IF;
END$$
CREATE TRIGGER rental_agreements_driver_immutable BEFORE UPDATE ON rental_agreements FOR EACH ROW
BEGIN
    IF NOT (NEW.driver_id <=> OLD.driver_id) AND OLD.status NOT IN ('reserved','confirmed') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rental agreement driver assignment is immutable after confirmation/pickup'; END IF;
END$$
-- The amount is fixed at booking. A downpayment is marked received only when its payment is in
-- the payments table, and is never taken back.
CREATE TRIGGER rental_agreements_downpayment_guard BEFORE UPDATE ON rental_agreements FOR EACH ROW
BEGIN
    IF NEW.downpayment_amount <> OLD.downpayment_amount THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The downpayment amount is fixed when the booking is made';
    END IF;
    IF OLD.downpayment_status = 'received' AND NEW.downpayment_status <> 'received' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A recorded downpayment cannot be changed';
    END IF;
    IF NEW.downpayment_status = 'received' AND OLD.downpayment_status <> 'received' AND NOT EXISTS (SELECT 1 FROM payments WHERE paid_downpayment_agreement_id = NEW.agreement_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A downpayment is received only when its payment is recorded';
    END IF;
END$$
CREATE TRIGGER rules_versions_no_update BEFORE UPDATE ON rules_versions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rules_versions is append-only; publish a new version'; END$$
CREATE TRIGGER rules_versions_no_delete BEFORE DELETE ON rules_versions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rules_versions is append-only'; END$$
-- What the customer submitted never changes, and a decision is made once.
CREATE TRIGGER payment_proofs_guard BEFORE UPDATE ON payment_proofs FOR EACH ROW
BEGIN
    IF NEW.agreement_id <> OLD.agreement_id OR NEW.reference_number <> OLD.reference_number OR NEW.storage_path <> OLD.storage_path OR NEW.submitted_at <> OLD.submitted_at THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A submitted payment proof cannot be edited';
    END IF;
    IF OLD.proof_status <> 'submitted' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A payment proof that has been decided cannot be changed';
    END IF;
END$$
CREATE TRIGGER payment_proofs_no_delete BEFORE DELETE ON payment_proofs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='payment_proofs is append-only'; END$$
-- A downpayment is paid in full, at the amount stored on the booking: nothing typed in a
-- browser can change what is charged.
CREATE TRIGGER payments_amount_guard BEFORE INSERT ON payments FOR EACH ROW
BEGIN
    IF NEW.purpose = 'downpayment' AND NEW.amount <> (SELECT downpayment_amount FROM rental_agreements WHERE agreement_id = NEW.agreement_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A downpayment is paid at the amount fixed when the booking was made';
    END IF;
END$$
-- What was asked for never changes, and a payment is settled once.
CREATE TRIGGER payments_guard BEFORE UPDATE ON payments FOR EACH ROW
BEGIN
    IF NEW.agreement_id <> OLD.agreement_id OR NEW.purpose <> OLD.purpose OR NEW.channel <> OLD.channel OR NEW.method <> OLD.method OR NEW.amount <> OLD.amount OR NEW.receipt_number <> OLD.receipt_number OR NEW.created_at <> OLD.created_at OR NOT (NEW.proof_id <=> OLD.proof_id) OR NOT (NEW.recorded_by <=> OLD.recorded_by) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='The details of a payment cannot be edited';
    END IF;
    IF OLD.payment_status <> 'pending' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A payment that has been settled cannot be changed';
    END IF;
END$$
CREATE TRIGGER payments_no_delete BEFORE DELETE ON payments FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='payments is append-only'; END$$
-- A booking keeps its reference and its source for life.
CREATE TRIGGER rental_agreements_booking_identity BEFORE UPDATE ON rental_agreements FOR EACH ROW
BEGIN
    IF NEW.booking_reference <> OLD.booking_reference OR NEW.booking_source <> OLD.booking_source THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A booking reference and source cannot be changed';
    END IF;
END$$
CREATE TRIGGER damage_reports_no_update BEFORE UPDATE ON damage_reports FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Damage reports are append-only'; END$$
CREATE TRIGGER damage_reports_no_delete BEFORE DELETE ON damage_reports FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Damage reports are append-only'; END$$
CREATE TRIGGER damage_liability_no_update BEFORE UPDATE ON damage_liability_decisions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Liability decisions are append-only'; END$$
CREATE TRIGGER damage_liability_no_delete BEFORE DELETE ON damage_liability_decisions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Liability decisions are append-only'; END$$
CREATE TRIGGER customer_telegram_links_guard BEFORE UPDATE ON customer_telegram_links FOR EACH ROW
BEGIN
    IF NEW.customer_id <> OLD.customer_id OR NEW.chat_fingerprint <> OLD.chat_fingerprint OR NEW.chat_id_ciphertext <> OLD.chat_id_ciphertext OR NEW.linked_at <> OLD.linked_at OR NOT (NEW.code_id <=> OLD.code_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Telegram connections are append-only; only revocation may be recorded';
    END IF;
    IF OLD.link_status = 'revoked' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A revoked Telegram connection cannot be changed';
    END IF;
END$$
CREATE TRIGGER customer_telegram_links_no_delete BEFORE DELETE ON customer_telegram_links FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Telegram connections are append-only'; END$$
DELIMITER ;

-- ==========================================
-- ==========================================
-- MAINTENANCE (MIGRATION 011)
-- ==========================================

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

-- Every status change of a vehicle, a driver, a rental, a rental's deposit or a maintenance
-- service. subject says which, each kind of record has its own column with a foreign key, and
-- the CHECK rules hold each kind to its own statuses and mandatory facts. It is defined here
-- because it needs every one of those tables to exist.
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

-- Every stored photo: of a vehicle, of a damage report, or of a maintenance service. Each kind
-- of owner has its own column with a foreign key, and exactly one of them is set. It is
-- defined here because it needs all three owner tables to exist.
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
CREATE TRIGGER status_logs_no_update BEFORE UPDATE ON status_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='status_logs is append-only'; END$$
CREATE TRIGGER status_logs_no_delete BEFORE DELETE ON status_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='status_logs is append-only'; END$$
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
CREATE TRIGGER maintenance_schedule_logs_no_update BEFORE UPDATE ON maintenance_schedule_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Maintenance schedule logs are append-only'; END$$
CREATE TRIGGER maintenance_schedule_logs_no_delete BEFORE DELETE ON maintenance_schedule_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Maintenance schedule logs are append-only'; END$$
CREATE TRIGGER maintenance_cost_audit_no_update BEFORE UPDATE ON maintenance_cost_audit_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Maintenance cost audit logs are append-only'; END$$
CREATE TRIGGER maintenance_cost_audit_no_delete BEFORE DELETE ON maintenance_cost_audit_logs FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Maintenance cost audit logs are append-only'; END$$
DELIMITER ;


-- MIGRATION CHECKSUM BASELINE
-- ==========================================
-- Required so existing bin/migrate.php installations verify and skip these migrations.

CREATE TABLE schema_migrations (
    migration VARCHAR(191) NOT NULL,
    checksum CHAR(64) CHARACTER SET ascii NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO schema_migrations (migration,checksum) VALUES
('001_notifications.sql','b253d7b9205a6cb915b6dbbe7a60b9f755a33cdf304b4717d325e7384a50756c'),
('002_magic_links.sql','072f2dd0e631ba3d0aaa32d328b42539daef4d6a27d7649620933bc959e0b781'),
('003_auth_sessions.sql','8f0253cb478462733a136d41b8d82554e168fa8e829284e64a6de70d324a53bb'),
('004_vehicles.sql','4c6c85634b8c29a3103e58044c77caa80f610afb203db2b1d9e9c00cef8c2488'),
('005_customers.sql','ac4cccb5a3d87dff6f6f7b8b7cff9299ee7126057ddc15d1d807e77d4cce4fbe'),
('006_drivers.sql','382dc203e3b1eeb158023c85eeb0d9fb27fa7d0538db62250b081e0fe6477efe'),
('007_rentals.sql','f695fb0ba499f04f20233d8c60b917930fd1489b677354331c7389a058c290c0'),
('008_rental_agreement_identity_immutable.sql','ab8e5f069f559d8d4f1ce4dcf3866997077b51bfd44d72a7ae2904d3b5cb0f0e'),
('009_chauffeur_guards.sql','e22e6f29136ea179cbf8ee1f85838ea3432d074a67d7d50b600280cad7f56da2'),
('010_damage.sql','1085ead11231251cf1ff7375688a862e8f66abfdb2a1ebf38b28f1a5860e466d'),
('011_maintenance.sql','322ee929af214400e52857c9b369bb91f544f898ad5bd2b0cad7661269a097fb'),
('012_telegram.sql','32af0e695691ab35ca761b08d6ec4c207bd630595c3a673f3931544e0b5dc2f4'),
('013_token_usage_into_security_logs.sql','6b3a63835026920cd81a07764133e4b7275f5ebd6453c3e6cd8e08ac42f9f7eb'),
('014_damage_posting_into_rental_charges.sql','a3ea4be229643fce6b0133f3b8d57734db9c0e242dfa7daccd8359c03307839a'),
('015_rate_counters.sql','13abdbf1aaf04edff8c1a2facd710b6085a4debfd64c7fdc1305c1594bdd2944'),
('016_photos.sql','4d6dd613cc9639fa2abad96e5a173f263b5e2b54cc2ca90a6cbc7229055a6c48'),
('017_status_logs.sql','babe36b0e5155084f28bfac46179cc6aaedab4f7f5ef4f395a0b1dadc4d2ee22'),
('018_downpayment.sql','f6588e45156e677d35409a1b51b5ba3404520b90d6dc8a0fb4c1cf6e20d27fba'),
('019_online_booking.sql','b0f596c6bb57ead3d1c1896085404b651cfeda862b2111166a3cc2aff49a9ff7'),
('020_payments.sql','98c3b62a03c0554643faf1d5fd4e716a774b74b9136fafe0f93d3b9225724ba3');
