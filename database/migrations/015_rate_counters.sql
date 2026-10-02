-- Schema consolidation, step 3: one table for every counter that limits how often something
-- may happen. rate_limits (sign-in and secure-link throttles), sms_daily_budgets (messages per
-- phone per day) and magic_link_booking_limits (secure links per booking) were each a key and
-- a count. They become rows of rate_counters, told apart by scope. None of the three had a
-- foreign key, so no reference is lost. See docs/SCHEMA_CONSOLIDATION_PLAN.md.

CREATE TABLE rate_counters (
    scope VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    counter_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_started_at DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (scope, counter_key),
    CONSTRAINT chk_rate_counters_scope CHECK (scope IN ('throttle','message_daily','magic_link_booking'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- throttle: counter_key is the limiter's hashed key and the window restarts in place.
INSERT INTO rate_counters (scope, counter_key, window_started_at, hits)
    SELECT 'throttle', limiter_key, window_started_at, attempts FROM rate_limits;

-- message_daily: counter_key is "<phone>|<date>", one row per phone per day.
INSERT INTO rate_counters (scope, counter_key, window_started_at, hits)
    SELECT 'message_daily', CONCAT(recipient_phone, '|', DATE_FORMAT(budget_date, '%Y-%m-%d')), CAST(budget_date AS DATETIME), message_count FROM sms_daily_budgets;

-- magic_link_booking: counter_key is the agreement id and the count lasts for the booking's life.
INSERT INTO rate_counters (scope, counter_key, window_started_at, hits)
    SELECT 'magic_link_booking', CAST(booking_id AS CHAR), updated_at, issue_count FROM magic_link_booking_limits;

-- Stop here, before anything is dropped, unless every counter was copied with its count.
CREATE TABLE migration_015_check (
    copied_all TINYINT NOT NULL,
    CONSTRAINT chk_migration_015_copied_all CHECK (copied_all = 1)
) ENGINE=InnoDB;
INSERT INTO migration_015_check (copied_all)
    SELECT (SELECT COUNT(*) FROM rate_limits) = (SELECT COUNT(*) FROM rate_counters WHERE scope = 'throttle')
       AND (SELECT COUNT(*) FROM sms_daily_budgets) = (SELECT COUNT(*) FROM rate_counters WHERE scope = 'message_daily')
       AND (SELECT COUNT(*) FROM magic_link_booking_limits) = (SELECT COUNT(*) FROM rate_counters WHERE scope = 'magic_link_booking')
       AND (SELECT COALESCE(SUM(attempts), 0) FROM rate_limits) + (SELECT COALESCE(SUM(message_count), 0) FROM sms_daily_budgets) + (SELECT COALESCE(SUM(issue_count), 0) FROM magic_link_booking_limits)
           = (SELECT COALESCE(SUM(hits), 0) FROM rate_counters);
DROP TABLE migration_015_check;

DROP TABLE rate_limits;
DROP TABLE sms_daily_budgets;
DROP TABLE magic_link_booking_limits;
