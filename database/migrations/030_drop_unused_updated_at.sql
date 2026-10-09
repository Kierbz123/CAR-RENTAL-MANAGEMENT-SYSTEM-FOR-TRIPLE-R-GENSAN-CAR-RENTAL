-- Removes the ten updated_at columns.
--
-- The database filled them in by itself on every change, and nothing ever read one: no page,
-- report, worker or test names the column (docs/SCHEMA_CONSOLIDATION_PLAN.md, section 7).
-- created_at stays on every table. Changes that matter are recorded in the history tables.

ALTER TABLE users DROP COLUMN updated_at;
ALTER TABLE vehicles DROP COLUMN updated_at;
ALTER TABLE vehicle_locations DROP COLUMN updated_at;
ALTER TABLE customers DROP COLUMN updated_at;
ALTER TABLE customer_contacts DROP COLUMN updated_at;
ALTER TABLE customer_identity_documents DROP COLUMN updated_at;
ALTER TABLE drivers DROP COLUMN updated_at;
ALTER TABLE driver_contacts DROP COLUMN updated_at;
ALTER TABLE rental_agreements DROP COLUMN updated_at;
ALTER TABLE notifications DROP COLUMN updated_at;
