# Triple R Gensan Car Rental Management System

Greenfield PHP 8.2+ and MySQL 8.0/InnoDB application. Feature E provides a shared SMS queue, Semaphore and PhilSMS adapters, authenticated staff delivery history, and append-only inbound STOP event capture.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `curl`, `mbstring`, `json`, and `openssl` extensions.
- MySQL 8.0+ with InnoDB.
- HTTPS and a provider account for real production SMS. Nothing is sent until a provider credential is configured.

This project has no Composer dependencies. It uses PDO and PHP's built-in extensions.

## First run

1. Create a MySQL database, a restricted runtime account, and a separate migration account. The runtime account has no `DELETE`, `CREATE`, `ALTER`, or `TRIGGER` privilege, so application credentials cannot remove or disable the append-only guards:

   ```sql
   CREATE DATABASE triple_r_rental CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
   CREATE USER 'triple_r_app'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-long-password';
   GRANT SELECT, INSERT, UPDATE ON triple_r_rental.* TO 'triple_r_app'@'127.0.0.1';
   CREATE USER 'triple_r_migrate'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-different-long-password';
   GRANT SELECT, INSERT, UPDATE, CREATE, ALTER, INDEX, TRIGGER ON triple_r_rental.* TO 'triple_r_migrate'@'127.0.0.1';
   ```

2. Copy `.env.example` to `.env`, set the runtime database details, set `APP_BASE_URL` to the site's public origin, and change the example seed password. Before sending notifications, set the dedicated `SMS_CIPHER_KEY` to a base64-encoded 32-byte key generated with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`.

   PowerShell: `Copy-Item .env.example .env`  
   Bash: `cp .env.example .env`
3. Apply the schema using the migration account, then remove its credentials from the shell before starting the web app. Create the first administrator using the runtime account:

   ```powershell
   $env:DB_MIGRATION_USER = 'triple_r_migrate'
   $env:DB_MIGRATION_PASSWORD = 'replace-with-a-different-long-password'
   php bin/migrate.php
   Remove-Item Env:DB_MIGRATION_USER
   Remove-Item Env:DB_MIGRATION_PASSWORD
   php bin/seed.php
   ```

   On Bash, use `DB_MIGRATION_USER=triple_r_migrate DB_MIGRATION_PASSWORD='replace-with-a-different-long-password' php bin/migrate.php`, then run `php bin/seed.php`. Migration credentials are read only from the CLI environment and should not be stored in `.env`.

   Login credentials are `SEED_ADMIN_EMAIL` and `SEED_ADMIN_PASSWORD` from `.env`. Example values are `admin@example.test` / `ChangeMe-Now-123!`; replace the password before seeding. Migration 003 flags existing accounts for a password change at first login. Seeding will not reset an existing account's password.

4. Start the development server from the project root:

   ```sh
   php -S 127.0.0.1:8000 -t public public/router.php
   ```

5. Sign in at `http://127.0.0.1:8000/staff/login`; `/staff` is the role-aware workspace and the live SMS history is at `/staff/notifications` for `system_admin`, `fleet_manager`, and `support_staff`.

## Staff authentication (M1)

Migration `003_auth_sessions.sql` rebuilds `users.role` so the final ENUM contains exactly `system_admin`, `fleet_manager`, `front_desk`, `driver_coordinator`, `mechanic`, `finance_staff`, `auditor`, and `support_staff`. Existing `system_admin` and `fleet_manager` rows map to the same values. The migration flags all existing users for a password change. The first seeded administrator signs in with the configured seed password and must change it before opening protected pages.

Admin users manage accounts at `/admin/users` and active sessions at `/admin/sessions?user_id=<id>`. A new account receives a cryptographically generated temporary password shown once in the administrator response; deliver it out of band. Only its password hash is stored, and the plaintext credential is excluded from application/security/error logs. Users must change temporary passwords before accessing role-protected pages. Passwords must be at least 14 characters.

Login throttling (20 requests per IP and 5 per normalized email per 60-second window) uses the existing `rate_limits` table. Separately, five consecutive invalid passwords lock a known account. The atomic counter is stored on `users`; a valid login clears a sub-threshold count, while a locked account can only be reset by an administrator. Rate-limited attempts are logged as throttled but do not increment the account counter. Locked and invalid-credential attempts return the same generic failure message. Locking an account invalidates all its existing sessions in the same transaction.

Persisted staff sessions store only a SHA-256 hash of PHP's session ID and expire after `AUTH_SESSION_TTL_SECONDS` (12 hours by default). Session and account changes are checked on protected requests. Admin invalidation takes effect on the next request. `security_logs` is append-only and contains event type, separate actor and subject references, hashed email identity, IP, user agent, and UTC timestamp, never submitted or temporary passwords. The application does not log request or response bodies; production web-server logging must not capture them either.

Run session cleanup every five minutes. Linux/macOS crontab:

```cron
*/5 * * * * cd /path/to/TripleR-Gensan-Car-Rental && /usr/bin/php bin/sessions-sweep.php >> storage/sessions-sweep.log 2>&1
```

Windows Task Scheduler (replace paths):

```powershell
schtasks.exe /Create /F /SC MINUTE /MO 5 /TN "TripleR-Sessions-Sweep" /TR '"C:\php\php.exe" "C:\path\TripleR-Gensan-Car-Rental\bin\sessions-sweep.php"'
```

## SMS provider and callbacks

Set `SMS_PROVIDER=semaphore` or `SMS_PROVIDER=philsms` and the matching API credential. Semaphore is the default. The service uses its normal `/api/v4/messages` endpoint for normal priority and `/api/v4/priority` for high priority ([official Semaphore API docs](https://api.semaphore.co/docs)). High priority uses `/priority` because `/otp` inserts an OTP code into the body, while these notifications carry links or payment details. PhilSMS uses `/api/v3/sms/send`; its published API does not document a separate priority route ([official PhilSMS docs](https://app.philsms.com/developers/documentation)). Set only a provider-registered sender name/ID.

Configure provider forwarding to `https://your-domain.example/webhooks/sms/inbound` and delivery callbacks to `/webhooks/sms/delivery`. Each request must carry `X-Webhook-Signature: sha256=<hex HMAC-SHA256 of the exact raw request body using SMS_WEBHOOK_SECRET>`. Normalize callback JSON to the fields shown below. Inbound callback fields: `provider_message_id`, `sender_number`, `message`, and optional `provider`. Delivery callback fields: `provider_message_id`, `status`, and optional `error`. Inbound records are stored before a fast HTTP 200 acknowledgement. Invalid/unparseable callbacks also receive HTTP 200 and are discarded. A provider or forwarding gateway must supply stable message IDs.

Feature E enqueues transactional messages. M5 imports STOP events into `rules_acceptances`; non-transactional SMS attempts are stored as `suppressed_by_policy` while affirmative opt-in capture is not implemented. Transactional messages remain permitted after STOP by policy.

## Magic-link infrastructure

Migration `002_magic_links.sql` creates the booking-independent token store. `booking_access_tokens` stores only the SHA-256 token hash, purpose, TTL expiry, use timestamp, and creation timestamp. Migration 007 adds a restrictive FK to rental agreements. Reserved-agreement links are capped at hold expiry; confirmed and later agreements use ordinary TTL. `magic_link_booking_limits` atomically enforces up to six links per booking over its lifetime. Tokens are purpose-scoped and single-use. Only `booking_manage`, `accept_rules`, and `submit_payment` are accepted.

### Redemption API contract

SMS links open `/magic-link#token=<base64url-token>&purpose=<purpose>`. The fragment is read client-side and removed from browser history; fragments are not sent in the initial HTTP request. The redemption endpoint is **POST `/api/magic-links/redeem`** with `Content-Type: application/json` and same-origin `Origin`. Its body is `{"token":"<43-character-base64url-token>","purpose":"booking_manage"}`. **The token is accepted in the POST body only; it must never be sent as a URL path or query parameter.** Wrong-purpose, expired, already-used, and unknown tokens receive the same generic error. A successful redemption consumes the token atomically, records a `token_usages` row, rotates the session ID, and stores a purpose-bound session context through the token's expiry. The response returns `verified` and the purpose only; it does not expose booking PII.

The issue service rate-limits by normalized phone and, when supplied, normalized email (10 per fixed 24-hour window each by default); booking-linked issues have a concurrency-safe lifetime cap of six tokens per booking (initial send plus five re-sends). Redemption is limited by IP and token hash. `GET /api/magic-links/session?purpose=...` checks the purpose-bound session after redemption; its query contains only the non-secret purpose, never the token. M5 links tokens to rental agreements and presents a minimal booking context only after `booking_manage` redemption. No customer accounts are introduced.

### SMS body encryption and staff history

All newly queued SMS bodies use AES-256-GCM ciphertext in `notifications.rendered_message`; a magic-link bearer token exists raw only in process memory. The encryption key is the dedicated `SMS_CIPHER_KEY` (base64-encoded 32-byte key); it is separate from and must not reuse `APP_KEY` or a session key. The worker decrypts only when sending. Authenticated staff history receives a server-rendered preview: ordinary messages are decrypted for authorized staff, while `magic_link.*` entries always display `[Magic-link content masked]`; ciphertext is never returned to the browser. Sent/failed non-magic bodies remain encrypted for history and key rotation; magic-link bodies are redacted after terminal send/failure.

For rotation, pause the worker, set the new `SMS_CIPHER_KEY` and the former key as `SMS_CIPHER_KEY_PREVIOUS`, then resume. Keep the previous key until no `smsenc:v1:` rows encrypted under it remain in `notifications` (including failed and policy-suppressed rows retained for history/key recovery). Drain or securely remove retained rows before rotating again; only one previous key is supported. Magic-link bodies are redacted after terminal delivery/failure and remain masked in staff history.

The token and append-only usage schema is defined in `database/migrations/002_magic_links.sql`; Feature E's notification schema remains in `001_notifications.sql`.

## Worker schedule and duplicate protection

Run the queue worker every minute. Linux/macOS crontab (replace project and PHP paths):

```cron
* * * * * cd /path/to/TripleR-Gensan-Car-Rental && /usr/bin/php bin/notifications-worker.php >> storage/notifications-worker.log 2>&1
```

Windows Task Scheduler, run in PowerShell as the account that owns the project (replace paths):

```powershell
schtasks.exe /Create /F /SC MINUTE /MO 1 /TN "TripleR-Notifications-Worker" /TR '"C:\php\php.exe" "C:\path\TripleR-Gensan-Car-Rental\bin\notifications-worker.php"'
```

Workers claim due messages in a transaction with `SELECT ... FOR UPDATE SKIP LOCKED`, then mark them `sending` with a unique claim token before calling the provider. Concurrent workers cannot claim a `sending` row. A crash after claim leaves the row visible as `sending` for manual reconciliation rather than risking an automatic duplicate. Known retryable failures use exponential backoff. Every priority shares the per-phone daily budget.

## Inbound event schema contract for Feature C

`inbound_sms_events` is append-only: the application exposes no update/delete operation, and database triggers reject both. Feature C should consume `event_type = 'stop'` by `sender_number` and `received_at`, using `provider_message_id` as its idempotency key.

| Column | MySQL type | Contract |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Internal primary key |
| `provider_message_id` | `VARCHAR(191)` | Required stable provider ID; unique; duplicate callbacks are ignored |
| `provider` | `VARCHAR(40)` | `semaphore`, `philsms`, or configured gateway |
| `raw_payload` | `MEDIUMTEXT` | Exact UTF-8 webhook request body; never rewritten |
| `received_at` | `DATETIME(6)` | UTC receipt time |
| `sender_number` | `VARCHAR(20)` | Normalized E.164 number, e.g. `+639171234567` |
| `event_type` | `VARCHAR(40)` | Normalized event type; STOP is exactly `stop` |
| `message_text` | `TEXT NULL` | Parsed inbound text, when supplied |

The authoritative SQL definition and append-only triggers are in `database/migrations/001_notifications.sql`.

## Web server routing

The built-in PHP server uses `public/router.php`. Apache deployments can point the document root at `public/`; the included `public/.htaccess` sends non-file requests to `index.php`. Enable `mod_rewrite` and `AllowOverride All`, and require HTTPS in the production virtual host.

## Configuration and storage

`.env.example` lists every runtime setting. `DB_MIGRATION_USER` and `DB_MIGRATION_PASSWORD` are CLI-only migration settings. Keep `.env`, provider credentials, and webhook secrets out of version control. Runtime logs and private files are kept in `storage/`, outside the public document root.

See [docs/FEATURE_E.md](docs/FEATURE_E.md) for the implementation file trace and UI-to-database round trips.
See [docs/MAGIC_LINKS.md](docs/MAGIC_LINKS.md) for the token schema, API contract, and round trips.
See [docs/FEATURE_M1.md](docs/FEATURE_M1.md) for role, account-lock, session, and password-change details.
See [docs/FEATURE_M2.md](docs/FEATURE_M2.md) for the vehicle fleet schema, authenticated photo storage, location lifecycle, mileage correction contract, and fleet acceptance checklist.
See [docs/FEATURE_M3.md](docs/FEATURE_M3.md) for encrypted customer PII, document fingerprints/audit, customer eligibility, and the customer management checklist.
See [docs/FEATURE_M4.md](docs/FEATURE_M4.md) for driver records, role access, encrypted driver PII, status history, and the M5 overlap handoff.
See [docs/FEATURE_M5.md](docs/FEATURE_M5.md) for rental costing, lifecycle, consent integration, and acceptance checks.
See [docs/RECONNAISSANCE.md](docs/RECONNAISSANCE.md) for the greenfield Step 0 findings and decisions that still need resolution.

## Rental agreements and costing (M5)

Migration `007_rentals.sql` adds rental agreements, append-only status/deposit histories and charge corrections, plus STOP-event consent imports. Scheduled rental dates are Manila-calendar `DATE` values by design: billing uses local calendar days, same-day rentals bill as one day, and no UTC conversion or MySQL timezone table is needed. Actual pickup/return times and event timestamps are UTC `DATETIME(6)`. Configure `RESERVATION_HOLD_MINUTES` and `NO_SHOW_GRACE_MINUTES` independently (both default to 60). Reserved booking links expire no later than the hold; links issued from confirmed onward use normal TTL. Chauffeur rentals are hidden and rejected until M6 adds assignment and conflict protection.

Non-transactional SMS is policy-disabled by default. `NON_TRANSACTIONAL_SMS_ENABLED=false` causes attempted messages to be recorded as `suppressed_by_policy`; affirmative opt-in capture is not part of M5, so changing the flag alone still does not enable sends. Transactional booking confirmation, pickup/return notices, and reminders remain permitted after STOP by design. Import STOP callbacks idempotently with `php bin/consume-stop-events.php`.

Run reservation expiry and 24-hour reminder jobs every minute, alongside STOP import. Linux/macOS crontab (replace paths):

```cron
* * * * * cd /path/to/TripleR-Gensan-Car-Rental && /usr/bin/php bin/rentals-expire.php >> storage/rentals-expire.log 2>&1
* * * * * cd /path/to/TripleR-Gensan-Car-Rental && /usr/bin/php bin/rentals-reminders.php >> storage/rentals-reminders.log 2>&1
* * * * * cd /path/to/TripleR-Gensan-Car-Rental && /usr/bin/php bin/consume-stop-events.php >> storage/stop-events.log 2>&1
```

Windows Task Scheduler (run each as the project service account; replace paths):

```powershell
schtasks.exe /Create /F /SC MINUTE /MO 1 /TN "TripleR-Rentals-Expiry" /TR '"C:\php\php.exe" "C:\path\TripleR-Gensan-Car-Rental\bin\rentals-expire.php"'
schtasks.exe /Create /F /SC MINUTE /MO 1 /TN "TripleR-Rental-Reminders" /TR '"C:\php\php.exe" "C:\path\TripleR-Gensan-Car-Rental\bin\rentals-reminders.php"'
schtasks.exe /Create /F /SC MINUTE /MO 1 /TN "TripleR-Consume-STOP" /TR '"C:\php\php.exe" "C:\path\TripleR-Gensan-Car-Rental\bin\consume-stop-events.php"'
```

See [docs/FEATURE_M5.md](docs/FEATURE_M5.md) for the exact state graph, lock order, SMS policy, and local acceptance checklist.

## Vehicle fleet (M2)

Migration `004_vehicles.sql` adds vehicles, photos, locations, status history, and mileage history. `php bin/migrate.php` applies it after 001–003. Ensure `STORAGE_PATH` is writable by PHP and outside the public document root; uploaded vehicle photos are stored privately and streamed through an authenticated staff route. `system_admin` and `fleet_manager` can use Fleet → Vehicles and Fleet → Locations. Locations use an active/retired state: retired locations are hidden from new selections while remaining valid in history; `deleted_at` is reserved for removal of unused locations. The FR-01 field list is documented in `docs/FEATURE_M2.md`. No weekly/monthly pricing or GPS device identifier is part of M2.

## Customer management (M3)

Migration `005_customers.sql` adds customers, encrypted contacts/identity documents, append-only notes, and append-only `customer_identity_document_audit_logs`. Before first customer entry, set a dedicated `CUSTOMER_PII_KEY` in `.env` using a fresh base64-encoded 32-byte random value (`php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`). Do not use or reuse `APP_KEY` or `SMS_CIPHER_KEY`; back up this key securely because it is required to decrypt existing customer values. Customer routes are available to `system_admin` and `front_desk`. The M3 schema, key contract, migration sequence, and runtime checklist are in `docs/FEATURE_M3.md`.

## Driver records (M4)

Migration `006_drivers.sql` adds encrypted driver records and contacts plus append-only status history. Set `DRIVER_PII_KEY` to a separate base64-encoded 32-byte random value before opening driver pages; generate it with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`. Do not reuse `CUSTOMER_PII_KEY`, `APP_KEY`, or `SMS_CIPHER_KEY`. Back it up securely; key rotation requires re-encrypting driver PII and recomputing license fingerprints before retiring the old key. `system_admin` and `fleet_manager` can manage and reveal driver PII. `driver_coordinator` can browse names, license expiry, and status but cannot decrypt PII or change records. Assignment candidates require active status, no soft deletion, and a license valid through the current Manila date. See `docs/FEATURE_M4.md` for the full contract and local verification checklist.
