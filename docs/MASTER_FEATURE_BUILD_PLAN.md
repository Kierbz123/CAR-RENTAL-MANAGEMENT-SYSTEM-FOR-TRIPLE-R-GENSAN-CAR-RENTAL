# Master Feature Build Plan (v3.6)

> v3.6 (2026-09-30): Carries forward v3.5, closes M8's due-soon decision with approved global defaults and nullable per-schedule overrides, and marks the pre-build trace ready for implementation. M9/M10 source specifications remain absent from this checkout/history.

## Build workflow and source of truth

For every new module, use this six-step workflow:

1. **Trace proposal:** before implementation, write the module's source requirements, current-code touchpoints, schema/service/controller/UI paths, lock interactions, and open decisions in `docs/FEATURE_MN.md`. Do not begin implementation while material data, money, security, or concurrency decisions remain unresolved.
2. **Implement:** make the smallest complete change that satisfies the approved trace and acceptance criteria.
3. **Self-review:** compare changed behavior and migration effects against the trace, prior modules, security boundaries, and failure/rollback cases.
4. **Verify:** run the module's database-backed and HTTP acceptance checks where applicable; retain concrete pass/fail output in its feature doc.
5. **Plan sync:** update this plan with **Decided (reasoning):** annotations, actual migration numbers, verified contracts, known limitations, and dependencies before committing the module. Include the plan and feature doc in the same commit as the implementation.
6. **Commit and report:** commit the implementation, feature trace, acceptance evidence, and plan sync together, then report what passed and what remains open.

Each module section links its `docs/FEATURE_M*.md` as the detailed contract. If a section conflicts with the feature doc or current code, the feature doc/code is authoritative; update this plan before the next module commit. A trace proposal is a required written gate, not an informal review step.

## Review dispositions from the master-plan analysis

- **Soft-delete uniqueness:** the current design reserves vehicle plates/engine/chassis, driver-license fingerprints, and customer-document fingerprints across retained rows. M3 fingerprint uniqueness across deleted/blacklisted customers is deliberate identity protection. No current requirement authorizes reusing these identifiers after soft-delete, so do not weaken the constraints. If plate/license reuse becomes a requirement, specify retention and collision behavior first, then add a forward migration and acceptance case.
- **ENUM policy:** use ENUM for closed state-machine vocabularies whose unknown values must fail fast; use lookup/reference tables for business-managed lists that need edits without schema deployment. Stable identity/customer/contact values may remain ENUM/CHECK constrained. M5 deposit values remain the current six-state contract; adding a refund sub-state requires a finance/product decision and migration.
- **Overlap indexes:** M5 already defines `idx_rentals_vehicle_dates_status (vehicle_id,start_date,end_date,status)` and `idx_rentals_driver_dates (driver_id,start_date,end_date,status)`. BR-3/BR-4 still perform their authoritative locked transaction checks; do not add duplicate indexes without query-plan evidence.
- **Billing/timezone:** M5 bills by Manila calendar-date boundaries using `GREATEST(DATEDIFF(end_date,start_date),1)`, not elapsed 24-hour periods. There is no late-return grace-hour conversion. M5 dates use no `CONVERT_TZ()` and no MySQL timezone tables; actual event timestamps remain UTC. Any grace-hour/late-day rule needs a product decision and explicit billing acceptance cases.
- **Auth/security:** password hashes use PHP `password_hash(..., PASSWORD_DEFAULT)` / `password_verify()` with no pepper; all state-changing HTTP routes use the session CSRF token; login rotates the session ID; cookies are HttpOnly/SameSite=Lax and Secure under HTTPS; database session expiry is absolute (12 hours by default). M1 security logs include event, actor/subject, hashed email, IP, user agent, and time, and exclude passwords, bearer tokens, and request/response bodies. User-agent hashing/retention is a separate privacy decision; do not silently change the existing audit contract.
- **PII:** M3 decryption is enforced at the `CustomerController` reveal role boundary and default masked projections; M10 report acceptance must verify auditors and other non-authorized roles never receive decrypted customer PII.
- **Idempotency:** inbound STOP imports and notification work already have explicit duplicate controls. Booking-create retries and charge submissions do not yet have a caller-supplied idempotency key; record this as a M5 follow-up and define response/replay semantics before adding schema/API behavior.
- **Scheduled jobs:** every new `bin/*.php` job must be bounded, replay-safe, and safe under concurrent invocations, with row locks/conditional updates and a repeated-run acceptance case. M5 expiry/reminder/STOP jobs are reviewed separately in their module evidence.
- **Append-only recovery:** do not add a generic trigger-disabling helper. Normal correction uses compensating records/reversals. Any exceptional repair requires an approved DBA procedure, named affected trigger/table, before/after evidence, and an independent audit record; runtime app credentials cannot disable triggers.
- **M9 documents:** polymorphic `(entity_type, entity_id)` references cannot use ordinary InnoDB foreign keys. M9 must choose explicit per-entity junction tables or provide app-layer existence checks plus a repeatable orphan-integrity report and acceptance case before implementation.
- **Operations:** MySQL DDL is not transactional; the migration runner now stores SHA-256 checksums and rejects changed applied files. A partial failure requires manual repair and a new forward migration. See the portable local MySQL 8 backup/restore and startup procedure in `docs/ops/LOCAL_MYSQL8.md`.
- **Deferred acceptance infrastructure:** M11 should include a schema verifier for expected triggers/checks/generated columns, idempotent seed replay, and explicit FR/BR-7–BR-11 mapping. Do not count a coverage table as runtime evidence. Known limitations must be maintained per module.

## M1 — Auth, Roles & Sessions

**Authoritative details:** [`docs/FEATURE_M1.md`](docs/FEATURE_M1.md). If this summary conflicts, the feature doc and current implementation win; sync this plan before the next module commit.

**Verified:** `must_change_password` is checked after session authentication and before role enforcement; only password change and logout are allowed while set. IP/email throttling occurs before account failure counting, and a throttle does not increment `failed_login_count`. Locked and ordinary bad-credential attempts return the same outward failure. Five consecutive failures lock the row; locking and persisted-session invalidation occur in the authentication transaction. `StaffAuth` remains a compatibility facade over persisted sessions, and the auth, staff notification, front controller, and seed paths use the migrated auth/repository APIs.

**Decided (reasoning):** Keep throttling and per-account lockout independent. This limits request abuse without allowing an attacker to lock a known account by sending throttled guesses. Keep lockout response text/status indistinguishable from a wrong password to avoid account-state disclosure. Lock sessions atomically with the account so no existing browser session survives a lock.

Temporary passwords are hashed at rest and returned only in the one-time administrator response; application/security logging paths do not log credentials or request/response bodies. This was verified by static source review, not by observing every deployment's web-server/proxy logging configuration. Production deployments must not capture request or response bodies.

## M2 — Vehicle Fleet

**Authoritative details:** [`docs/FEATURE_M2.md`](docs/FEATURE_M2.md). If this summary conflicts, the feature doc and current implementation win; sync this plan before the next module commit.

**Verified; no rewrite required:** Migration 004 and `docs/FEATURE_M2.md` agree on vehicle fields, `vehicle_locations` as reference data, append-only status/mileage histories, and correction chains. `recordMileage()` is independent of status transitions; GPS and weekly/monthly pricing remain deferred. `recordMileageInTransaction()` is available for rental lifecycle transactions.

**Decided (reasoning):** Keep mileage as an independent append-only measurement with explicit correction links. This preserves the original reading and makes a correction auditable without coupling odometer entry to a vehicle state change.

## M3 — Customer Management

**Authoritative details:** [`docs/FEATURE_M3.md`](docs/FEATURE_M3.md). If this summary conflicts, the feature doc and current implementation win; sync this plan before the next module commit.

**Verified:** Migration 005 stores identity documents separately from customers and uniquely indexes one HMAC fingerprint across all rows, including blacklisted and soft-deleted customers. `CustomerPiiCipher` uses AES-256-GCM with a fresh nonce and a dedicated `CUSTOMER_PII_KEY`; HKDF derives separate encryption and fingerprint keys. License aliases normalize to the canonical document type before type-bound fingerprinting. Only `front_desk` and `system_admin` can reveal PII; other roles receive masked values. Blacklist and soft-delete lock the customer row; blacklist does not invalidate an open rental, while soft-delete rejects reserved, confirmed, active, and returned agreements. Corporate and referral requirements are enforced by both service validation and database checks.

**Decided (reasoning):** Keep identity records and encrypted values out of the customer row, and use canonical type plus normalized identifier in the keyed fingerprint. This makes alias entry collide consistently while preserving cross-customer uniqueness regardless of eligibility state. Restrict decryption to the operational roles that need it; audit/report views remain masked.

## M4 — Drivers (runtime-verified)

**Authoritative details:** [`docs/FEATURE_M4.md`](docs/FEATURE_M4.md). If this summary conflicts, the feature doc and current implementation win; sync this plan before the next module commit.

**Verified in the current checkout:** Migration 006, driver services/controllers, and `docs/FEATURE_M4.md` implement encrypted driver PII, unique keyed license fingerprints, append-only status history, eligibility selection, and shared M5 overlap checks. `driver_coordinator` has read-only driver master access and scheduling visibility. Full M4 runtime acceptance passed against MySQL 8.0.46 on 2026-09-30: edit/encrypted address updates, concurrent contact-primary handling, soft-delete blocked by a reserved agreement, and HTTP role/reveal boundaries all passed alongside encryption, masking, eligibility, and history checks.

**Status:** M4 acceptance checklist is closed based on the recorded database-backed and HTTP outputs.

## M5 — Rentals, Lifecycle & Charges

**Authoritative details:** [`docs/FEATURE_M5.md`](docs/FEATURE_M5.md). If this summary conflicts, the feature doc and current implementation win; sync this plan before the next module commit.

**Verified:** Migrations 007 and 008 add rental/deposit status histories, append-only charges, required cancel/no-show reasons, the six deposit states (`not_required`, `due`, `held`, `released`, `refunded`, `forfeited`), and agreement identity immutability for `vehicle_id`/`customer_id`. Completion is blocked while a deposit is due or held. No-show waits until scheduled pickup plus `NO_SHOW_GRACE_MINUTES` (default 60). Pickup/return record mileage through M2's transaction-aware method in the lifecycle transaction. Reserved booking links expire at the reservation hold; confirmed links use configured TTL. Non-transactional SMS defaults off; suppressed enqueue attempts are persisted as `suppressed_by_policy`, while transactional booking messages remain permitted after STOP. Charge corrections append reversals and replacement charges; rental foreign keys use RESTRICT. Expiry, reminder, and STOP-consumer scheduled jobs are present and designed to be idempotent.

The shared lock order is vehicle → customer → driver → agreement. `RentalService::reconcileVehicleStatus()` checks active agreements first, then confirmed agreements whose end date has not passed in Manila, then makes the vehicle available. A confirmed future rental therefore reserves the vehicle immediately, even before its start date.

**Decided (reasoning):** Preserve immutable agreement identity because lifecycle code reads a snapshot to discover vehicle/customer IDs before acquiring ordered row locks. Preserve append-only histories and ledger corrections so money and state changes retain their prior values and reasons. Use one reconciliation path so cancellation, no-show, expiry, and return apply the same fleet precedence.

**Decided (reasoning):** A confirmed agreement reserves the vehicle immediately, even when pickup is far in the future. This is the shared self-drive/chauffeur behavior; driver assignment never reconciles vehicle status. Transaction-level BR-3 overlap checks prevent actual double-booking independently of the displayed `current_status`. The cost is limited to the fleet list showing the vehicle as unavailable between now and that future rental. Near-term status changes would add thresholds, scheduled transitions, idempotency, and tests for a speculative display problem. M10 period-availability reporting uses status history and agreement date ranges, so it does not rely on this live snapshot. Revisit only if staff report that far-future confirmed rentals make the fleet list hard to use; use that operational evidence to decide whether to change it.

## M6 — Chauffeur Rentals

**Authoritative details:** [`docs/FEATURE_M6.md`](docs/FEATURE_M6.md). If this summary conflicts, the feature doc and current implementation win; sync this plan before the next module commit.

**Verified:** Migration 009 adds the FR-05 CHECK (driver required except for reserved, cancelled, and no-show chauffeur agreements) and a NULL-safe `driver_id` update trigger that permits changes while old status is reserved or confirmed and blocks them afterward. `ChauffeurService` assigns/reassigns drivers under vehicle → customer → driver → agreement locks, checks rental type/status and assignment conflicts under the driver lock, reverses the old fee, and appends the new fee. Removal is reserved-only. Confirmation validates FR-05. `driver_coordinator` can manage assignments but not agreement lifecycle or financial actions; front desk can assign during booking. The date-filtered picker excludes inactive, deleted, expired, and overlapping drivers. The two-phase flow preserves a created reservation if assignment fails. Cancellation/no-show reverse an unreversed chauffeur fee. M5 chauffeur-unavailable stopgaps are absent from `RentalService`, `RentalRepository`, and `booking-new.php`.

**Verified and corrected in the current worktree:** `ChauffeurService::assignDriver()` checks the locked driver's license expiry against the current Asia/Manila date. Confirmation re-locks the assigned driver in the canonical vehicle → customer → driver → agreement order and `validateForConfirmation()` checks active status and license expiry again. The assignment and confirmation expiry acceptance cases passed in the MySQL 8.0.46 run on 2026-09-30.

**Decided (reasoning):** Keep driver assignment separate from vehicle reservation so a conflict does not discard the customer's reservation. Keep fees in the append-only charge ledger and reverse them on reassignment or cancellation/no-show rather than editing prior entries.

## M7 — Damage Reporting (migration 010)

**Authoritative details:** [`docs/FEATURE_M7.md`](docs/FEATURE_M7.md), resolved against the supplied FR-07/BR-6 source excerpt and owner decisions. Use `database/migrations/010_damage.sql`; migration 009 belongs to M6 and must remain unchanged.

**Decided (reasoning):** `front_desk` and `fleet_manager` capture pre/during/post reports because they already record pickup/return. Liability decisions belong to `fleet_manager` and `system_admin`; finance staff alone may post a charge. DamageService stores repair-cost suggestions and never writes `rental_charges`; charge posting calls M5's `RentalService::addCharge()` path. Liability corrections append a reasoned, superseding decision. Reuse M2's validated private image pipeline. A clean pre report may have no images; newly reported damage during/post requires at least one. Enforce one pre and one post report per agreement, while allowing multiple during reports. These evidence/cardinality choices avoid forcing staff to fabricate photos for a clean walkaround and preserve repeated incident reports during a rental.

**Implementation contract:** migration 010 uses restrictive agreement references; all phases remain tied to an agreement. Damage evidence is authenticated/private. Every multi-row write follows vehicle → customer → driver → agreement. Audit and report acceptance must prove auditors are read-only. M5's existing `damage` charge type is only the financial ledger entry, not damage capture.

**Verified:** Migration 010 applied after 009 on MySQL 8.0.46. The 19 database checks passed for clean inspections, one pre/post vs repeatable during reports, required during/post damage evidence, reasoned superseding liability decisions, duplicate-charge protection, append-only triggers, agreement RESTRICT, and finance adjustment posting through M5's charge path. M7 HTTP acceptance passed 17 checks for capture/liability/charge roles, auditor restrictions, agreement and damage detail views, actual image upload, authenticated private retrieval, append-only photo rows, and no-store response headers. The isolated acceptance flow also exercised checksum baselining for legacy migration rows without a checksum column, then verified a third replay.

**Known limitation:** repair cost is an estimate entered during damage capture and stored for finance review. No approved severity/type price formula was supplied, so the service validates and preserves that estimate rather than inventing a tariff. Pre capture is limited to confirmed agreements, during to active, and post to returned/completed. Report capture is not a prerequisite for M5 pickup/return transitions.

## M8 — Maintenance (trace gate; not implemented)

**Authoritative details:** [`docs/FEATURE_M8.md`](docs/FEATURE_M8.md). Use migration `011_maintenance.sql`; M7 owns 010. Recheck migration names again before M9/M10.

**Verified:** M2 status transitions and mileage recording require the shared vehicle lock/transaction and append history. M5 both lists only `available`/`reserved` vehicles and rejects a locked vehicle in any other status during booking creation, so the maintenance booking guard already exists. A pre-existing M5 defect was corrected separately: `RentalService::reconcileVehicleStatus()` now locks the vehicle and no-ops unless its current state is `available`, `reserved`, or `rented`. Thus cancellation/no-show cannot overwrite deliberate fleet states such as `out_of_service`, `cleaning`, `maintenance`, or `retired`. `bin/test-m5-reconciliation.php` passed all eight combinations of four protected states and cancel/no-show.

**Decided (reasoning):** Use multiple named schedules per vehicle, either-first time/mileage due semantics, Manila business-date boundaries with inclusive due checks, M2's transaction-aware mileage writer, the shared private `VehiclePhotoService` evidence pipeline, and forward-only migration/RESTRICT history. A completed service's due threshold is based on actual completion date/mileage and remains frozen if that mileage event is corrected later. A started service needs an explicit `cancelled` status and restoration path. Fleet managers/system admins may correct completed-service costs only with a reason and immutable before/after audit. Due-soon delivery is pull-only, with configurable global defaults of 30 Manila calendar days or 500 km and nullable per-schedule overrides for either dimension; NULL inherits the global setting. If prior status becomes stale during maintenance, keep the vehicle in `maintenance` until explicit fleet-manager/system-admin resolution. Exact schema, numeric precision, NULL/default rules, keys, indexes, audit records, and generated-cost NULL/overflow treatment are specified in `FEATURE_M8.md`.

**Open PRODUCT decisions:** none for the M8 trace. Its resolved contract is ready for implementation; implementation and runtime acceptance remain outstanding.

## M9–M10 — source-plan audit status

Their original definitions were not present in this checkout or Git history. The supplied review flags M9 polymorphic documents and M10 reporting/PII boundaries, but does not provide complete module specs or migration numbers. Do not infer those contracts from the review summary. Before each module, source its canonical requirements and verify planned migration numbers against the live migrations, then write its trace under the six-step workflow.

## Acceptance state and dependencies

- Full M4 runtime acceptance, all 15 M6 chauffeur checks (including license expiry at assignment and confirmation), migration 009 raw-SQL checks, and M7 database/HTTP acceptance passed against fresh MySQL 8.0.46 acceptance databases on 2026-09-30. The legacy migration checksum-baseline and subsequent replay also passed.
- M7 implementation and acceptance are complete on migration 010. M8's written pre-build resolution trace is complete and ready for implementation; no M8 implementation/runtime acceptance is claimed yet. M9/M10 remain behind their source-trace gates.
