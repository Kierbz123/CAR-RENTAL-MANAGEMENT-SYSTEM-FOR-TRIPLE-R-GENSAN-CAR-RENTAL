# M8 — Maintenance: Requirements Resolution

**Status:** Pre-build trace; schedule model, completed-cost correction policy, notification mode, and review holding state are resolved. Due-soon numeric horizon remains open before implementation.  
**Source:** supplied M8 FR-08/FR-09 spec and gap analysis, reconciled against this checkout.  
**Migration:** `011_maintenance.sql` (010 is M7; no 011 exists yet).

## Corrections to the source spec

- The source migration number 010 is occupied by damage reports. Reserve 011 for M8. Recheck the live directory before assigning M9/M10 numbers; do not reserve them now.
- The booking-path concern in item 9 is already guarded in both the selection and transaction paths. `VehicleRepository::availableForBooking()` selects only `available`/`reserved`, and `RentalRepository::createInTransaction()` locks the vehicle row and rejects any current status outside those two values. Maintenance therefore cannot be newly booked through current app code. Add regression tests; no present availability bug was found.
- There is no maintenance implementation, route, migration, table, or class in this checkout. The only current `maintenance` code is M2's vehicle status value. No existing M8 data migration is required.

## Baseline spec, as written

FR-08/FR-09 require vehicle maintenance schedules with time and/or mileage intervals, next-due tracking and a due-soon listing; services with mechanic, labor/parts/other costs and generated total; before/after photos; vehicle status transitions to maintenance and restoration to the prior status; transition logging; and `bin/maintenance-due.php`. M8 uses M2 `VehicleService::transitionStatusInTransaction()` and `recordMileageInTransaction()`. A started service can be completed or cancelled; cancellation restores status without advancing the schedule. M8 is not authorized to add proactive notifications without a product decision.

## Verified integration facts

- `VehicleService::transitionStatusInTransaction()` requires an existing transaction, locks/reads the vehicle, changes `vehicles.current_status`, and appends `vehicle_status_logs` with old/new status. It does not retain a prior status for later restoration. `recordMileageInTransaction()` appends through the correction-chain path and updates the vehicle's effective mileage in the caller's transaction.
- M5 booking selection and creation both reject `maintenance` status, as described above.
- **Pre-existing M5 defect, corrected separately after this trace surfaced it:** `RentalService::reconcileVehicleStatus()` originally assumed it owned every vehicle state and could overwrite manually controlled states (including `out_of_service`, `cleaning`, `maintenance`, and `retired`) after agreement cancellation/no-show. The M5 source now locks/reads the vehicle and no-ops unless status is one of `available`, `reserved`, or `rented`. `bin/test-m5-reconciliation.php` passes all eight combinations of the four protected states and cancellation/no-show. This is a correction to shipped M5 behavior, not new M8 behavior.
- M5 locks in canonical order vehicle → customer → driver → agreement. M8's schedule/service rows must be locked only after vehicle, and before doing any M2 status/mileage writes. There is no customer/driver lock in the maintenance transaction.
- `VehiclePhotoService::storeEvidence()` supplies shared private storage, JPEG/PNG/WebP validation, an 8 MiB per-file limit, path traversal checks, random stored names, and cleanup support. `readEvidence()` prevents public-path access. M8 should reuse this pipeline and add maintenance-specific DB ownership/phase authorization, not create another storage implementation.
- `mechanic` is present in the users role enum but has no current feature gate. M8 is its first intended operational use. No M8 class, table, route, or name collision exists.

## Decision items

### M8-D1. Migration and naming

- **Class:** MECHANICAL · **Priority:** High · **Status:** Resolved · **Confidence:** High
- **Context:** 010 is already installed for M7. M8 classes/routes/tables are otherwise unused.
- **Options:** reuse 010; use 011; renumber existing migrations.
- **Recommendation:** create `database/migrations/011_maintenance.sql`; use `MaintenanceScheduleService`, `MaintenanceService`, `MaintenanceController`, and the specified views. Do not renumber deployed migrations. Recheck M9/M10 migration numbers at their trace gates.
- **Rationale & consequences:** forward numbering preserves immutable migration history and avoids colliding with damage DDL. Renumbering would invalidate deployed checksums.

### M8-D2. One schedule or multiple named schedules

- **Class:** PRODUCT · **Priority:** Blocker · **Status:** Resolved · **Confidence:** High
- **Context:** “per vehicle” can mean one generic schedule or several independent named intervals such as oil and tire service. This choice changes uniqueness, service-to-schedule relationship, due reporting, and whether a completed service advances one or several next-due values.
- **Options:** (A) one active generic schedule per vehicle; (B) multiple named schedules per vehicle, each independently due; (C) one schedule row per vehicle but permit interval fields to be repurposed (not recommended because it loses independent histories).
- **Decision:** choose B, multiple named schedules per vehicle. Each schedule name owns independent intervals, next-due values, and service history. Enforce unique `(vehicle_id,schedule_name)`; allow `schedule_id=NULL` on an unscheduled corrective service, which does not advance a schedule.
- **Rationale & consequences:** distinct work such as oil and tire service needs independent due dates and histories. One generic schedule would conflate them and require a schema/UI migration when a second type is introduced. Multiple schedules add a name and picker but avoid that data loss. Names are bounded to 100 characters and compared under the database collation.

### M8-D3. Due rule when both interval types are set

- **Class:** MECHANICAL · **Priority:** High · **Status:** Recommended · **Confidence:** High
- **Context:** A schedule can use time, mileage, or both. A due report must give one unambiguous result.
- **Options:** due when either threshold is reached; due only when both are reached.
- **Recommendation:** either-first. A date-based schedule is due when Manila business date `>= next_due_date`; a mileage schedule is due when effective `vehicles.current_mileage >= next_due_mileage`. If both are configured, either match makes it due.
- **Rationale & consequences:** this matches “whichever comes first” maintenance practice and the source's time/mileage/both wording. Both-first risks operating past one manufacturer's limit. Boundary tests must cover equality.

### M8-D4. Schedule field integrity, due boundaries, and indexes

- **Class:** MECHANICAL · **Priority:** High · **Status:** Recommended · **Confidence:** Medium
- **Context:** Source fields have no SQL types, nullable rules, units, or access indexes. M2 stores whole kilometers and the app uses `Asia/Manila` for business dates.
- **Recommendation:** `interval_time_days INT UNSIGNED NULL`, `interval_mileage INT UNSIGNED NULL`; at least one must be non-NULL and positive. Store `next_due_date DATE NULL` and `next_due_mileage INT UNSIGNED NULL`; the field for a disabled interval stays NULL. Keep service completion instants UTC and derive date intervals using Asia/Manila calendar dates. Due is inclusive (`today >= due_date`, mileage `>= due_mileage`). Check that adding an interval cannot exceed supported `INT UNSIGNED` mileage/date range; reject overflow instead of wrapping. Use active schedule status and indexed due paths: separate indexes beginning `(status,next_due_date)` and `(status,next_due_mileage)` with vehicle/key columns as selected by D2; validate the actual report query with `EXPLAIN`.
- **Options rejected:** zero as a “disabled” interval (ambiguous with invalid input); one composite index expected to optimize both independent due predicates (not reliable); UTC date boundaries (conflict with existing Manila business dates).
- **Consequences:** schedule interval changes must be validated and auditable. Final uniqueness/index definitions depend on D2.

#### Proposed schema inventory (multiple named schedules per vehicle)

| Table | Column contract |
|---|---|
| `maintenance_schedules` | `schedule_id BIGINT UNSIGNED PK AUTO_INCREMENT`; `vehicle_id BIGINT UNSIGNED NOT NULL FK`; `interval_time_days INT UNSIGNED NULL`; `interval_mileage INT UNSIGNED NULL`; `next_due_date DATE NULL`; `next_due_mileage INT UNSIGNED NULL`; `is_active TINYINT(1) NOT NULL DEFAULT 1`; `created_by`, `updated_by BIGINT UNSIGNED NOT NULL FK users`; `created_at`, `updated_at DATETIME(6) NOT NULL` with current-timestamp defaults and update behavior. Add `schedule_name VARCHAR(100) NOT NULL` and unique `(vehicle_id,schedule_name)` only if D2 selects multiple schedules; if single, use unique active schedule-per-vehicle instead. Add unique `(vehicle_id,schedule_id)` to support service-to-same-vehicle composite FK. |
| `maintenance_services` | `service_id BIGINT UNSIGNED PK AUTO_INCREMENT`; `vehicle_id BIGINT UNSIGNED NOT NULL FK`; `schedule_id BIGINT UNSIGNED NULL` (NULL means unscheduled corrective service; do not advance a schedule); `mechanic_id BIGINT UNSIGNED NOT NULL FK users`; `status ENUM('in_progress','completed','cancelled') NOT NULL DEFAULT 'in_progress'`; `labor_cost`, `parts_cost`, `other_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00`; `total_cost DECIMAL(11,2) GENERATED ALWAYS AS (...) STORED`; `vehicle_status_before ENUM` matching M2's existing closed status set and NOT NULL; `completion_mileage_log_id BIGINT UNSIGNED NULL UNIQUE` (reference to M2 event, not duplicated mileage); `started_at DATETIME(6) NOT NULL`; nullable `completed_at`, `cancelled_at DATETIME(6)`; nullable `cancel_reason VARCHAR(500)`; `needs_review TINYINT(1) NOT NULL DEFAULT 0`; nullable `reviewed_by BIGINT UNSIGNED FK users`, `reviewed_at DATETIME(6)`, and `review_reason VARCHAR(500)`; creation/update actor IDs and timestamps. Add composite FK `(vehicle_id,schedule_id)` → schedule `(vehicle_id,schedule_id)` and `(vehicle_id,completion_mileage_log_id)` → M2's unique vehicle/mileage-log pair so neither reference can point to another vehicle's history. |
| `maintenance_photos` | `photo_id BIGINT UNSIGNED PK AUTO_INCREMENT`; `service_id BIGINT UNSIGNED NOT NULL FK`; `phase ENUM('before','after') NOT NULL`; `storage_path VARCHAR(512) NOT NULL`; `original_filename VARCHAR(255) NOT NULL`; `mime VARCHAR(32) NOT NULL`; `size_bytes INT UNSIGNED NOT NULL`; `uploaded_by BIGINT UNSIGNED NOT NULL FK users`; `created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`. |
| `maintenance_service_status_logs` | `status_log_id BIGINT UNSIGNED PK AUTO_INCREMENT`; `service_id BIGINT UNSIGNED NOT NULL FK`; nullable old status and non-null new status using service enum; `reason VARCHAR(500) NULL` (required for cancellation); `actor_user_id BIGINT UNSIGNED NOT NULL FK`; `created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`. Append-only triggers. |
| `maintenance_schedule_logs` | `schedule_log_id BIGINT UNSIGNED PK AUTO_INCREMENT`; `schedule_id BIGINT UNSIGNED NOT NULL FK`; `actor_user_id BIGINT UNSIGNED NOT NULL FK`; `reason VARCHAR(500) NOT NULL`; typed old/new interval and due-date fields matching schedule columns; `created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`. Append-only trigger; only actual schedule changes create a row. |
| `maintenance_cost_audit_logs` | `cost_audit_id BIGINT UNSIGNED PK AUTO_INCREMENT`; `service_id BIGINT UNSIGNED NOT NULL FK`; old/new labor, parts, and other amounts `DECIMAL(10,2) NOT NULL`; `reason VARCHAR(500) NOT NULL`; `actor_user_id BIGINT UNSIGNED NOT NULL FK`; `created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)`. Append-only triggers. |

For all tables, FK delete behavior is `RESTRICT`, FKs are indexed, and every actor/user key matches `users.id` as `BIGINT UNSIGNED`. Monetary checks reject negative component cost. Empty string is not used to encode missing dates, schedule names, actors, or reasons. The service enum contains cancellation because an aborted service has a different lifecycle outcome from completion.

### M8-D5. Advance next-due from actual completion and freeze later corrections

- **Class:** MECHANICAL · **Priority:** High · **Status:** Recommended · **Confidence:** High
- **Context:** M2 supports later, audited mileage corrections. Recomputing old schedules from a corrected historical odometer could move due thresholds without a new service event.
- **Recommendation:** on successful completion, derive the next date from the actual Manila completion date plus `interval_time_days`, and the next mileage from the odometer value appended at that completion plus `interval_mileage`. Early completion starts the next interval early; late completion does not preserve the old overdue cadence. If a later M2 correction changes that reading's effective mileage, keep the already-written schedule threshold frozen. The next completed service derives a fresh threshold from its then-effective mileage.
- **Rejected alternative:** retroactively recompute every affected schedule after a correction; it creates schedule churn and would need correction fan-out/locking and audit semantics.
- **Consequences:** completion, odometer append, service status, schedule advancement, vehicle status, and their logs are one transaction. Preserve both original and corrected mileage through M2's chain.

### M8-D6. Prior status, restoration, and invalid restore review

- **Class:** MECHANICAL · **Priority:** Blocker · **Status:** Recommended · **Confidence:** Medium
- **Context:** M2 has no saved prior-status field, and M5 may reconcile a reservation while maintenance is underway. Restoring the raw saved value without checking current agreements could resurrect a cancelled reservation.
- **Recommendation:** store `vehicle_status_before` using a closed domain synchronized with `vehicles.current_status`, captured under the vehicle lock when the service starts. Permit service start only from a non-retired, non-rented, non-maintenance state; reject starting while an active rental exists or an unexpired M5 `reserved` hold exists. A confirmed future rental may coexist with maintenance, as the source requires. The M5 reconciliation guard preserves `maintenance` while the service is active. At completion/cancellation, revalidate the saved state against current agreements under lock rather than trusting the snapshot: derive `available`/`reserved`/`rented` from current agreement reality if the saved status was agreement-derived, and restore non-agreement states exactly. If the prior agreement was cancelled/no-showed or any other saved state is no longer valid, set `needs_review=1` and keep the actual vehicle status `maintenance` until a fleet_manager/system_admin explicitly resolves it. Resolution requires a reason and uses `VehicleService::transitionStatusInTransaction()` so status history records the selected state.
- **Storage:** `vehicle_status_before` and `needs_review BOOLEAN NOT NULL DEFAULT FALSE` on `maintenance_services`; review actor/time/reason fields (or an equivalent append-only review record) are required so resolution is auditable. Use the same canonical lock order, vehicle then schedule/service rows.
- **Rejected alternatives:** blindly set `available`; restore `reserved` without checking agreements; leave `maintenance` indefinitely without a surfaced owner queue.
- **Consequences:** M5's generic non-agreement-state guard preserves the vehicle's `maintenance` state during cancellation/no-show. In an invalid-restore case, the vehicle remains unavailable in `maintenance` until a responsible manager resolves it.

### M8-D7. Service lifecycle, cancellation, and transition history

- **Class:** MECHANICAL · **Priority:** High · **Status:** Recommended · **Confidence:** High
- **Context:** Source has only `in_progress` and `completed`; a started but abandoned service otherwise strands the vehicle.
- **Recommendation:** use `ENUM('in_progress','completed','cancelled')`. Starting captures prior status and transitions vehicle to `maintenance`; completion records mileage, advances schedule, and restores/reviews status; cancellation requires a non-empty reason, does not advance any due threshold, and runs the same restore/review path. Record service-state changes in append-only `maintenance_service_status_logs` with actor, from/to state, reason, and timestamp. M2 `vehicle_status_logs` remains the vehicle-status history.
- **Rejected alternative:** delete or reset an abandoned service; it loses evidence of labor/costs and can break status restoration.
- **Consequences:** incomplete service can retain already-incurred costs/photos; whether such costs may be edited later is D10.

### M8-D8. Cost precision and generated total

- **Class:** MECHANICAL · **Priority:** High · **Status:** Resolved recommendation · **Confidence:** High
- **Context:** MySQL arithmetic over any NULL cost would make a generated sum NULL. The sum of three DECIMAL(10,2) maximum values also exceeds DECIMAL(10,2).
- **Spec requirement:** `labor_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00`; `parts_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00`; `other_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00`; `total_cost DECIMAL(11,2) GENERATED ALWAYS AS (labor_cost + parts_cost + other_cost) STORED`. Reject negative amounts. Keep calculations fixed point; reject any combined total exceeding the chosen generated precision. Empty/in-progress service therefore has total 0.00, never NULL.
- **Rejected alternative:** nullable costs plus `COALESCE` only in the generated expression (preserves an ambiguous “unknown vs zero” state and conflicts with required explicit zero semantics); FLOAT (financial rounding).

### M8-D9. Mileage and maintenance photos

- **Class:** MECHANICAL · **Priority:** Medium · **Status:** Recommended · **Confidence:** High
- **Context:** M2 owns odometer records and M2/M7 share private photo validation/storage. Maintenance photos need ownership and phase integrity.
- **Recommendation:** completion uses `VehicleService::recordMileageInTransaction()`; do not add a competing odometer field to services. Store the returned mileage-log ID as a unique nullable FK on the service so due calculations and history point to M2's event without copying its mileage. `maintenance_photos` has its own `photo_id BIGINT UNSIGNED` PK, `service_id` and `uploaded_by` BIGINT UNSIGNED FKs with `ON DELETE RESTRICT`, `phase ENUM('before','after')`, private relative storage path, original filename, validated MIME, byte size, and creation time; index `(service_id,phase,photo_id)`. Store through `VehiclePhotoService::storeEvidence()` in a service-specific private directory and remove the file if metadata insertion rolls back. Serve only through an authenticated route with private/no-store headers. Apply the established per-file 8 MiB and JPEG/PNG/WebP rules. Limit to 10 photos per phase per service, enforced under a service-row lock. This keeps upload counts and storage bounded.
- **Rejected alternative:** put paths on `maintenance_services` (repeating group); store images under public web root; invent a second validator.

### M8-D10. Completed-service cost correction policy

- **Class:** PRODUCT · **Priority:** Medium · **Status:** Resolved · **Confidence:** High
- **Context:** This is internal operating expense data, but it feeds reporting. Existing append-only policy is strongest for customer money and disputed facts; it should not be copied automatically.
- **Options:** (A) permit fleet_manager/system_admin to edit completed costs with mandatory reason and before/after audit; (B) freeze a completed service and add a separately linked cost correction/addendum record; (C) permit mechanic edits without a correction trail.
- **Decision:** choose A. Once completed, only `fleet_manager` or `system_admin` may edit cost fields; every edit requires a non-empty reason and writes an immutable before/after audit entry. `mechanic` may update costs while `in_progress`; those edits record actor/time. `auditor` is read-only.
- **Rationale & consequences:** this internal expense data has less dispute exposure than customer charges/deposits. An append-only correction chain would add unnecessary data/reporting machinery; unrestricted silent editing would lose accountability.
- **Known future gap:** M7 damage reports do not link to maintenance services. Keep that cross-link out of M8 unless a later requirement calls for it.

### M8-D11. Due-soon behavior and thresholds

- **Class:** PRODUCT · **Priority:** Medium · **Status:** Recommended (pull mode resolved; horizon open) · **Confidence:** Medium
- **Context:** Source names a due-soon listing and CLI report, not proactive messaging or a due-soon horizon. SMS would create costs and additional consent/policy behavior.
- **Options:** (A) pull-only report/list, with owner-configured windows in days and kilometers; (B) enqueue proactive notifications when a threshold is crossed; (C) report only already-due items.
- **Decision:** pull-only is accepted; no proactive notification. `bin/maintenance-due.php` is a read-only report with human-readable output and CSV mode, nonzero exit on query/config failure, and safe replay. Scheduling cadence belongs in deployment documentation, not a mutating job.
- **Threshold recommendation requiring confirmation:** due-soon means within 30 Manila calendar days OR within 500 km; both are configurable without migration. Due itself remains the inclusive interval boundary. Change these defaults before implementation if the owner wants another horizon or due-only behavior.
- **Consequence:** push would require deduplication, delivery policy, templates, consent handling, and additional acceptance; it is outside the accepted pull-only scope.

### M8-D12. Roles and review ownership

- **Class:** MECHANICAL · **Priority:** High · **Status:** Recommended · **Confidence:** High
- **Context:** `mechanic` exists but currently gates no behavior. M7 separates raw capture from higher-impact configuration/review.
- **Recommendation:** `mechanic` starts/completes/cancels services, records costs, and uploads photos; `fleet_manager` and `system_admin` create/edit/retire schedules and resolve needs-review cases. Both may read maintenance history. `auditor` is read-only. `front_desk`, `finance_staff`, `driver_coordinator`, and `support_staff` have no maintenance mutations unless a later source spec assigns one. Verify actual role enum before adding route gates; it currently includes all these roles.
- **Rejected alternative:** allow any fleet-facing role to edit schedules or resolve status mismatches; those actions affect operating availability and fleet reporting.

### M8-D13. Schedule and service foreign keys, audit, and rollback

- **Class:** MECHANICAL · **Priority:** High · **Status:** Recommended · **Confidence:** Medium
- **Recommendation:** all vehicle, schedule/service, photo, and actor relationships use indexed, type-matched `BIGINT UNSIGNED` FKs with `ON DELETE RESTRICT`; no maintenance history cascades. Maintenance status transitions use append-only logs; schedule interval/activation edits and privileged cost corrections must capture actor, time, reason, and old/new values. Do not make `maintenance_services` globally append-only until D10 is settled. Keep file data outside public storage. Migration 011 is forward-only like the existing runner: no automatic destructive down migration. A partial DDL failure is manually repaired; after data exists, correction/disablement uses a new migration and preserves records.
- **Alternatives rejected:** CASCADE (would erase maintenance evidence); a fake rollback that drops populated history; silently overwriting schedule or cost changes.

### M8-D14. Transaction and concurrency contract

- **Class:** MECHANICAL · **Priority:** Blocker · **Status:** Recommended · **Confidence:** High
- **Recommendation:** start/complete/cancel lock vehicle first, then agreement rows in canonical order where checked, then schedule and service; use conditional status updates and one transaction for maintenance status, logs, mileage, and due advancement. The M5 booking path serializes on the vehicle lock. Two mechanics racing to complete/cancel the same service: exactly one transition commits; the loser reloads and gets a state-changed error. A booking racing with maintenance start either commits first and leaves an unexpired `reserved` hold that service-start rejects, or waits and then sees `maintenance` and is rejected. A cancellation/no-show racing with active service keeps vehicle status `maintenance` due to M5's non-agreement-state guard. Photo files written before DB commit are removed if row insertion fails.
- **Rejected alternative:** lock only the maintenance row (can race with booking or M5 reconciliation); update vehicle status outside the service transaction (can strand state).

### M8-D15. Safe state while a prior status awaits human resolution

- **Class:** PRODUCT · **Priority:** High · **Status:** Resolved · **Confidence:** High
- **Context:** If the stored prior status is no longer valid, the source requires a manager review flag but does not say what `vehicles.current_status` should be before the human resolves it. Returning `available` risks a new booking before review.
- **Options:** (A) set `out_of_service`; (B) retain `maintenance`; (C) derive available/reserved automatically and treat review as advisory.
- **Decision:** retain `maintenance` while `needs_review=1`. Reject A because it adds a second holding state for the same unresolved situation; reject C because it can make an unresolved vehicle bookable.
- **Consequence:** the resolution route must require fleet_manager/system_admin, require a reason, record reviewer identity, and transition through M2's status-history path. Clear `needs_review` atomically with resolution.

## Test matrix

| ID | Given / when | Then |
|---|---|---|
| D1 | M7 migration 010 exists; clean DB applies 011 | Migration applies in order and runner replay verifies checksum. |
| D2/D4 | Schedule has both intervals; one threshold equals now/current mileage | Either exact equality marks due; the other threshold need not be met. Test time-only, mileage-only, both, empty invalid schedule, and zero/overflow rejection. |
| D5 | Service completes early/late with both intervals | Next date uses Manila completion date; next mileage uses the mileage reading appended on completion; correction after completion leaves the stored threshold unchanged. |
| D6/D14 | Start maintenance from each allowed/blocked vehicle status and with reserved/confirmed/active agreements | Allowed start stores exact prior status and logs transition; rented/retired/already-maintenance, active rental, or unexpired reservation hold reject. A confirmed future booking is allowed. Completion restores valid status; status and mileage commit/rollback together. |
| D6/D7/D15 | Prior state was reserved because of Agreement A; while service is active, Agreement A is cancelled/no-showed and an unrelated agreement on the same vehicle also changes state | At completion/cancellation, reload current agreement reality under lock; never resurrect the stale reservation. Keep `maintenance`, set `needs_review`, and surface it for fleet_manager/system_admin resolution whenever the saved prior state is no longer valid. A still-confirmed valid reservation is restored as `reserved`. |
| D6 | M5 cancel/no-show/return reconciliation runs while service is in_progress | Vehicle remains maintenance and booking creation remains rejected. After maintenance ends, reconciliation reflects current rental state. |
| D7 | Service is cancelled after costs/photos exist | Cancellation reason and status log persist, costs/evidence are retained, due thresholds do not advance, and prior-state restore/review runs. Repeat/cross-terminal transition rejects. |
| D8 | New in-progress service has omitted costs; each of three cost columns at max | Defaults yield generated 0.00; one maximum is valid; combined overflow and negative costs reject; total remains exact DECIMAL. |
| D9 | Valid and invalid photos; service completion mileage | Valid private images round-trip authenticated; bad MIME, >8 MiB, invalid phase, wrong ownership reject; rollback removes orphan files. Completion creates exactly one M2 mileage event, no second mileage source. |
| D10 | Completed cost correction is submitted | Fleet_manager/system_admin edits require a reason and append before/after audit; mechanic and auditor edits reject; generated total matches new components. |
| D11 | Due report repeated and run with query failure | Pull-only output is stable across reruns; due-soon uses the selected configurable windows; failure exits nonzero; no notification rows are enqueued. |
| D12 | Each role calls read/mutate/configure/review routes | Mechanic can service but not configure/resolve; fleet_manager/admin can configure and resolve; auditor read-only; other roles denied as specified. |
| D13/D14 | Two completion requests, completion vs cancel, booking/hold vs maintenance start, unrelated agreement cancellation/no-show during maintenance | Row locks/conditional writes allow one legal result, prevent lost status/mileage, reject stranded unconfirmed holds, preserve maintenance during service, and revalidate the prior status before restore. |
| D13 | Vehicle or schedule is deleted while history exists; DDL fails partway | RESTRICT rejects deletion; migration recovery does not drop populated maintenance history. |

## Assumptions register

| Assumption | Confirmation / refutation |
|---|---|
| Manila calendar dates are the intended schedule-day basis. | Confirm against business operations; existing booking dates and license checks use Asia/Manila. |
| “Whichever comes first” is the intended either-first rule. | Recommended from the supplied interval wording; test exact boundaries. |
| Existing VehiclePhotoService can be reused for maintenance namespace. | Verified generic private storage/validation methods exist; implementation must test cleanup/read route. |
| Existing M5 booking integrity excludes maintenance. | Verified in list and locked creation paths; preserve with runtime regression checks. |

## Out of scope

- Proactive SMS/email notifications (explicitly deferred by D11).
- Predictive maintenance, parts inventory, supplier/work-order management, labor timesheets, and service pricing rules.
- Rewriting M2 mileage correction history or M5 agreement overlap logic.
- M9/M10 schema assumptions; their migration numbers must be rechecked at their trace gates.

## Definition of done for the trace

- [x] Migration number/name collision checked; 011 is the next available migration.
- [x] M2 transition/mileage APIs and M5 booking selection, write guard, reconciliation, and lock behavior inspected.
- [x] VehiclePhotoService private evidence pipeline inspected.
- [x] Owner chose multiple named schedules per vehicle (D2).
- [x] Owner chose role-gated editing with mandatory reason and immutable audit for completed-service costs (D10).
- [x] Owner chose pull-only due-soon reporting; no proactive notifications (D11).
- [x] Owner chose to keep actual vehicle status at `maintenance` until explicit review resolution (D15).
- [ ] Owner confirms the due-soon horizon. Recommendation: configurable 30 Manila calendar days or 500 km, whichever is reached first.
- [ ] Final field inventory, exact indexes, role gates, acceptance cases, and generated total are synchronized into the plan before implementation.
- [ ] Implement only after open product items are resolved; then run every applicable test-matrix row against MySQL 8 and HTTP routes.
