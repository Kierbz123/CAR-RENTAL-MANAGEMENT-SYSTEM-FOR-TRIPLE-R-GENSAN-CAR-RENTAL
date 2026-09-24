# M2 — Vehicle Fleet

## File trace and contracts

- `database/migrations/004_vehicles.sql` adds `vehicles`, `vehicle_photos`, `vehicle_locations`, `vehicle_status_logs`, and `vehicle_mileage_logs`.
- `VehicleRepository`, `VehicleStatusLogRepository`, and `VehicleLocationRepository` persist fleet records and retrieve histories.
- `VehicleService` validates vehicle data, initializes new vehicles as `available`, owns status transitions, retires vehicles transactionally, and records odometer entries independently.
- `VehiclePhotoService` accepts JPEG, PNG, and WebP images up to 8 MiB, stores them under private `STORAGE_PATH/vehicles/{vehicle_id}`, and streams them only after staff authorization.
- `VehicleController` exposes role-guarded fleet, status, mileage, location, and photo routes. `system_admin` and `fleet_manager` can operate fleet screens; only `system_admin` can correct mileage or remove an unused location.
- Fleet views use real form routes, `public/assets/js/vehicles.js`, and `public/assets/css/app.css`. Staff home links to fleet screens for fleet managers and administrators.

## Schema decisions

`vehicles` stores the FR-01 fields supplied by the business: required unique plate; nullable unique engine and chassis numbers; make, model, model year, color, body type, transmission, fuel type, seating capacity, daily and optional chauffeur daily rates, current status, current mileage, optional current location, registration/insurance dates, provider, notes, and timestamps/soft-delete marker. MySQL unique indexes permit multiple `NULL` engine/chassis values while enforcing uniqueness once a value is entered. No weekly or monthly rate or GPS device identifier is included.

`vehicle_photos` contract: `photo_id` primary key; `vehicle_id` FK; private relative `storage_path`; `original_filename`; verified `mime`; `size_bytes`; `sort_order`; `uploaded_by` user FK; and `created_at`. Files are not placed under `public/`; retrieval uses the authenticated `/fleet/vehicles/photos/show?photo_id=...` endpoint.

`vehicle_locations.location_status` is `active` or `retired`. Only active, non-removed rows appear in new selections. Retired location IDs remain valid in status and mileage history. `deleted_at` is reserved for removal of an unused location; removal is refused if referenced by a vehicle or either history table.

`vehicle_status_logs` and `vehicle_mileage_logs` are append-only, enforced by database triggers. Status transition, status log, and retirement `deleted_at` are written in one transaction. Every new vehicle starts available and receives initial status and mileage history entries using the onboarding odometer value; later readings use the independent mileage action.

Mileage readings are whole, non-negative kilometers and are recorded independently through `VehicleService::recordMileage()`. Normal readings cannot decrease relative to the latest effective reading. A correction appends a new mileage row referencing the current head of a reading's correction chain, with a mandatory reason; one unique target constraint prevents branching. A correction may repair any reading's chain, including a non-latest reading. The effective value for each root reading is its chain's latest correction. The effective sequence ordered by the root reading's `recorded_at` must remain non-decreasing after the correction; otherwise it is rejected. `vehicles.current_mileage` is the effective value of the latest root reading by recorded time. The original reading and each correction remain in history.

## Routes

- `GET /fleet/vehicles`, `/fleet/vehicles/new`, `/fleet/vehicles/edit?vehicle_id=…`, `/fleet/vehicles/detail?vehicle_id=…`
- `POST /fleet/vehicles/create`, `/fleet/vehicles/update`, `/fleet/vehicles/status`, `/fleet/vehicles/mileage`
- `POST /fleet/vehicles/photos/upload`; authenticated `GET /fleet/vehicles/photos/show?photo_id=…`
- `GET /fleet/locations`; `POST /fleet/locations/create`, `/fleet/locations/retire`, `/fleet/locations/remove`

All mutation forms use the existing CSRF token. Vehicle inputs are validated in the service and constrained in MySQL. Duplicate plate/engine/chassis conflicts return a clear error.

## Setup and acceptance review

Run `php bin/migrate.php` after setting up and seeding migration 001–003. Ensure the configured `STORAGE_PATH` is writable by PHP and outside the web document root. Add branch/lot names through Fleet → Locations; locations are not seeded because the business location list was not supplied.

Acceptance checklist:

- Register/update a vehicle with every supplied FR-01 field; test duplicate plate, engine, and chassis values and blank engine/chassis values.
- Verify new vehicles start `available`, have initial status and mileage history, and appear in status filtering and detail screens.
- Upload supported images and confirm their metadata appears and files are served only through the authenticated route; reject invalid MIME and files over 8 MiB.
- Transition status and verify old/new status, actor, location/mileage snapshot, and history ordering. Retire and verify `retired`, log entry, and `deleted_at` are committed together.
- Record a normal mileage reading without changing vehicle status. Confirm decreasing readings are rejected.
- As `system_admin`, correct a latest chain head for both a latest and a non-latest root reading; verify a correction that breaks neighbor monotonicity is rejected and a valid correction remains visible without rewriting history.
- Retire a location and verify it disappears from new selections while historical references still render. Attempt removal of a referenced location and verify rejection.
- Verify unauthenticated users and non-fleet roles cannot access fleet pages or photos.

## Requirement mapping

- FR-01: `vehicles`, `vehicle_photos`, vehicle form/detail/list, and `VehicleService::validate()`.
- FR-02: current status, sole `transitionStatus()` path, append-only `vehicle_status_logs`, and detail timeline.
- BR-1: unique indexes and application conflict messaging for plate, engine, and chassis numbers.
