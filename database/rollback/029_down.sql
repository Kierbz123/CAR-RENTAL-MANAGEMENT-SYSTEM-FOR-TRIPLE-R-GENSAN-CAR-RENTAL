-- Undoes migration 029 (three staff roles and driver accounts). Run by hand, as the migration
-- account, only together with the application code from before that migration.
--
-- It cannot tell which fleet managers used to be driver coordinators, or which front desk
-- accounts used to be finance: that is why the list of accounts and roles is saved before 029
-- is applied (roles-before-029.sql, next to the database backup). Run that file after this one.

-- 1. Driver accounts cannot exist in the old layout, and an account that has signed in cannot
--    be deleted, so they are signed out, deactivated and parked on a staff role.
UPDATE sessions s JOIN users u ON u.id = s.user_id
    SET s.invalidated_at = UTC_TIMESTAMP(6)
    WHERE u.role = 'driver' AND s.invalidated_at IS NULL;
ALTER TABLE users DROP CHECK chk_users_driver_link;
UPDATE users
    SET role = 'fleet_manager', driver_id = NULL, is_active = 0, deleted_at = COALESCE(deleted_at, UTC_TIMESTAMP(6))
    WHERE role = 'driver';

-- 2. The column, its keys and the five roles as they were.
ALTER TABLE users
    DROP FOREIGN KEY fk_users_driver,
    DROP INDEX uq_users_driver,
    DROP COLUMN driver_id,
    MODIFY COLUMN role ENUM('system_admin','fleet_manager','front_desk','driver_coordinator','finance_staff') NOT NULL DEFAULT 'fleet_manager';

-- 3. Let the migration be applied again later.
DELETE FROM schema_migrations WHERE migration = '029_roles_and_driver_accounts.sql';
