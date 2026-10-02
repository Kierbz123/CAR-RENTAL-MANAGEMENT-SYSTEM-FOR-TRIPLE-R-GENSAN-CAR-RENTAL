# Plan: Reducing the Number of Tables

**Written:** 2026-10-01
**Status:** done on 2026-10-01. Steps B1 to B5 are built, tested and applied: 42 tables became 32. Section 8 records what was done and how it differs from the plan. B6 (contacts) was deliberately not done.
**Why:** the teacher's feedback is that there are too many tables, that some could have been merged into one, and that she suspects dead tables and columns. Section 7 is the audit that answers the second point with evidence.
**What this is:** a review of a proposal to consolidate the schema "from roughly 48 to about 25 tables" by merging tables that share a pattern (logs, photos, contacts, counters), checked against the real schema in [database/schema.sql](../database/schema.sql), followed by a plan.

---

## 1. Short answer

- The proposal's idea, one table per pattern with a type column, is a real technique. Applied to this schema as written, it would remove about 20 of the 73 foreign keys and most of the typed status columns and CHECK rules. Those are the parts of the design that are easiest to defend in front of a panel.
- Four of its nine items are built on a wrong reading of what the tables hold (section 2).
- The schema has 42 tables, not 48. The proposal's target of 25 depends on the merges that do the most damage.
- **Recommendation, now that the teacher has asked for fewer tables:** do the limited consolidation in Option B (42 to 36 tables, every foreign key kept), and optionally the status-log merge B5 (down to 32), which is the repeated pattern most visible to a reader. Act on the audit in section 7. Also regroup the documentation as in Option A. Do not do the proposal as written (Option C).

---

## 2. What the proposal gets wrong about this schema

| Proposal says | What is actually there |
|---|---|
| About 48 tables | 42, including `schema_migrations`. |
| `vehicle_locations` is location telemetry (GPS pings) to merge with mileage logs | It is a short lookup list of named branches and garages (`name`, `location_status`). `vehicles.current_location_id` points to it. There is no GPS data anywhere in the system. |
| `rental_charges` and `damage_charge_postings` are both "money owed" | All money is already in one ledger, `rental_charges` (damage is `charge_type = 'damage'`). `damage_charge_postings` is a link row: which liability decision produced which charge, once only, with the adjustment reason. |
| `maintenance_schedule_logs` is a status log | It records field-by-field edits to a schedule (old and new name, intervals, due dates, 18 old/new columns). It has no status. |
| `security_logs` is an audit log of entity changes | It records sign-in events (failed logins, lockouts, password changes) with IP address, browser and a hashed email. It has no "entity", old value or new value. |
| `token_usages` may be "just a counter" | It is an append-only record of who redeemed a secure link: time, IP address, browser, action. |
| Merging loses no data | The proposed generic tables have no columns for several things the current ones store: deposit amounts, the reason a rental was cancelled, the vehicle's location and mileage at a status change, photo size and type. |

---

## 3. Item-by-item verdict

| # | Proposal | Verdict | Why |
|---|---|---|---|
| 1 | Six status logs into one `status_logs(entity_type, entity_id, ...)` | **No**, with a small exception in Option B | Each log is tied to its parent by a foreign key and uses that parent's own status list (`ENUM`). Three carry extra facts the generic table drops: deposit amounts, vehicle location and mileage, mandatory reasons enforced by CHECK. `entity_type` + `entity_id` cannot have a foreign key, so a log row could point at nothing. One of the six is not a status log at all. |
| 2 | Three audit tables into one `audit_logs` with JSON old/new values | **No** | The identity-document audit is written by database triggers and stores fingerprints only, never the document number; a JSON "old value / new value" design invites storing the number. The maintenance cost audit uses real decimal columns that can be summed. `security_logs` is a different kind of record (section 2). |
| 3 | Three photo tables into one `photos` | **Possible** (Option B) | Same shape: path, original name, type, size, uploader. Differences are small: vehicle photos have an order and may be removed; damage and maintenance photos are append-only; maintenance photos have a before/after phase. Workable only if each parent keeps its own foreign-key column. The proposed columns (`file_path`, `caption`, `sort_order`) drop size and type, so they would have to be the real ones. |
| 4 | Customer and driver contacts into one `contacts` | **No** | They are encrypted with two different keys on purpose (`CUSTOMER_PII_KEY`, `DRIVER_PII_KEY`) and are opened by different roles. Customer contacts carry a lookup fingerprint; driver contacts do not. One table would mix two key domains and lose both foreign keys, to save one table. |
| 5a | Fold `telegram_link_codes` into `customer_telegram_links` as a "pending" row with a `code` column | **No** | A code exists before any chat does, and a connection row requires a chat. Several codes can be issued before one is used. The connection table is an append-only history protected by triggers. Storing the code itself would also undo a security decision: only its hash is stored. |
| 5b | `notifications`, `inbound_sms_events`, `telegram_updates` into one `message_events` | **No** | Three different lifecycles. `notifications` is a working queue that is claimed, retried and updated. `inbound_sms_events` is append-only and referenced by `rules_acceptances`. `telegram_updates` is a de-duplication ledger keyed by Telegram's own number. One table cannot be both append-only and a mutable queue, and the queue's indexes and unique keys would stop making sense. |
| 6 | Three throttling tables into one counter table | **Possible** (Option B) | All three are counters with no foreign keys: `rate_limits` (per key, per time window), `sms_daily_budgets` (per phone, per day), `magic_link_booking_limits` (per booking, lifetime). They can share one table keyed by scope and key. Care is needed: each is updated under its own locking rule. |
| 7 | `rental_charges` and `damage_charge_postings` into one `charges` | **Possible, differently** (Option B) | The ledger is already one table. What can be folded in is the link: add the decision reference and adjustment reason to the damage charge row itself. Both foreign keys stay real. |
| 8 | `vehicle_locations` and `vehicle_mileage_logs` into `vehicle_telemetry_logs` | **No** | Based on a misreading (section 2). They are a lookup list and a corrections-aware history; they have nothing in common. |
| 9 | Fold `token_usages` into `booking_access_tokens` | **Possible** (Option B) | Links are single-use, so there is one usage row per redeemed link. The IP address, browser and action can live on the token row. The append-only protection must be kept with a guard trigger. Folding it into a generic audit table, the proposal's other suggestion, is not recommended. |

### What the full proposal would cost

- **Foreign keys:** about 20 of 73 removed, because `entity_type` + `entity_id` cannot reference a table. The proposal says to "enforce integrity at the application layer", which is the thing the current design avoids.
- **Typed statuses:** every status column becomes free text. Today the database refuses a rental log with a vehicle's status.
- **CHECK rules:** "a cancellation needs a reason", "a deposit amount is not negative" and similar rules would have to be rewritten as long conditionals on the type column, or dropped.
- **Append-only triggers:** 46 triggers exist; a merged table that is append-only for one type and editable for another needs conditional triggers.
- **Code:** every repository and service that reads or writes these tables, all 12 migrations' worth of structure re-expressed in a new migration with a data copy, and every test suite.
- **Questions from the panel:** "How do you guarantee a status log belongs to a real rental?" has a one-line answer today (a foreign key). After the merge the answer is "the application is careful".

---

## 4. Options

### Option A: keep the schema, change how it is presented (recommended before the defense)

Forty-two tables is a consequence of keeping history and money append-only, not a design fault. What needs to shrink is what the reader has to hold in their head.

1. **Group the tables into eight modules** in the database documentation and the ERD: accounts and security (4), fleet (5), customers (5), drivers (3), rentals (4), damage (4), maintenance (6), messaging and links (10), plus `schema_migrations`.
2. **Draw the main ERD with the 12 core tables only**: `users`, `vehicles`, `vehicle_locations`, `customers`, `customer_contacts`, `drivers`, `rental_agreements`, `rental_charges`, `damage_reports`, `damage_liability_decisions`, `maintenance_schedules`, `maintenance_services`. Show history, photo and counter tables as one box per module, with a second diagram per module for anyone who asks.
3. **Name the pattern once**: "every `*_status_logs` table has the same five columns: parent, old status, new status, who, when". That is the same simplification the proposal wants, without changing the database.
4. **Update the database documentation** from 39 to 42 tables (the three Telegram tables).
5. **Prepare the one-line answer** to "why so many tables?": history and money are never edited or deleted, so each kind of history has its own table tied to its parent by a foreign key.

Effort: documentation only. Risk to the running system: none.

### Option B: limited consolidation, 42 to 36 tables, every foreign key kept

Only merges where the tables really are the same thing, and done so nothing points at a row that might not exist.

| Step | Change | Tables | How integrity is kept |
|---|---|---|---|
| B1 | Fold `token_usages` into `booking_access_tokens` (`used_ip_address`, `used_user_agent`, `used_action` beside the existing `used_at`) | −1 | Guard trigger: once `used_at` is set, the usage columns cannot change. |
| B2 | Fold `damage_charge_postings` into `rental_charges` (`damage_decision_id` unique with a foreign key, `adjustment_reason`) | −1 | Foreign key to `damage_liability_decisions`; CHECK that only `charge_type = 'damage'` rows carry a decision; the unique key keeps "one charge per decision". |
| B3 | `rate_limits`, `sms_daily_budgets`, `magic_link_booking_limits` into one `rate_counters(scope, counter_key, window_start, count)` | −2 | None of the three has a foreign key today, so nothing is lost. Each caller keeps its own locking rule. |
| B4 | `vehicle_photos`, `damage_photos`, `maintenance_photos` into one `photos` | −2 | One nullable foreign-key column per parent (`vehicle_id`, `damage_report_id`, `maintenance_service_id`) and a CHECK that exactly one is set. The append-only trigger applies to damage and maintenance rows only. |

Optional B5: merge the five status logs (`vehicle_status_logs`, `driver_status_logs`, `rental_status_logs`, `deposit_status_logs`, `maintenance_service_status_logs`) into one `status_logs` table, for 32 tables. This is the repeated pattern a reader notices first. It is done the same way as B4: one nullable foreign-key column per parent with a CHECK that exactly one is set, a `subject` column saying which kind of status it is, and the extra facts kept as nullable columns (reason, old and new deposit amount, location, mileage). The cost: old and new status become text, with a CHECK listing the allowed values per subject, which is weaker and harder to read than five small typed tables. `maintenance_schedule_logs` stays separate because it is not a status log.

Optional B6: `customer_contacts` and `driver_contacts` into one `contacts` table the same way, for 31. Weakest case: the two are encrypted with different keys and only customer contacts have a lookup fingerprint, so the merged table would have a column that is empty for every driver row.

**How each step is done** (one forward-only migration per step, numbered from 013):

1. Back up the database.
2. Create the new table or columns.
3. Copy the rows with `INSERT ... SELECT` inside the migration.
4. Verify inside the migration: row counts match, and for B2 the sum of amounts matches.
5. Switch the repositories and services to the new structure, and update the tests.
6. Run every suite, including `bin/test-roles-http.php` and `bin/test-telegram.php`.
7. Drop the old tables in a separate, later migration, once the step has been in use without problems.
8. Update `database/schema.sql`, the counts in `.local-acceptance-setup.ps1`, and the database documentation.

Files touched: `MagicLinkRepository` (B1, B3), `DamageReportRepository` and `DamageService` (B2), `RateLimiter` and `NotificationRepository` (B3), `VehiclePhotoService`, `DamageService`, `MaintenanceService` and their repositories (B4), plus the test scripts that read these tables directly.

Order by risk, lowest first: B1, B2, B3, B4. Each step stands alone, so the work can stop after any of them.

Effort: roughly one working session per step including tests. Risk: B3 touches sign-in throttling and the SMS daily limit, and B4 touches every photo upload; both are covered by existing tests but are the two most likely to break something visible.

### Option C: the proposal as written, 42 to about 25 tables (not recommended)

For the reasons in section 3. It is the largest change this codebase has had, it removes guarantees the panel is likely to ask about, and it cannot be finished and re-verified safely before a defense.

---

## 5. Recommendation and timing

The teacher has asked for fewer tables and suspects dead ones, so:

1. **First, the audit actions in section 7.** They are small, and they remove the reason the database looks dead: 24 of the 42 tables are empty in the demo data.
2. **Then Option B, lowest risk first:** B1 and B2 (40 tables), B3 and B4 (36), and B5 if the teacher's point is the repeated log tables (32). Each step is its own migration and is re-tested before the next one starts, so the work can stop safely after any step.
3. **Option A's documentation regrouping** alongside, so the ERD and the data dictionary match whatever the final count is.
4. **Not Option C.**

Whether there is time depends on the defense date. If it is within a few days, do section 7 and B1 to B2 only, and present the rest as the planned next step.

## 6. Decisions needed from the owner

1. When is the defense? It decides how far down the list in section 5 it is safe to go.
2. How far to consolidate: 36 tables (B1 to B4), 32 (plus the status logs, B5) or 31 (plus contacts, B6)?
3. The four histories that are recorded but never shown (section 7): show them on a page, or remove them?
4. The `updated_at` columns that nothing reads (section 7): remove them, or keep them and say why?

---

## 7. Audit: dead tables and columns

Run on 2026-10-01 against the everyday database and the application code (137 files: `app/`, `public/`, and the workers in `bin/`; tests and migrations were not counted as use). Read-only.

### Tables

| Finding | Count | Detail | What to do |
|---|---|---|---|
| Tables no code uses | **0** | Every one of the 42 tables is read or written by application code. | Nothing. |
| Tables that are empty in the demo data | **24 of 42** | All of damage (4) and maintenance (6), vehicle locations and photos, driver contacts and status log, customer notes, notifications and the SMS and secure-link tables. The features exist; the demo data never used them. | This is the most likely reason the database looks dead in phpMyAdmin. Create one realistic demo data set that uses every module, through the application's own screens or a seed script. |
| Histories that are written but never shown anywhere | **4** | `security_logs` (sign-in events), `token_usages` (who opened a secure link), `maintenance_service_status_logs`, `maintenance_schedule_logs`. The application inserts into them and no page or report ever reads them. | The teacher's suspicion is fair for these. Either show them (a History section on the page they belong to; `token_usages` disappears anyway in B1) or remove them. Showing them is the better answer for audit trails. |
| Tables that can only fill up with an SMS provider | 3 | `inbound_sms_events`, `rules_acceptances`, `sms_daily_budgets`. No SMS provider is configured, so customers' STOP replies never arrive. `sms_daily_budgets` is used by Telegram messages too and merges away in B3. | Keep, and say so, or remove the SMS-reply feature if SMS will never be used. |

### Columns (412 in total)

| Finding | Count | Detail | What to do |
|---|---|---|---|
| Columns no code mentions | 35 | 14 are `updated_at` timestamps the database maintains and nothing reads. 16 are the old/new columns of `maintenance_schedule_logs`, written through a loop rather than by name (in use, but never displayed; see above). 3 are generated columns that exist to enforce a unique rule. 2 are primary keys. | The 14 `updated_at` columns are the only real candidates for removal. |
| Columns empty in every row | 32 | Optional details nobody filled in for the test records: vehicle engine and chassis number, registration and insurance dates, driver address and emergency contact, customer referral source, blacklist details, soft-delete dates, mileage corrections. All are used by the code. | Not dead, but they look dead. The demo data set above should fill them in for at least some records. |
| Values allowed by a column but never used | 3 cases | `notifications.status` allows `suppressed`, which nothing writes. `rules_acceptances.action` is a list with one value. Secure-link purposes `accept_rules` and `submit_payment` are accepted, but no screen issues them. | Remove the unused value and the two unused purposes, or keep the purposes if online payment is planned. |

Limit of this audit: a column whose name is shared by several tables (`status`, `reason`, `notes`, `created_at`) counts as mentioned if any code mentions that name, so an unused column with a common name could be missed. Columns with a name unique to one table are checked reliably.

---

## 8. What was done

Five merges, one migration each, applied first to a test database and then, after every suite passed, to the everyday database with a backup taken before each one.

| Step | Migration | Merge | Tables after |
|---|---|---|---|
| B1 | `013_token_usage_into_security_logs.sql` | Secure-link usage into the security event log | 41 |
| B2 | `014_damage_posting_into_rental_charges.sql` | The damage posting link onto the charge row | 40 |
| B3 | `015_rate_counters.sql` | The three counters into `rate_counters` | 38 |
| B4 | `016_photos.sql` | The three photo tables into `photos` | 36 |
| B5 | `017_status_logs.sql` | The five status logs into `status_logs` | 32 |

Foreign keys went from 73 to 64. None was given up. The nine that disappeared were repeats: five separate "changed by" references to users became one column, three "uploaded by" references became one, and a posting no longer points at its charge because it is the charge. Every record still reaches its owner through a real foreign key.

**Where the work differed from the plan**

- **B1 was changed.** The plan said to fold usage into the token row, on the belief that a link has one usage row. Reading the code showed that refused attempts (a replayed or expired link) are recorded too, so a token can have several. Folding would have thrown that evidence away. Usage rows went into `security_logs` instead, which was already an append-only log of security events with an IP address and browser, and gained a `token_id` foreign key.
- **Old tables were dropped in the same migration**, not a later one as section 4 proposed. Each migration first copies the rows, then refuses to continue unless the counts (and for money and sizes, the sums) match, and only then drops. With a backup before each run this gave the same safety with less to keep track of.
- **B5 was done.** It is the repeated pattern a reader sees first. The cost named in section 4 is real: statuses are text checked by a rule (`chk_status_logs_statuses`) instead of a typed list per table. `bin/test-status-logs.php` proves the database still refuses a wrong status, a wrong owner, a missing reason or amount, and any edit.
- **B6 was not done.** Customer and driver contacts are encrypted with different keys and only one of them has a lookup fingerprint. Merging them saves one table and blurs that separation.

**A fault found and fixed along the way.** On the everyday database, migrations had been run through a temporary account that was removed afterwards. A trigger belongs to the account that created it, so 15 triggers were left owned by accounts that no longer existed and failed with error 1449 when fired. One of them guards Telegram connections, so disconnecting a customer would have failed. All 15 were re-created unchanged under the database's permanent migration account, and the trigger definitions were confirmed identical to the test database's. Migrations should be run with a permanent account.

**How it was checked.** After each step: every command-line suite, every HTTP suite including the eight-role test (1,757 checks at the end, no PHP warnings), and a structural comparison of `database/schema.sql` against the migrated database (identical). New checks were added for what changed: `bin/test-rate-counters.php` (14), `bin/test-status-logs.php` (21), and in the role test, that each photo address serves only its own kind of photo and that each page lists only its own kind of history.

**Still open from section 7:** the 14 `updated_at` columns nothing reads (two went away with their tables in B3, 12 remain), the histories that are recorded but not shown on any page, and the unused allowed values. The database documentation `.docx` needs its table list redone for 32 tables.
