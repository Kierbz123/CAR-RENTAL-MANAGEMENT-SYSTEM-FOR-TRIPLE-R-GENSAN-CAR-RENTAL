# M5 — Rental agreements and costing

## File trace

- `database/migrations/007_rentals.sql` creates `rental_agreements`, `rental_charges`, `rental_status_logs`, `deposit_status_logs`, and Feature C `rules_acceptances`; it adds the FK to the already-existing nullable `booking_access_tokens.booking_id` and adds `suppressed_by_policy` to notification status.
- `app/Services/BookingOverlapService.php` owns the only vehicle/driver date-overlap query. `DriverRepository::conflictsWith()` delegates to it.
- `app/Repositories/RentalRepository.php` owns locked agreement persistence and status/deposit log reads; `ChargeRepository.php` owns append-only charge rows and linked reversals; `RulesAcceptanceRepository.php` consumes STOP events idempotently.
- `app/Services/RentalService.php` owns validation, lock ordering, transition guards, charge arithmetic, deposit settlement, reminders, and link issuance. `VehicleService::transitionStatusInTransaction()` reuses M2 status history inside the rental transaction.
- `app/Controllers/Rentals/AgreementController.php` and `app/Controllers/Api/RentalApiController.php` implement staff workflows and the purpose-bound booking-context API. `public/index.php` wires all routes.
- `app/Views/rentals/{booking-new,reserve,agreements,agreement-detail}.php`, `app/Views/customer/booking.php`, and `public/assets/js/{rentals,rental-booking,magic-links}.js` implement the wired staff form, management views, and magic-link context.
- `bin/consume-stop-events.php`, `bin/rentals-expire.php`, and `bin/rentals-reminders.php` provide scheduled jobs. `RentalRuntimeFactory` is their shared dependency wiring.

## Schema and billing contract

`rental_agreements` references customers, vehicles, and optional drivers with `ON DELETE RESTRICT`. The related master rows are soft-deleted; physical deletion is forbidden while referenced. Chauffeur is a legal schema value for M6, but M5 rejects it at service validation and omits the option from the form until driver assignment and BR-4 concurrency protection ship.

`start_date` and `end_date` are intentionally Manila-calendar `DATE` values, an explicit exception to the normal UTC `DATETIME(6)` convention. Rental billing is by Manila calendar days; `DATE` makes this direct and avoids timezone conversion and dependence on loaded MySQL timezone tables. This rationale is also in the migration comment. Actual pickup/return, hold, log, and other event timestamps are UTC `DATETIME(6)`.

`rental_days` is generated as `GREATEST(DATEDIFF(end_date,start_date),1)`, with `CHECK (end_date >= start_date)`. Same-day rentals bill one day. `base_amount` is a generated column (`rental_days * daily_rate`) and is not duplicated as a charge. Vehicle overlap treats a same-day range as occupying one calendar day and otherwise uses half-open intervals.

Overlap occupancy is `reserved`, `confirmed`, or `active`. This is intentionally distinct from M3's customer soft-delete guard, which also blocks `returned` agreements: a vehicle is available after return, while the customer remains referenced until financial completion.

## Lifecycle, deposits, and charges

Agreement states: `reserved → confirmed → active → returned → completed`; `reserved` and `confirmed` may instead transition to `cancelled` or `no_show`. Terminal states have no outgoing transition. Every transition uses a conditional expected-status update and appends to trigger-protected `rental_status_logs`. Cancel and no-show require a reason. No-show requires a scheduled pickup and is allowed only after `NO_SHOW_GRACE_MINUTES` (default 60).

`RESERVATION_HOLD_MINUTES` (default 60) is separate from no-show grace. Expired reserved holds are marked cancelled by `bin/rentals-expire.php`; this does not alter vehicle status because an unconfirmed reservation has not taken the vehicle's current fleet status. Confirming transitions the vehicle to `reserved`; pickup transitions it to `rented`; return restores it to `reserved` when another confirmed future agreement exists, otherwise `available`.

No separate handover table exists. The pickup action's preconditions are a confirmed agreement and reserved vehicle; that action records the actor and `actual_pickup_at`. M5 does not capture vehicle condition or damage; M7 owns that workflow. Overdue active agreements remain active and are a reporting concern for M10.

Deposits remain separate from billable totals. States are `not_required`, `due`, `held`, `released`, `refunded`, `forfeited`; completion requires a terminal deposit state (`not_required`, `released`, `refunded`, or `forfeited`). Every initial deposit state and subsequent status/amount change appends to `deposit_status_logs`, including reason and actor. Finance roles own deposit changes.

`rental_charges` is append-only. Entries have positive amounts and types `fee`, `discount`, `tax`, `damage`, `chauffeur_fee`, or `other`. Corrections use one linked reversal and a replacement entry; no update/delete path exists. `RentalService` has the single sign mapping (discount subtracts; all other types add) and computes totals in integer cents. Charge totals are never stored redundantly.

## Transaction and locking rules

The canonical lock order is **vehicle → customer → driver → agreement**. M5 locks vehicle then customer for creation and lifecycle operations, then the agreement row. M6 must lock the driver before the agreement and preserve the same preceding order. M7 and later work must follow this order when multiple entities are involved. Vehicle state transitions use M2's transaction-aware service method so the agreement change, fleet status, and status log commit or roll back together.

Future non-overlapping confirmed agreements may share a vehicle whose current status is `reserved`. The overlap service prevents concurrent overlapping periods, and confirmation rechecks the row-locked vehicle. On return/cancellation/no-show, the current status remains `reserved` if another confirmed future rental exists.

## Feature C and SMS policy

`bin/consume-stop-events.php` imports `event_type='stop'` from append-only `inbound_sms_events` to append-only `rules_acceptances`; provider message ID and inbound event ID have unique constraints, so replay is safe. Notification checks consult the imported consent table and the inbound event ledger.

Feature C integration schema: `acceptance_id BIGINT UNSIGNED` primary key; `phone VARCHAR(20)` normalized E.164 sender; `action ENUM('revoked')`; `inbound_sms_event_id BIGINT UNSIGNED` FK to `inbound_sms_events.id`; `provider_message_id VARCHAR(191)`; `recorded_at DATETIME(6)` copied from the inbound event's UTC receipt time. `UNIQUE(phone, provider_message_id)` is the consent-import idempotency key and `UNIQUE(inbound_sms_event_id)` prevents event reuse. The table has `BEFORE UPDATE` and `BEFORE DELETE` triggers that signal an error.

`NON_TRANSACTIONAL_SMS_ENABLED=false` is checked at enqueue. Attempts are recorded as `suppressed_by_policy`, with an explanatory reason and the normal encrypted-body storage behavior. M5 has no affirmative SMS-opt-in capture; even setting the flag true does not permit sends until affirmative consent capture and its check are implemented. Transactional booking confirmation, pickup/return messages, and reminders remain permitted after STOP by design. This distinction is intentional.

Reserved booking-management links are capped at `hold_expires_at`. Links issued from `confirmed` onward receive their ordinary configured TTL. Terminal agreement transitions mark outstanding booking-linked tokens used. `/api/rentals/booking-context` requires a redeemed `booking_manage` session and returns only the minimum booking summary.

## Local operations and acceptance checklist

Run `php bin/migrate.php` to apply migration 007 after 001–006. Add the three every-minute jobs shown in the README: rental hold expiry, pickup/return reminders, and STOP import. Configure `RESERVATION_HOLD_MINUTES`, `NO_SHOW_GRACE_MINUTES`, and `NON_TRANSACTIONAL_SMS_ENABLED` in `.env` as needed.

- Same-day agreement has one generated rental day; backward dates are rejected; a cross-day range uses Manila dates without `CONVERT_TZ`.
- Two overlapping creates for one vehicle yield at most one success; adjacent half-open ranges work; same-day ranges overlap correctly.
- Confirmed vehicle status and agreement log are atomic; pickup and return update actual timestamps and M2 vehicle history; future confirmed booking keeps vehicle reserved.
- Chauffeur input is rejected by API and unavailable in the staff form.
- Cancel/no-show without reason fails; early no-show fails; post-grace no-show records reason and releases the reservation.
- Charge UPDATE/DELETE triggers reject writes; reversal plus replacement is reflected correctly in the computed total; discount cannot make total negative.
- Completion fails while deposit is `due` or `held`; all deposit updates append a log; deposit log UPDATE/DELETE triggers reject writes.
- Replaying STOP import creates no duplicates; imported STOP suppresses non-transactional SMS; with flag false, suppression is visible in notification history; transactional SMS remains queued after STOP.
- Reserved magic-link expiry is capped to the hold; a confirmed booking link uses normal TTL; terminal state invalidates unused booking links; booking context requires a redeemed session.
- Verify `rental_status_logs`, `deposit_status_logs`, `rental_charges`, and `rules_acceptances` triggers reject UPDATE/DELETE.

No PHP/MySQL runtime verification was available during implementation. M1–M4 local acceptance checks remain pending; run those before treating the M5 acceptance run as complete.

## Carry-forward coverage note

BR-12 evidence remains in distinct append-only tables: M1 `security_logs` and M3 `customer_identity_document_audit_logs`; M5 adds `rental_status_logs`, `deposit_status_logs`, and `rules_acceptances`. These are not a generic `audit_logs` table. If the source requirements need generic audit events, M11 must record a separate module decision rather than conflating these logs.

GCash verification and GPS tracking are not implemented by M5, and M6 adds no GPS behavior/schema. M11 must explicitly map those source FR IDs to an existing feature or record them as deferred; they must not be inferred as covered by rental lifecycle work.
