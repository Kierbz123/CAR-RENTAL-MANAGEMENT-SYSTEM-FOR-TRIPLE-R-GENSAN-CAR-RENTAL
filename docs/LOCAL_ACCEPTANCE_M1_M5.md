# Local Runtime Acceptance — Features E, Magic Links, M1–M5

Run this checklist in order before starting M6. It combines each module's acceptance checks with the accumulated cross-module concurrency, privacy, and lifecycle scenarios. For each item, record **PASS**, **FAIL**, or **BLOCKED**, plus environment, date, and defect details. Do not mark a module complete while a required item is unrun.

| Run details | Value |
|---|---|
| Tester / date | |
| OS / PHP version | |
| MySQL version | |
| Git commit tested | |
| Overall result | |

## 0. Environment and schema setup

- [ ] Install PHP 8.2+ with `pdo_mysql`, `curl`, `mbstring`, `json`, and `openssl`; install MySQL 8.0+ with InnoDB.
- [ ] Follow README database-account and `.env` setup. Keep migration credentials separate from runtime credentials.
- [ ] Set distinct secrets for `APP_KEY`, `SMS_CIPHER_KEY`, `CUSTOMER_PII_KEY`, and `DRIVER_PII_KEY`; preserve the PII keys securely. Do not configure live SMS credentials until ready to send real messages.
- [ ] Run `php bin/migrate.php`; confirm migrations 001–008 apply in order without warnings or errors. Migration 008 is the rental identity guard; new future migrations must use the next unused number.
- [ ] Separately verify migration 003 against a populated Feature E `users` table in a disposable schema: apply 001, seed existing `system_admin`/`fleet_manager` rows, then apply 002–003. Confirm existing rows survive and the final role ENUM has exactly eight canonical values.
- [ ] Run `php bin/seed.php`; sign in with configured seed credentials and change the first-login password.
- [ ] Start `php -S 127.0.0.1:8000 -t public public/router.php`; confirm login and staff home load over localhost.
- [ ] Configure the README schedules: notification worker and rental expiry/reminder/STOP jobs every minute; session sweep every five minutes. Keep test SMS provider settings inert or pointed at a safe test account.

**Result / defect notes:**

## 1. Feature E — notifications and inbound STOP events

- [ ] Enqueue a notification and inspect the database: body is encrypted at rest; only authorized staff history returns decrypted message preview; no ciphertext appears in the page/API. Magic-link message preview remains masked.
- [ ] Run two notification workers concurrently against multiple due queue rows. Confirm claim semantics prevent both workers from sending the same row.
- [ ] Exercise configured provider failure paths: known retryable errors back off; unknown provider outcome is not automatically retried; daily budget and idempotency prevent duplicate enqueue/send.
- [ ] POST a valid signed inbound STOP webhook. Confirm full raw body and normalized event are inserted before the fast HTTP 200 response. Replay the same provider message ID and confirm no duplicate event.
- [ ] POST invalid/unparseable callback data and confirm provider still receives HTTP 200 without an event being created.
- [ ] Attempt UPDATE and DELETE on `inbound_sms_events`; database triggers must reject both.
- [ ] Confirm notification history requires an authenticated staff session and cannot reveal raw tokens or ciphertext.

**Result / defect notes:**

## 2. Magic-Link infrastructure

- [ ] Issue a test token through an internal caller. Confirm only the token hash is stored and the message body remains encrypted.
- [ ] Open the link and redeem it through the page. Confirm token comes from the URL fragment and is sent only in the POST body; attempts to pass a token in the query string are rejected.
- [ ] Redeem once successfully; replay must fail. Wrong-purpose, expired, and consumed tokens return the same generic failure. Rate limits apply to token/IP/contact as configured.
- [ ] Confirm the successful response contains no customer PII or booking identifier and the server session is purpose-bound.
- [ ] Verify booking-independent TTL behavior now; later M5 links are checked again in section 7 for hold-capped reserved tokens and normal TTL after confirmation.

**Result / defect notes:**

## 3. M1 — authentication, roles, sessions

- [ ] Inspect `SHOW CREATE TABLE users`: the role ENUM contains exactly the eight canonical roles, with no legacy extras. Apply migration 003 to a database already seeded by migration 001 and confirm existing admin/manager rows survive their role mapping.
- [ ] Attempt UPDATE and DELETE on `security_logs`; both are rejected by database triggers.
- [ ] Trigger one pre-authentication IP/email throttle. Confirm it logs `login_throttled`, returns the generic response, and does not increment the user's consecutive failure counter.
- [ ] In a fresh rate-limit window, submit wrong passwords until the five-failure account lock triggers. Confirm only credential failures increment the counter; locked and ordinary failure responses have identical body/status; admin UI shows the lock and unlock works.
- [ ] Confirm lockout invalidates every existing session for that user within the transaction; the next request in an already-open browser is rejected.
- [ ] Log in successfully below the lock threshold and confirm the failure counter resets. Verify admin session invalidation rejects that browser on its next protected request.
- [ ] Create a user, verify the temporary password is displayed once and only a hash is stored, then confirm must-change middleware permits only password change/logout until updated. Check application/security logs contain no submitted or temporary password.
- [ ] Create test users for all eight roles. Verify allowed and denied routes match the role contract; deactivate/reactivate and role changes invalidate sessions as documented.
- [ ] Run `php bin/sessions-sweep.php` against an expired session and confirm it becomes unusable without deleting security history.

**Result / defect notes:**

## 4. M2 — fleet, photos, status and odometer

- [ ] Register a vehicle using the FR-01 fields. Duplicate plate, engine, or chassis values are rejected; multiple blank engine/chassis values are accepted; plate is required.
- [ ] Confirm new vehicle starts `available` with initial status and mileage entries; status filters and vehicle detail/history render.
- [ ] Upload JPEG, PNG, and WebP under 8 MiB. Confirm metadata is present and unauthenticated/direct public access to the file is impossible. Reject HEIC/other MIME and over-limit files.
- [ ] Change status and confirm old/new status, actor, mileage/location snapshot and oldest-first history. Retire and confirm status, status-log row, and `deleted_at` commit together.
- [ ] Record mileage without a status change; decreasing reading is rejected. Correct latest and non-latest roots as `system_admin`; invalid sequence correction is rejected; valid correction appends without rewriting history and `current_mileage` resolves to the effective latest reading.
- [ ] Retire a location: it disappears from new selectors but remains valid in historical records. Removing a referenced location is rejected.
- [ ] Retiring a referenced location is allowed and preserves historical references; physically removing a referenced location is rejected.
- [ ] Confirm non-fleet roles and unauthenticated users cannot access fleet management or photo streaming.

**Result / defect notes:**

## 5. M3 — customers, PII and eligibility

- [ ] Create one customer for each supported type: walk_in, online, corporate, repeat, referral. Confirm corporate company and referral source validations.
- [ ] Add/edit contacts and identity documents. Confirm sensitive values are encrypted at rest and decrypted only for `front_desk`/`system_admin`; auditor and other roles see masked values and cannot call reveal endpoints.
- [ ] Submit the same Philippine driver's-license number under a license field and a government-ID alias. Canonical type must be `ph_driver_license`; the shared fingerprint uniqueness rejects the second registration, including when the original customer is blacklisted or soft-deleted.
- [ ] Correct an identity document. Confirm in-place correction appends to `customer_identity_document_audit_logs`; no application delete path exists. Database UPDATE/DELETE against `customer_notes` and `customer_identity_document_audit_logs` must be rejected.
- [ ] Blacklist a customer with an active agreement; blacklist is allowed and the customer becomes ineligible for future booking. Confirm the customer is absent from every new-booking selector.
- [ ] Confirm blacklisting locks the customer row, requires a reason, and appends that reason as an immutable customer note.
- [ ] Try soft-delete for each open agreement status (`reserved`, `confirmed`, `active`, `returned`); it must be blocked. Terminal agreements do not block deletion.
- [ ] With M5 schema applied, race customer blacklist/delete against rental creation. Confirm both paths lock the customer row and no agreement is created against a customer who became ineligible first.

**Result / defect notes:**

## 6. M4 — drivers and eligibility

- [ ] Create/edit a driver. Duplicate normalized license number is rejected; license expiry, contacts, status, notes, and soft-delete persist.
- [ ] Confirm license, address, contact, and emergency-contact PII are encrypted and revealed only to `system_admin`/`fleet_manager`. `driver_coordinator` can browse allowed master data but cannot edit, change status, or decrypt PII.
- [ ] Verify active, non-deleted drivers with license expiry today or later in Manila are assignment-eligible; inactive, deleted, or expired-license drivers are excluded.
- [ ] Change driver status. Initial and subsequent entries render oldest-first; UPDATE/DELETE on `driver_status_logs` is rejected.
- [ ] Update primary contacts concurrently; at most one primary per driver/contact type remains.
- [ ] Run `php bin/driver-conflict-check.php <driver_id> <start> <end> [exclude_agreement_id]`. With M5 applied, verify shared half-open overlap semantics: intersection conflicts, adjacent periods do not, same-day uses one day, and excluded agreement is ignored.
- [ ] Confirm `DriverRepository::conflictsWith()` delegates to the shared `BookingOverlapService`; there is only one overlap implementation.

**Result / defect notes:**

## 7. M5 — agreements, money, consent and lifecycle

### Schema, availability and concurrency

- [ ] Inspect `SHOW CREATE TABLE rental_agreements`: `start_date`/`end_date` are Manila-calendar DATE values; same-day rental generates one day; backward dates fail the CHECK; generated base amount is `rental_days × daily_rate`.
- [ ] Verify two concurrent overlapping creates for one vehicle yield at most one agreement. Adjacent half-open date ranges are allowed; same-day bookings conflict correctly.
- [ ] Confirm `vehicle_id` and `customer_id` cannot be changed by direct SQL UPDATE after migration 008. Ordinary status, deposit, and actual-time updates still work.
- [ ] Confirm lock order exercised by multi-row flows is vehicle → customer → driver → agreement. Agreement IDs are read as an unlocked snapshot only to discover vehicle/customer IDs before those rows and then the agreement are locked.
- [ ] Confirm a far-future confirmed booking immediately sets fleet status `reserved`, while a non-overlapping date range remains bookable. M10 period availability must use agreement date ranges and/or `vehicle_status_logs`, never just current status.
- [ ] Scenario A: start an earlier active rental, confirm a later non-overlapping booking on the same vehicle, then cancel the later booking. Fleet status must remain `rented`.
- [ ] Scenario B: return the active rental while another future confirmed agreement remains. Fleet status becomes `reserved`. With no active or current/future confirmed agreements, it becomes `available`.
- [ ] `reconcileVehicleStatus()` is the shared implementation used on return and confirmed cancel/no-show. Expiry is verified separately below and does not call it; review the transition call sites in code.
- [ ] Expiry safety scenario: keep an active rental on a vehicle (`current_status='rented'`) and create a separate unconfirmed `reserved` hold for a non-overlapping period. Expire the hold; verify only that agreement becomes cancelled and fleet status remains `rented`.
- [ ] Near UTC/Manila midnight, confirm reconciliation's `end_date >= today` comparison uses Manila's calendar date, not the server's UTC date.
- [ ] Create a scheduled pickup just after Manila midnight while UTC is still the prior date; local input date/time must be accepted, stored in UTC, and displayed correctly. This tests local-time parsing separately from status reconciliation.

### Agreement lifecycle and vehicle mileage

- [ ] Create a reserved hold; confirm it does not change fleet status. Confirm the agreement; vehicle transitions to `reserved`. Pick up; vehicle transitions to `rented`. Return; agreement becomes `returned` and shared status reconciliation chooses the correct vehicle status.
- [ ] Pickup and return require whole-kilometer readings. Each appends `vehicle_mileage_logs` and updates vehicle mileage in the same transaction as agreement/status logs. Try a decreasing reading; the entire lifecycle action must roll back.
- [ ] Cancel/no-show without reason fails. No-show before the configured grace period fails; after the default/configured 60-minute grace, it records a reason and releases a confirmed reservation.
- [ ] Expire an unconfirmed hold with `php bin/rentals-expire.php`; it transitions once to cancelled with system reason and invalidates booking tokens. It intentionally does not call vehicle-status reconciliation because an unconfirmed hold never changed fleet status. Run the script again; no duplicate transition/log/SMS occurs.
- [ ] Run `php bin/rentals-reminders.php` twice while a pickup/return is within 24 hours. One idempotency key per agreement/reminder kind means no duplicate queued notification.
- [ ] Confirm chauffeur rental type is unavailable in the UI and rejected by API until M6.
- [ ] Return condition/damage capture is not part of M5; verify M7 scope has not been implemented as a competing workflow.

### Charges and deposits

- [ ] Add positive fee/discount/tax/damage/other charges; total uses the central sign mapping and integer-cent arithmetic. Discount cannot reduce total below zero.
- [ ] Reverse a charge with a required reason, then add a replacement separately if needed. Confirm reversal alone is valid and totals reflect both rows. Charge UPDATE/DELETE triggers reject changes.
- [ ] Test deposit legal transitions and required reasons. Each status/amount change appends one deposit history row. Completion is rejected while deposit is `due` or `held` and accepted only in a terminal deposit state. Deposit-log UPDATE/DELETE triggers reject changes.
- [ ] Confirm agreement status changes and reason/actor appear in `rental_status_logs`; UPDATE/DELETE is rejected.

### Feature C, SMS and booking links

- [ ] Replay the same STOP event through `php bin/consume-stop-events.php`; `rules_acceptances` has no duplicate provider event/ID. UPDATE/DELETE triggers reject changes.
- [ ] Confirm a STOP suppresses non-transactional SMS. With `NON_TRANSACTIONAL_SMS_ENABLED=false`, attempted non-transactional sends appear as `suppressed_by_policy`. Transactional confirmation/pickup/return/reminder messages remain permitted after STOP.
- [ ] Reserved booking link expires no later than hold expiry; link issued from confirmed onward uses normal TTL. Terminal agreement invalidates linked tokens. Booking context requires a redeemed purpose-bound session and returns only minimum context.

**Result / defect notes:**

## 8. Closeout before M6

- [ ] Record every failed check as a defect with reproduction steps, expected/actual results, and commit reference. Re-run failed checks after fixes.
- [ ] Confirm runtime fixes are committed and pushed; `git status` is clean and `main` matches `origin/main`.
- [ ] Sign/date this checklist and retain it with the local acceptance evidence. Do not begin M6 trace until M1–M5 checks are passed or individually documented as blocked with an owner and resolution plan.

## Related module references

- [Feature E](FEATURE_E.md) · [Magic Links](MAGIC_LINKS.md) · [M1 Auth](FEATURE_M1.md) · [M2 Fleet](FEATURE_M2.md) · [M3 Customers](FEATURE_M3.md) · [M4 Drivers](FEATURE_M4.md) · [M5 Rentals](FEATURE_M5.md)
