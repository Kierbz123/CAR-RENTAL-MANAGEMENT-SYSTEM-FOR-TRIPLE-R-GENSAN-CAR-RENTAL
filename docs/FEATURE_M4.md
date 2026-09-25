# M4 — Drivers

## File trace

- `database/migrations/006_drivers.sql` creates `drivers`, `driver_contacts`, and append-only `driver_status_logs`; license fingerprints are unique and state/history references use restrictive foreign keys.
- `app/Services/DriverPiiCipher.php` encrypts PII and computes the keyed fingerprint for normalized Philippine driver-license numbers.
- `app/Repositories/DriverRepository.php` owns persistence, selection, status history, assignment history, and `conflictsWith()`.
- `app/Services/DriverService.php` validates inputs, performs transactional mutations, encrypts values, enforces contact-primary rules, and builds assignment-eligible selectors.
- `app/Controllers/Fleet/DriverController.php` serves the staff workflow. Routes are wired in `public/index.php`.
- `app/Views/drivers/list.php`, `form.php`, and `detail.php` use `public/assets/js/drivers.js` and `public/assets/css/app.css`.
- `bin/driver-conflict-check.php` calls the same repository conflict method for a date-range inspection without changing data.
- The staff home links drivers for `system_admin`, `fleet_manager`, and `driver_coordinator`.

## Schema and privacy contract

`drivers` stores `driver_id`, searchable plaintext `full_name`, encrypted license number, unique license fingerprint, required `license_expiry`, encrypted optional address and emergency contact name/phone, status (`active`/`inactive`), staff notes, timestamps, and `deleted_at`. `driver_contacts` stores encrypted phone/email channels with a primary flag and soft-delete timestamp. The service locks the driver row while changing contacts and clears an existing primary of the same contact type before setting another; at most one primary per driver and type is enforced in the transaction.

`DRIVER_PII_KEY` is a dedicated base64-encoded 32-byte key, distinct from customer, SMS, and application keys. HKDF derives separate encryption and license-fingerprint keys. AES-256-GCM uses a new random 12-byte nonce per value and stores version, nonce, tag, and ciphertext. Full license/contact/address/emergency values are encrypted; only a keyed HMAC fingerprint of normalized license numbers is used for cross-record uniqueness. License normalization uppercases and removes formatting characters. There is no contact fingerprint. Full name remains plaintext for staff search. Notes are free text and must not contain license or contact details.

`system_admin` and `fleet_manager` may create, edit, change status, soft-delete, and reveal PII. `driver_coordinator` has read-only access to names, status, license expiry, assignment-eligible names, and history; it cannot decrypt license, address, contacts, or emergency details. Reveal uses a CSRF-protected POST and no-store JSON. Driver records do not provide driver login.

New records start active and append an initial status row with `old_status=NULL`. Every later active/inactive change updates the driver and appends `old_status`, `new_status`, actor, and UTC `DATETIME(6)` in the same transaction. `driver_status_logs` has database triggers that reject UPDATE and DELETE.

## Assignment eligibility and overlap handoff

`DriverService::selectableForAssignment()` passes the current Asia/Manila calendar date to a query requiring `status='active'`, `deleted_at IS NULL`, and `license_expiry >= today`. M5/M6 must lock the same driver row with `SELECT ... FOR UPDATE` before repeating these checks and inserting an assignment. This serializes assignment against M4 status changes and soft-delete, which also lock the driver row.

`DriverRepository::conflictsWith(driverId, start, end, excludeAgreementId)` delegates to M5's `BookingOverlapService`, the only implementation of half-open overlap across `reserved`, `confirmed`, and `active` agreements. Same-day ranges occupy one local calendar day; adjacent non-overlapping ranges are allowed. Assignment history returns empty before the agreement table/driver link exists and renders actual rows after migration 007.

The read-only CLI inspection is `php bin/driver-conflict-check.php <driver_id> <start_yyyy-mm-dd> <end_yyyy-mm-dd> [exclude_agreement_id]`. It validates arguments, calls `conflictsWith()`, and prints `CONFLICT` or `NO_CONFLICT` against the shared M5 overlap implementation.

Soft-delete sets `deleted_at` and preserves all history. It is refused when an open agreement references the driver, once the agreement table has a `driver_id` column.

## Local setup and acceptance checklist

Set `DRIVER_PII_KEY` in `.env` before opening driver routes, then run `php bin/migrate.php` to apply migrations 001–006. Keep the key backed up. Rotation requires re-encrypting driver PII and recalculating license fingerprints under the new key before switching keys.

- Create and edit drivers; verify a duplicate license is rejected even when its formatting differs.
- Verify active, non-deleted drivers with license expiry today or later in Asia/Manila appear as assignment eligible; inactive, deleted, and expired drivers do not.
- Verify initial active status and subsequent changes appear oldest-first in history; attempt UPDATE and DELETE against `driver_status_logs` and confirm triggers reject them.
- Verify only system_admin/fleet_manager can mutate/reveal; driver_coordinator sees readable master data but all PII remains restricted.
- Verify contact-primary changes leave no more than one primary per driver/contact type; test concurrent updates to the same driver.
- With migration 007 applied, verify the shared overlap service catches intersections, allows adjacent half-open ranges, handles same-day occupancy, and respects the excluded agreement ID.
- Run `php bin/driver-conflict-check.php <driver_id> <start> <end> [exclude_agreement_id]` to exercise the repository query path without writes.
- Verify soft-delete is retained in history and is blocked for drivers assigned to open agreements once those schema fields exist.

No runtime verification was performed in the build environment. The M1–M4 acceptance checks remain pending for the local acceptance run.
