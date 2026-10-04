-- Live vehicle tracking: where each rented vehicle is right now.
--
-- A phone that travels with the vehicle (the driver's, or the renter's) opens the tracker page
-- from a link staff create for that rental, and reports its GPS position every few seconds.
-- The link is a row in booking_access_tokens with purpose 'vehicle_tracker', so no new table
-- is needed for it.
--
-- vehicle_positions holds ONE row per vehicle: its latest position, replaced by each newer one.
-- It is not a history of where a customer has been. A trail would grow without limit and is far
-- more sensitive than a current position, and nothing in the system needs it.
--
--   agreement_id   the rental the position was reported for; the map shows a position only
--                  while that rental is still active
--   accuracy_m     how sure the phone was, in metres (GPS outdoors is a few metres; indoors far more)
--   stopped_since  set while the vehicle has not moved; NULL while it is moving

CREATE TABLE vehicle_positions (
    vehicle_id BIGINT UNSIGNED NOT NULL,
    agreement_id BIGINT UNSIGNED NOT NULL,
    latitude DECIMAL(9,6) NOT NULL,
    longitude DECIMAL(9,6) NOT NULL,
    accuracy_m SMALLINT UNSIGNED NULL,
    speed_kph DECIMAL(5,1) NULL,
    heading_degrees SMALLINT UNSIGNED NULL,
    recorded_at DATETIME(6) NOT NULL,
    stopped_since DATETIME(6) NULL,
    PRIMARY KEY (vehicle_id),
    KEY idx_vehicle_positions_agreement (agreement_id),
    CONSTRAINT fk_vehicle_positions_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id) ON DELETE RESTRICT,
    CONSTRAINT fk_vehicle_positions_agreement FOREIGN KEY (agreement_id) REFERENCES rental_agreements(agreement_id) ON DELETE RESTRICT,
    CONSTRAINT chk_vehicle_positions_place CHECK (latitude BETWEEN -90 AND 90 AND longitude BETWEEN -180 AND 180),
    CONSTRAINT chk_vehicle_positions_speed CHECK (speed_kph IS NULL OR speed_kph >= 0),
    CONSTRAINT chk_vehicle_positions_heading CHECK (heading_degrees IS NULL OR heading_degrees < 360),
    CONSTRAINT chk_vehicle_positions_stopped CHECK (stopped_since IS NULL OR stopped_since <= recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
