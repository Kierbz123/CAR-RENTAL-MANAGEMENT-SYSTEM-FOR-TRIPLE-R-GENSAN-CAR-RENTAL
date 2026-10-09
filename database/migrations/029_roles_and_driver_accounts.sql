-- Three staff roles and driver accounts.
--
-- The driver coordinator's work (assigning drivers) moves to the fleet manager, and the finance
-- role's work (payments, charges, deposits) moves to the front desk. A new role, driver, is for
-- an account that signs in as one driver record and sees only that driver's own trips.
--
-- Order matters. Accounts are moved off the two old roles first, to roles the column already
-- allows; only then is the list of roles shortened. Shortening it while a row still held an
-- old role would blank that row's role, so the session is put in strict mode, where MySQL
-- refuses the change instead.

-- 1. Sign out everyone whose role is about to change.
UPDATE sessions s JOIN users u ON u.id = s.user_id
    SET s.invalidated_at = UTC_TIMESTAMP(6)
    WHERE u.role IN ('driver_coordinator','finance_staff') AND s.invalidated_at IS NULL;

-- 2. Move them. Whether an account is active or deactivated is left as it is.
UPDATE users SET role = 'fleet_manager' WHERE role = 'driver_coordinator';
UPDATE users SET role = 'front_desk' WHERE role = 'finance_staff';

-- 3. The four roles, and the driver record a driver's account signs in as.
--    One account per driver (unique), a driver with an account cannot be hard-deleted
--    (RESTRICT; the application only ever soft-removes drivers), and a driver's account always
--    names its driver while no other account names one (the CHECK).
SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',STRICT_ALL_TABLES');
ALTER TABLE users
    MODIFY COLUMN role ENUM('system_admin','fleet_manager','front_desk','driver') NOT NULL DEFAULT 'fleet_manager',
    ADD COLUMN driver_id BIGINT UNSIGNED NULL AFTER role,
    ADD UNIQUE KEY uq_users_driver (driver_id),
    ADD CONSTRAINT fk_users_driver FOREIGN KEY (driver_id) REFERENCES drivers (driver_id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    ADD CONSTRAINT chk_users_driver_link CHECK ((role = 'driver') = (driver_id IS NOT NULL));
