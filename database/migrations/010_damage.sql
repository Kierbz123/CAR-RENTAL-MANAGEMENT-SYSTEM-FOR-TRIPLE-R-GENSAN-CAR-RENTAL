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
  UNIQUE KEY uq_damage_phase_slot (agreement_id, phase_slot),
  KEY ix_damage_agreement (agreement_id, created_at),
  CONSTRAINT fk_damage_agreement FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
  CONSTRAINT fk_damage_actor FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_damage_fields CHECK (
    (has_damage=0 AND location IS NULL AND damage_type IS NULL AND severity IS NULL AND repair_cost_suggestion IS NULL)
    OR (has_damage=1 AND location IS NOT NULL AND CHAR_LENGTH(TRIM(location))>0 AND damage_type IS NOT NULL AND CHAR_LENGTH(TRIM(damage_type))>0 AND severity IS NOT NULL AND (repair_cost_suggestion IS NULL OR repair_cost_suggestion>=0))
  )
) ENGINE=InnoDB;

CREATE TABLE damage_photos (
  photo_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id BIGINT UNSIGNED NOT NULL,
  storage_path VARCHAR(500) NOT NULL,
  original_filename VARCHAR(255) NOT NULL,
  mime VARCHAR(40) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  uploaded_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (photo_id),
  KEY ix_damage_photo_report (report_id),
  CONSTRAINT fk_damage_photo_report FOREIGN KEY (report_id) REFERENCES damage_reports(report_id) ON DELETE RESTRICT,
  CONSTRAINT fk_damage_photo_actor FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

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
  UNIQUE KEY uq_damage_decision_same_report (report_id, decision_id),
  UNIQUE KEY uq_damage_supersedes_once (supersedes_decision_id),
  KEY ix_damage_decision_report (report_id, decision_id),
  CONSTRAINT fk_damage_decision_report FOREIGN KEY (report_id) REFERENCES damage_reports(report_id) ON DELETE RESTRICT,
  CONSTRAINT fk_damage_decision_parent FOREIGN KEY (report_id, supersedes_decision_id) REFERENCES damage_liability_decisions(report_id, decision_id) ON DELETE RESTRICT,
  CONSTRAINT fk_damage_decision_actor FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_damage_decision_amount CHECK (liable_amount>=0 AND (customer_liable=1 OR liable_amount=0)),
  CONSTRAINT chk_damage_decision_reason CHECK (CHAR_LENGTH(TRIM(reason))>0)
) ENGINE=InnoDB;

CREATE TABLE damage_charge_postings (
  posting_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  decision_id BIGINT UNSIGNED NOT NULL,
  charge_id BIGINT UNSIGNED NOT NULL,
  approved_amount DECIMAL(10,2) NOT NULL,
  adjustment_reason VARCHAR(500) NULL,
  posted_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (posting_id),
  UNIQUE KEY uq_damage_charge_decision (decision_id),
  UNIQUE KEY uq_damage_charge_id (charge_id),
  CONSTRAINT fk_damage_post_decision FOREIGN KEY (decision_id) REFERENCES damage_liability_decisions(decision_id) ON DELETE RESTRICT,
  CONSTRAINT fk_damage_post_charge FOREIGN KEY (charge_id) REFERENCES rental_charges(charge_id) ON DELETE RESTRICT,
  CONSTRAINT fk_damage_post_actor FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_damage_post_amount CHECK (approved_amount>0)
) ENGINE=InnoDB;

DELIMITER $$
CREATE TRIGGER damage_reports_no_update BEFORE UPDATE ON damage_reports FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Damage reports are append-only'; END$$
CREATE TRIGGER damage_reports_no_delete BEFORE DELETE ON damage_reports FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Damage reports are append-only'; END$$
CREATE TRIGGER damage_photos_no_update BEFORE UPDATE ON damage_photos FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Damage photos are append-only'; END$$
CREATE TRIGGER damage_photos_no_delete BEFORE DELETE ON damage_photos FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Damage photos are append-only'; END$$
CREATE TRIGGER damage_liability_no_update BEFORE UPDATE ON damage_liability_decisions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Liability decisions are append-only'; END$$
CREATE TRIGGER damage_liability_no_delete BEFORE DELETE ON damage_liability_decisions FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Liability decisions are append-only'; END$$
CREATE TRIGGER damage_postings_no_update BEFORE UPDATE ON damage_charge_postings FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Damage charge postings are append-only'; END$$
CREATE TRIGGER damage_postings_no_delete BEFORE DELETE ON damage_charge_postings FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Damage charge postings are append-only'; END$$
DELIMITER ;
