# Database audit and consolidated schema

Audit date: 2026-09-30. Canonical clean-install DDL: [`../database/schema.sql`](../database/schema.sql).

## Scope and method

Reviewed all ten SQL migrations and the current database-facing PHP across repositories, services, controllers, routes/API, auth, CLI jobs, seed and acceptance scripts, configuration, views and frontend request code. Cross-checked table and column names against migrations, SQL statements, repository calls and runtime acceptance. The consolidated schema was imported into a new isolated MySQL 8.0.46 database; the application migration runner, seed and M4/M6/M7 database checks then ran against that database.

The application contains 32 business/auth tables. The migration runner owns a 33rd table, `schema_migrations`. No table or column was dropped: the code and available acceptance paths show each table supports active behavior, audit/history, reporting, authentication or migration operation. This is a static/application audit plus isolated runtime validation; it cannot establish whether a deployed database contains valuable rows, nor infer production query frequency.

## Object disposition

| Object | Status | Evidence and action |
|---|---|---|
| `users`, `rate_limits`, `sessions`, `security_logs` | ACTIVE / INDIRECTLY USED | Staff authentication, throttling, persisted sessions, account lockout/session invalidation and security events use these. Retained. |
| `vehicle_locations`, `vehicles`, `vehicle_status_logs`, `vehicle_mileage_logs`, `vehicle_photos` | ACTIVE / REPORTING | Fleet CRUD, location selection, vehicle state reconciliation, mileage correction chain, fleet history and private photo serving. Retained, including current mileage as the deliberate vehicle-list projection of the mileage ledger. |
| `customers`, `customer_contacts`, `customer_identity_documents`, `customer_notes`, `customer_identity_document_audit_logs` | ACTIVE / AUDIT | Customer CRUD, contact-primary handling, encrypted identity documents, notes and document change audit. Retained. |
| `drivers`, `driver_contacts`, `driver_status_logs` | ACTIVE / AUDIT | Driver CRUD, eligibility, contact and status history, chauffeur assignment. Retained. |
| `rental_agreements`, `rental_charges`, `rental_status_logs`, `deposit_status_logs` | ACTIVE / AUDIT / REPORTING | Agreement lifecycle and identity, append-only charge/reversal ledger, status and deposit history, availability and financial views. Retained. |
| `sms_daily_budgets`, `notifications`, `inbound_sms_events`, `rules_acceptances` | ACTIVE / INDIRECTLY USED | Outbound policy/budget, delivery and suppression tracking, STOP ingestion/idempotency and consent evidence are used by notification services and workers. Retained. |
| `booking_access_tokens`, `magic_link_booking_limits`, `token_usages` | ACTIVE / AUTH | Booking magic links, issue limits and one-time token-use records. Retained. |
| `damage_reports`, `damage_photos`, `damage_liability_decisions`, `damage_charge_postings` | ACTIVE / AUDIT | M7 capture, private evidence, superseding liability decisions and finance-posting trace. Retained. |
| `schema_migrations` | REQUIRED BY MIGRATION RUNNER | `bin/migrate.php` records and checks migration names/checksums. Retained and initialized by the canonical schema. |
| `notifications.channel` | REVIEW REQUIRED / RETAINED | No current application read/write reference found; the column is part of the existing notification contract and migration history. Kept to avoid breaking older integrations or stored rows. |
| `magic_link_booking_limits.booking_id` relationship | REVIEW REQUIRED / RETAINED AS-IS | Booking issue limits are keyed by the agreement id in `MagicLinkService`, but current migrations intentionally have no FK. No FK was invented in a consolidation. A future migration may add `ON DELETE RESTRICT` after checking live rows and cleanup needs. |
| `ix_damage_decision_report` | DUPLICATE / OMITTED FROM CLEAN SCHEMA | It duplicates the leftmost ordered columns of unique `uq_damage_decision_same_report(report_id, decision_id)`. The unique index supports the same report lookup and supplies the composite referenced key. No table or data is removed. |

There are no objects classified DEAD. There are no table/column removals. “No reference found” is not treated as proof that production data is disposable.

### Column-by-column inventory

For this inventory, `ACTIVE` means application SQL/service code reads or writes the field; `INDIRECT` means it supports a constraint, generated projection, audit/history, operational worker, or report. `REVIEW` is the one field with no current app reference. Column sets below are taken from the imported canonical schema’s `information_schema`; none is proposed for removal.

| Table | Columns and disposition |
|---|---|
| `users` (ACTIVE) | `id`, `email`, `password_hash`, `role`, `is_active`, `created_at`, `updated_at`, `failed_login_count`, `locked_at`, `must_change_password`, `deleted_at` |
| `rate_limits` (ACTIVE) | `limiter_key`, `window_started_at`, `attempts` |
| `sessions` (ACTIVE) | `id`, `user_id`, `session_hash`, `created_at`, `last_seen_at`, `expires_at`, `invalidated_at`, `ip_address`, `user_agent` |
| `security_logs` (INDIRECT) | `id`, `actor_user_id`, `subject_user_id`, `email_hash`, `event_type`, `ip_address`, `user_agent`, `created_at` |
| `vehicle_locations` (ACTIVE) | `location_id`, `name`, `location_status`, `created_at`, `updated_at`, `deleted_at` |
| `vehicles` (ACTIVE / REPORTING) | `vehicle_id`, `plate_number`, `engine_number`, `chassis_number`, `make`, `model`, `model_year`, `color`, `body_type`, `transmission`, `fuel_type`, `seating_capacity`, `daily_rate`, `chauffeur_daily_rate`, `current_status`, `current_mileage`, `current_location_id`, `registration_expiry`, `insurance_expiry`, `insurance_provider`, `notes`, `created_at`, `updated_at`, `deleted_at` |
| `vehicle_status_logs` (INDIRECT / AUDIT) | `status_log_id`, `vehicle_id`, `old_status`, `new_status`, `location_id`, `mileage`, `actor_user_id`, `created_at` |
| `vehicle_mileage_logs` (ACTIVE / AUDIT) | `mileage_log_id`, `vehicle_id`, `mileage`, `recorded_at`, `location_id`, `actor_user_id`, `correction_of_log_id`, `correction_reason` |
| `vehicle_photos` (ACTIVE) | `photo_id`, `vehicle_id`, `storage_path`, `original_filename`, `mime`, `size_bytes`, `sort_order`, `uploaded_by`, `created_at` |
| `customers` (ACTIVE) | `customer_id`, `customer_type`, `full_name`, `company_name`, `referral_source`, `is_blacklisted`, `blacklist_reason`, `blacklisted_at`, `blacklisted_by_user_id`, `created_at`, `updated_at`, `deleted_at` |
| `customer_contacts` (ACTIVE) | `contact_id`, `customer_id`, `contact_type`, `contact_ciphertext`, `contact_fingerprint`, `is_primary`, `created_at`, `updated_at`, `deleted_at` |
| `customer_identity_documents` (ACTIVE) | `document_id`, `customer_id`, `document_type`, `document_ciphertext`, `document_fingerprint`, `expires_on`, `created_at`, `updated_at` |
| `customer_notes` (ACTIVE / AUDIT) | `note_id`, `customer_id`, `note_type`, `note_text`, `created_by_user_id`, `created_at` |
| `customer_identity_document_audit_logs` (INDIRECT / AUDIT) | `audit_id`, `actor_user_id`, `customer_id`, `document_id`, `document_type`, `old_fingerprint`, `new_fingerprint`, `operation`, `created_at` |
| `drivers` (ACTIVE) | `driver_id`, `full_name`, `license_number_ciphertext`, `license_number_fingerprint`, `license_expiry`, `address_ciphertext`, `emergency_contact_name_ciphertext`, `emergency_contact_phone_ciphertext`, `status`, `notes`, `created_at`, `updated_at`, `deleted_at` |
| `driver_contacts` (ACTIVE) | `contact_id`, `driver_id`, `contact_type`, `contact_ciphertext`, `is_primary`, `created_at`, `updated_at`, `deleted_at` |
| `driver_status_logs` (INDIRECT / AUDIT) | `status_log_id`, `driver_id`, `old_status`, `new_status`, `actor_user_id`, `created_at` |
| `rental_agreements` (ACTIVE) | `agreement_id`, `customer_id`, `vehicle_id`, `driver_id`, `rental_type`, `start_date`, `end_date`, `scheduled_pickup_at`, `scheduled_return_at`, `actual_pickup_at`, `actual_return_at`, `daily_rate`, `rental_days`, `base_amount`, `security_deposit_amount`, `deposit_status`, `hold_expires_at`, `status`, `created_by_user_id`, `created_at`, `updated_at` |
| `rental_charges` (ACTIVE / AUDIT) | `charge_id`, `agreement_id`, `charge_type`, `entry_kind`, `amount`, `description`, `reverses_charge_id`, `created_by_user_id`, `created_at` |
| `rental_status_logs` (INDIRECT / AUDIT) | `status_log_id`, `agreement_id`, `old_status`, `new_status`, `reason`, `actor_user_id`, `created_at` |
| `deposit_status_logs` (INDIRECT / AUDIT) | `deposit_log_id`, `agreement_id`, `old_status`, `new_status`, `old_amount`, `new_amount`, `reason`, `actor_user_id`, `created_at` |
| `sms_daily_budgets` (ACTIVE / WORKER) | `recipient_phone`, `budget_date`, `message_count`, `updated_at` |
| `notifications` (ACTIVE / WORKER) | `id`, `recipient_phone`, `idempotency_key`, `channel` (REVIEW), `template_key`, `rendered_message`, `message_class`, `provider`, `status`, `priority`, `provider_message_id`, `provider_status`, `attempt_count`, `retry_count`, `max_attempts`, `next_attempt_at`, `claim_token`, `claimed_at`, `sent_at`, `last_error`, `created_at`, `updated_at` |
| `inbound_sms_events` (ACTIVE / IDEMPOTENCY) | `id`, `provider_message_id`, `provider`, `raw_payload`, `received_at`, `sender_number`, `event_type`, `message_text` |
| `rules_acceptances` (ACTIVE / AUDIT) | `acceptance_id`, `phone`, `action`, `inbound_sms_event_id`, `provider_message_id`, `recorded_at` |
| `booking_access_tokens` (ACTIVE / AUTH) | `id`, `token_hash`, `purpose`, `booking_id`, `expires_at`, `used_at`, `created_at` |
| `magic_link_booking_limits` (ACTIVE / AUTH) | `booking_id`, `issue_count`, `updated_at` |
| `token_usages` (INDIRECT / SECURITY AUDIT) | `id`, `token_id`, `used_at`, `ip_address`, `user_agent`, `action` |
| `damage_reports` (ACTIVE / AUDIT) | `report_id`, `agreement_id`, `phase`, `phase_slot`, `has_damage`, `location`, `damage_type`, `severity`, `repair_cost_suggestion`, `notes`, `recorded_by`, `created_at` |
| `damage_photos` (ACTIVE / EVIDENCE) | `photo_id`, `report_id`, `storage_path`, `original_filename`, `mime`, `size_bytes`, `uploaded_by`, `created_at` |
| `damage_liability_decisions` (ACTIVE / AUDIT) | `decision_id`, `report_id`, `customer_liable`, `liable_amount`, `reason`, `supersedes_decision_id`, `current_root_report_id` (generated uniqueness support), `decided_by`, `created_at` |
| `damage_charge_postings` (ACTIVE / AUDIT) | `posting_id`, `decision_id`, `charge_id`, `approved_amount`, `adjustment_reason`, `posted_by`, `created_at` |
| `schema_migrations` (MIGRATION RUNNER) | `migration`, `checksum`, `applied_at` |

The status is assigned to the field’s role within its containing object, so a field can be read only by a worker/constraint and still be needed. The explicit exceptions are `notifications.channel` (reviewed above) and generated/index-support field `damage_liability_decisions.current_root_report_id`.

## Primary-key audit

All tables have exactly one declared primary key. Entity, event, and history tables use unsigned `BIGINT AUTO_INCREMENT` IDs, matching their referencing columns. Intentional exceptions:

- `rate_limits.limiter_key` is a natural `CHAR(64)` primary key.
- `sms_daily_budgets` uses a composite key for the daily budget identity.
- `schema_migrations.migration` is a natural migration filename key.
- `magic_link_booking_limits.booking_id` is the agreement key for its one-row-per-booking limit record.

No duplicate ID columns or non-unique natural key is used as a surrogate primary key. `vehicle_mileage_logs` and `damage_liability_decisions` also declare composite unique keys to support correction-chain FKs without changing their surrogate PKs.

## Foreign-key and relationship audit

The clean schema declares 48 foreign-key constraints. Referencing and referenced IDs use compatible `BIGINT UNSIGNED` types; nullable actor/location/driver relationships stay nullable where the business allows them. Historical/business evidence uses `RESTRICT` to prevent silently cascading away agreements, customer/vehicle/driver history, ledgers, or audit records. The only deliberately absent relationship is noted above (`magic_link_booking_limits.booking_id`). No circular dependency was introduced.

## Index audit

Every table has its PK index; FK columns have supporting indexes (explicitly declared or provided by a suitable composite index). Unique indexes enforce email, vehicle identifiers, document fingerprints, natural event IDs, token hashes, and other application invariants. Composite indexes follow the current fleet, agreement-overlap, history, expiry, and lookup access paths. The sole removed index is the proven duplicate `ix_damage_decision_report` described above. Static source search cannot prove that an index is unused under production load, so other migration indexes were preserved rather than removed on speculation.

## Types, nullability, defaults, and constraints

- Surrogate/FK identifiers consistently use `BIGINT UNSIGNED`; natural keys remain character data. Phone values are character strings, not numbers.
- Monetary rates, charges, deposits, liability and adjustments use fixed-point `DECIMAL`; no financial amount uses floating point.
- Business dates use `DATE`; lifecycle and audit instants use `DATETIME(6)` where existing migrations require precision. Nullable expiration, optional identity/contact fields, optional actor references and self-drive `driver_id` remain nullable.
- State fields retain the migration-defined ENUM/check domains. Defaults match existing behavior (including status/boolean/counter defaults); no speculative vocabulary or broad type rewrite was introduced.
- Encrypted PII remains binary ciphertext material; HMAC fingerprints and session/token hashes retain fixed-size ASCII/binary collations to avoid case-folding behavior.
- CHECKs and triggers preserve mileage bounds/correction rules, agreement identity/driver immutability, chauffeur driver requirements, append-only ledgers/history, damage evidence/liability corrections and private historical links. No procedures or views are used by the app or migrations.

## Database relationship map

Notation lists each table's PK and outgoing FK edges. Tables without FK edges are explicitly marked.

| Table | PK | FK relationships |
|---|---|---|
| `users` | `id` | — |
| `rate_limits` | `limiter_key` | — |
| `sessions` | `id` | `user_id → users.id` |
| `security_logs` | `id` | `actor_user_id`, `subject_user_id → users.id` |
| `vehicle_locations` | `location_id` | — |
| `vehicles` | `vehicle_id` | `current_location_id → vehicle_locations.location_id` |
| `vehicle_status_logs` | `status_log_id` | `vehicle_id → vehicles.vehicle_id`; `location_id → vehicle_locations.location_id`; `actor_user_id → users.id` |
| `vehicle_mileage_logs` | `mileage_log_id` | `vehicle_id → vehicles.vehicle_id`; `location_id → vehicle_locations.location_id`; `actor_user_id → users.id`; correction pair → prior mileage log for same vehicle |
| `vehicle_photos` | `photo_id` | `vehicle_id → vehicles.vehicle_id`; `uploaded_by → users.id` |
| `customers` | `customer_id` | `blacklisted_by_user_id → users.id` |
| `customer_contacts` | `contact_id` | `customer_id → customers.customer_id` |
| `customer_identity_documents` | `document_id` | `customer_id → customers.customer_id` |
| `customer_notes` | `note_id` | `customer_id → customers.customer_id`; `created_by_user_id → users.id` |
| `customer_identity_document_audit_logs` | `audit_id` | `actor_user_id → users.id`; `customer_id → customers.customer_id`; `document_id → customer_identity_documents.document_id` |
| `drivers` | `driver_id` | — |
| `driver_contacts` | `contact_id` | `driver_id → drivers.driver_id` |
| `driver_status_logs` | `status_log_id` | `driver_id → drivers.driver_id`; `actor_user_id → users.id` |
| `rental_agreements` | `agreement_id` | `customer_id → customers.customer_id`; `vehicle_id → vehicles.vehicle_id`; nullable `driver_id → drivers.driver_id`; `created_by_user_id → users.id` |
| `rental_charges` | `charge_id` | `agreement_id → rental_agreements.agreement_id`; nullable reversal → `rental_charges.charge_id`; `created_by_user_id → users.id` |
| `rental_status_logs` | `status_log_id` | `agreement_id → rental_agreements.agreement_id`; `actor_user_id → users.id` |
| `deposit_status_logs` | `deposit_log_id` | `agreement_id → rental_agreements.agreement_id`; `actor_user_id → users.id` |
| `sms_daily_budgets` | composite | — |
| `notifications` | `id` | — |
| `inbound_sms_events` | `id` | — |
| `rules_acceptances` | `acceptance_id` | `inbound_sms_event_id → inbound_sms_events.id` |
| `booking_access_tokens` | `id` | `booking_id → rental_agreements.agreement_id` |
| `magic_link_booking_limits` | `booking_id` | No declared FK (review item above) |
| `token_usages` | `usage_id` | `token_id → booking_access_tokens.id` |
| `damage_reports` | `report_id` | `agreement_id → rental_agreements.agreement_id`; `recorded_by → users.id` |
| `damage_photos` | `photo_id` | `report_id → damage_reports.report_id`; `uploaded_by → users.id` |
| `damage_liability_decisions` | `decision_id` | `report_id → damage_reports.report_id`; superseded decision composite → prior decision in same report; `decided_by → users.id` |
| `damage_charge_postings` | `posting_id` | `decision_id → damage_liability_decisions.decision_id`; `charge_id → rental_charges.charge_id`; `posted_by → users.id` |
| `schema_migrations` | `migration` | — |

## Migration and compatibility policy

`database/schema.sql` is a clean-install baseline, not a replacement for migration history. The ten migration files stay unchanged because existing installations and `bin/migrate.php` depend on their names and checksums. The schema inserts the current SHA-256 checksum rows so the runner verifies and skips the already-built DDL. Import into a pre-created, empty target database; the file deliberately does not create or switch databases. Existing databases must continue through `bin/migrate.php`, not be overwritten with this clean-install file.

No PHP, API, frontend, seed, or migration source files were changed for this consolidation. No migration was deleted or rewritten. No existing production data was inspected or removed.

## Validation and remaining risks

- MySQL 8.0.46 clean import of `database/schema.sql`: PASS in isolated database `triple_r_schema_20260930182932`.
- `bin/migrate.php` checksum verification for 001–010 against imported schema: PASS (all ten verified and skipped).
- `bin/seed.php`: PASS.
- M4 driver DB acceptance: PASS; M6 chauffeur acceptance: PASS; M7 damage acceptance: PASS (19 checks); migration 009 raw SQL guards: PASS.
- Existing migration path in a separate empty DB: fresh application 001–010, legacy checksum-column baseline, checksum replay, seed, M4/M6/M7 DB checks, 009 raw SQL, M4/M7 HTTP role/reveal/photo checks: PASS.
- Final reference scan parsed all 33 tables and every column from `schema.sql`, then searched 139 project source/document/migration files. Every table has references outside the consolidated schema (with `schema_migrations` correctly runner-owned), and every column name has at least one project reference. This is a lexical completeness check, paired with repository/query inspection; a token occurrence alone is not treated as proof of runtime use.
- MySQL metadata check: 33 tables, each with a primary key, and 48 foreign keys.
- Index/FK/PK/type parity was checked against the current migration definitions. This is not a production EXPLAIN/telemetry review; there is no workload evidence here to claim query-plan optimality.
- REVIEW REQUIRED: inspect existing deployment rows before adding FK to `magic_link_booking_limits.booking_id`; confirm whether any external notification consumer still uses `notifications.channel`.
- Backup/restore, privilege isolation between migration and application credentials, and production data integrity remain operational checks outside this schema consolidation.
