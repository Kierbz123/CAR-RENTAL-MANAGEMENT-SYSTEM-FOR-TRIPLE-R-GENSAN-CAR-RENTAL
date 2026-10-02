-- Schema consolidation, step 2: the damage posting link moves onto the charge row.
-- damage_charge_postings held one row per damage charge, repeating its amount, who posted it
-- and when, plus the two facts that were new: which liability decision the charge came from
-- and why finance adjusted it. Those two facts become columns of rental_charges, with the
-- same guarantees: a real foreign key to the decision and at most one charge per decision.
-- See docs/SCHEMA_CONSOLIDATION_PLAN.md.

ALTER TABLE rental_charges
    ADD COLUMN damage_decision_id BIGINT UNSIGNED NULL AFTER reverses_charge_id,
    ADD COLUMN damage_adjustment_reason VARCHAR(500) NULL AFTER damage_decision_id,
    ADD UNIQUE KEY uq_rental_charge_damage_decision (damage_decision_id),
    ADD CONSTRAINT fk_rental_charges_damage_decision FOREIGN KEY (damage_decision_id) REFERENCES damage_liability_decisions(decision_id) ON DELETE RESTRICT,
    ADD CONSTRAINT chk_rental_charge_damage_decision CHECK (damage_decision_id IS NULL OR (charge_type = 'damage' AND entry_kind = 'charge')),
    ADD CONSTRAINT chk_rental_charge_damage_reason CHECK (damage_adjustment_reason IS NULL OR damage_decision_id IS NOT NULL);

-- rental_charges is append-only. The guard is lifted for this one copy and put back below.
DROP TRIGGER rental_charges_no_update;

UPDATE rental_charges c
    JOIN damage_charge_postings p ON p.charge_id = c.charge_id
    SET c.damage_decision_id = p.decision_id, c.damage_adjustment_reason = p.adjustment_reason;

DELIMITER $$
CREATE TRIGGER rental_charges_no_update BEFORE UPDATE ON rental_charges FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rental_charges is append-only; record a reversal'; END$$
DELIMITER ;

-- Stop here, before anything is dropped, unless every posting was copied and nothing it
-- said differs from its charge (amount and who posted it).
CREATE TABLE migration_014_check (
    copied_all TINYINT NOT NULL,
    CONSTRAINT chk_migration_014_copied_all CHECK (copied_all = 1)
) ENGINE=InnoDB;
INSERT INTO migration_014_check (copied_all)
    SELECT (SELECT COUNT(*) FROM damage_charge_postings) = (SELECT COUNT(*) FROM rental_charges WHERE damage_decision_id IS NOT NULL)
       AND (SELECT COUNT(*) FROM damage_charge_postings p JOIN rental_charges c ON c.charge_id = p.charge_id
            WHERE p.approved_amount <> c.amount OR p.posted_by <> c.created_by_user_id
               OR NOT (p.decision_id <=> c.damage_decision_id) OR NOT (p.adjustment_reason <=> c.damage_adjustment_reason)) = 0;
DROP TABLE migration_014_check;

DROP TABLE damage_charge_postings;
