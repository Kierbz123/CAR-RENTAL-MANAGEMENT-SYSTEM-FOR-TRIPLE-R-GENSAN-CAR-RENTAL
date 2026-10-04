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
   GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, TRIGGER, REFERENCES ON triple_r_rental.* TO 'triple_r_migrate'@'127.0.0.1';
   ```

   The migration account needs `DELETE` for migration 022, which removes the retired maintenance records. The runtime account still has none.

   MySQL 8 has binary logging on by default, and then only an account with `SUPER` may create triggers. Allow the migration account to create them by running this once as an administrator (it relaxes a binary-logging safety check for stored programs and grants no privilege):

   ```sql
   SET PERSIST log_bin_trust_function_creators = 1;
   ```

   `bin/migrate.php` checks both of these, and any data a pending migration cannot accept, before it starts. If something is missing it stops with a message and changes nothing.

2. Copy `.env.example` to `.env`, set the runtime database details, set `APP_BASE_URL` to the site's public origin, and change the example seed password. Before sending notifications, set the dedicated `SMS_CIPHER_KEY` to a base64-encoded 32-byte key generated with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`.

   PowerShell: `Copy-Item .env.example .env`  
   Bash: `cp .env.example .env`
3. Apply the schema using the migration account, then remove its credentials from the shell before starting the web app. MySQL 8 DDL can implicitly commit, so migrations are forward-only and recorded in `schema_migrations` with SHA-256 checksums. The runner refuses changed applied files. If a migration fails partway, inspect and repair the schema manually, then add a new forward migration. (A server where migration 022 stopped partway because the migration account had no `DELETE` privilege is missing the damage-photo guards, and running 022 again stops at `Trigger does not exist`. Grant `DELETE`, recreate the two `photos_guard_*` triggers exactly as they appear in `database/migrations/016_photos.sql`, then run `bin/migrate.php` again. Migration 023 recreates the 022 versions of those triggers.) Existing installations without checksums get a one-time baseline from their current migration files. Create the first administrator using the runtime account:

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

5. Sign in at `http://127.0.0.1:8000/staff/login`; `/staff` is the role-aware workspace and the live SMS history is at `/staff/notifications` for `system_admin` and `fleet_manager`.

## Front end: layouts, styles and the public site

The design plan and its status are in [docs/UI_DESIGN_MASTER_PLAN.md](docs/UI_DESIGN_MASTER_PLAN.md).

**Layouts.** A view wraps its markup in `View::begin('<layout>', [...])` and `View::end()`; controllers require views as before. There are three layouts in `app/Views/layouts/`:

| Layout | Used by | Notes |
|---|---|---|
| `staff` | every signed-in page | Sidebar, breadcrumb and sign-out. The menu comes from `app/Support/Navigation.php`, the single role-to-menu map (also served by `/api/staff/navigation`). |
| `entry` | sign-in, password change, secure link, find my booking, the customer's booking page, error pages | Dark, like the public site. `variant => 'split'` adds the brand panel. |
| `public` | the landing page at `/` and the booking page at `/book` | Header, footer and business details. Options: `home` (where the brand links), `intro` (plays the loading screen), `actions` (header buttons), `scripts`. |

Display helpers live in `app/Support/`: `StatusPresenter` (stored value to label and badge), `Format` (money, kilometres, dates in Manila time), `Pager`, and `Icon`.

**Styles.** `public/assets/css/app.source.css` is the source for staff and entry pages; `app.css` is its built output. Rebuild after editing:

```sh
npm install        # once
npm run build:css  # or: npm run watch:css
```

The staff workspace is light; the entry pages are dark like the public site and use the same components with a second set of tokens (the `.entry` block in section 12 of `app.source.css`), so a new component needs no separate dark version as long as it uses the tokens. Printing always uses the light tokens.

`public/assets/css/landing.css` is plain CSS for the public site (the landing page and `/book`) and needs no build. Both files define the same brand colours (ink, amber, rust); change them in both.

**Public business details.** The phone number, address, opening hours, map link, fleet classes and starting rates on the landing page all come from `config/site.php`. The contact details are those of the Triple R Gensan Car Rental listing on Google Maps (read on 2026-10-01). The fleet classes, rates and photos are illustrative and marked `DEMO-PLACEHOLDER`; replace them with the real list, then set `'is_demo' => false` to remove the "demonstration site" notice and allow search engines to index the page. Landing images are in `public/assets/img/landing/`. Each fleet class has a `slug` and a list of `body_types`: its card on the landing page opens `/book?class=<slug>`, which lists only vehicles of those body types. A class with no body types (the limousine) is not booked online; its page gives the phone number instead.

**Scripts and security policy.** Pages send `Content-Security-Policy: script-src 'self'; style-src 'self'`, so views must not contain inline `<script>`, `on...=` handlers or `style=` attributes. Put behaviour in `public/assets/js/` and styles in the stylesheets. The landing page loads `vendor/three.min.js` (about 1.2 MB before compression) only after the page has loaded and only when the browser supports WebGL 2.

**Motion in the workspace and on entry pages.** It is all CSS (section 14 of `app.source.css`), so no page waits on a script to become visible: each staff page arrives once (header, then the blocks below it), entry pages bring in the brand panel and the card, and buttons, stat cards and menu icons respond to the pointer with the same spring curves as the public site. `View::rise($text)` writes a heading whose words rise one after another (the dashboard greeting, the sign-in headline). `app-shell.js` adds two things: dashboard numbers marked `data-count-up` count up once, and a thin line crosses the top while the next page loads. Give a link that downloads a file the `download` attribute so the line is not started for it.

**Motion on the public site.** `landing-boot.js` runs in the `<head>` and marks the page as scripted; `landing.js` does the rest, and `book.js` adds the live summary on `/book`. Motion is declared in the markup: `data-enter="<ms>"` (appears after the loading screen), `data-split="lines"` or `"words"` (a heading that rises line by line or word by word), `class="reveal"` with an optional `data-delay` (fades up when scrolled to) and `data-count="<n>"` (counts up as it scrolls in). The loading screen plays on a fresh visit or a reload of the landing page, not when arriving from another page of the site. Wheel scrolling is eased on desktop. Without scripts, or with reduced motion switched on, every page shows at once with no animation. Because of the security policy nothing is loaded from a CDN: there is no smooth-scroll library or web font, only these files.

**Behind a tunnel or proxy.** By default the connecting address is treated as the visitor. When the app sits behind a tunnel or reverse proxy, list the proxy's address in `TRUSTED_PROXIES`; only then are `X-Forwarded-For` and `X-Forwarded-Proto` used for the visitor's address (sign-in rate limits, security log) and for marking the session cookie Secure. `bin/demo-online.ps1` puts the local copy online for a presentation through a Cloudflare quick tunnel and sets this for its own run; see [docs/DEPLOYMENT_PLAN.md](docs/DEPLOYMENT_PLAN.md).

**Checking roles end to end.** `bin/test-roles-http.php` signs in as each of the five roles on a migrated, seeded, otherwise empty database and (1) drives a full rental, damage and secure-link flow through the real pages, checking each rendered form carries the fields the server reads; (2) checks every page and action against every role; (3) follows every link each role is shown. It needs `ROLES_HTTP_BASE_URL` and `ROLES_HTTP_TEST_PASSWORD`. Results of the last run are in [docs/FEATURE_REVIEW.md](docs/FEATURE_REVIEW.md).

**Error pages.** A controller that returns a short plain-text message with a 4xx or 5xx status (`Response::html('Vehicle not found.', 404)`) gets the shared error page automatically. For 5xx the message is written to the error log and a generic message is shown instead.

## Staff authentication (M1)

There are five staff roles: `system_admin`, `fleet_manager`, `front_desk`, `driver_coordinator` and `finance_staff`. Migration `003_auth_sessions.sql` first created eight; migration `022_remove_maintenance_and_roles.sql` removed `mechanic`, `auditor` and `support_staff` (existing mechanics became fleet managers; auditor and support accounts were deactivated). Existing `system_admin` and `fleet_manager` rows map to the same values. The migration flags all existing users for a password change. The first seeded administrator signs in with the configured seed password and must change it before opening protected pages.

Admin users manage accounts at `/admin/users` and active sessions at `/admin/sessions?user_id=<id>`. A new account receives a cryptographically generated temporary password shown once in the administrator response; deliver it out of band. Only its password hash is stored, and the plaintext credential is excluded from application/security/error logs. Users must change temporary passwords before accessing role-protected pages. Passwords must be at least 14 characters.

Login throttling (20 requests per IP and 5 per normalized email per 60-second window) uses the `throttle` rows of the `rate_counters` table. Separately, five consecutive invalid passwords (`AUTH_MAX_FAILED_LOGINS`) lock a known account for `AUTH_LOCKOUT_MINUTES` (15); the lock then lifts by itself on the next sign-in attempt, so someone who only knows a staff email cannot keep the account shut. An administrator can unlock sooner from the Staff accounts page, which shows when each lock lifts, and `php bin/unlock-user.php <email>` unlocks from the server when no administrator can sign in. Setting `AUTH_LOCKOUT_MINUTES=0` restores the old administrator-only unlock. The atomic counter is stored on `users`; a valid login clears a sub-threshold count. Rate-limited attempts are logged as throttled but do not increment the account counter. Locked and invalid-credential attempts return the same generic failure message, and an unknown email takes as long to refuse as a wrong password, so neither confirms that an account exists. Locking an account invalidates all its existing sessions in the same transaction.

Passwords are stored using PHP `password_hash(..., PASSWORD_DEFAULT)` and verified with `password_verify()`; the application does not add a separate pepper. Hashes remain algorithm-tagged so PHP can rehash on future password changes. Passwords require at least 14 characters.

All HTTP state-changing routes require the session-bound CSRF token, including booking creation, lifecycle/deposit/charge actions, fleet/customer/driver mutations, and PII reveals. Staff session IDs rotate after login. Cookies are `HttpOnly` and `SameSite=Lax`; `Secure` is enabled for HTTPS. Persisted sessions store only a SHA-256 hash of PHP's session ID and have a fixed absolute expiry from `AUTH_SESSION_TTL_SECONDS` (12 hours by default); requests update `last_seen_at` but do not extend that expiry. A session unused for `AUTH_IDLE_TIMEOUT_SECONDS` (30 minutes) also ends. Pages send `Permissions-Policy` (location allowed for this site only, for the tracker phone) and, over HTTPS, `Strict-Transport-Security`. Admin invalidation takes effect on the next request. `security_logs` is append-only and contains event type, separate actor and subject references, hashed email identity, IP, user agent, and UTC timestamp, never passwords, temporary passwords, or bearer tokens. The application does not log request or response bodies; production web-server logging must not capture them either.

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

## Telegram notifications (Feature T)

A customer who connects to the business's Telegram bot receives the same queued messages (secure booking link, confirmation, pickup and return notices, reminders) in Telegram instead of by SMS, at no cost. Design, safeguards and test coverage are in [docs/TELEGRAM_NOTIFICATIONS_PLAN.md](docs/TELEGRAM_NOTIFICATIONS_PLAN.md).

**Turning it on.** Create a bot with `@BotFather` in Telegram (`/newbot`), then set `TELEGRAM_BOT_TOKEN` and `TELEGRAM_BOT_USERNAME` in `.env`. With either one missing, the customer page says Telegram is not set up and every message takes the SMS route as before. The token is a secret: it is read only from `.env`, and no log line, error message or database row contains it.

**Connecting a customer.** A bot cannot message a phone number; the customer has to press Start. On the customer's page, system administrators and front desk staff choose **Show Telegram QR code** (or **Show QR code** in the Telegram column of the customer list), which shows a QR code, the same link as text, and an 8-character one-time code (15 minutes, single use, only its SHA-256 hash is stored). The customer scans the QR code with their own phone and presses Start, or types the code to the bot. The page changes to Connected by itself. Staff can disconnect at any time; the customer can send `/stop`. Connections are append-only rows in `customer_telegram_links` (chat id encrypted with `CUSTOMER_PII_KEY`, looked up by keyed fingerprint), and the database itself allows one active connection per customer and one active customer per chat.

**Sending.** `NotificationService::enqueue()` writes the channel on the row when the message is queued: `telegram` when the customer has an active connection, otherwise `sms`. The per-phone daily limit, idempotency keys, encryption at rest and the non-transactional suppression policy are unchanged and apply to both channels. If Telegram refuses a message because the customer blocked the bot, the connection is ended and the message is handed to the SMS route when an SMS provider is configured, or marked failed with a plain reason when not. Telegram gives no delivery receipts, so "sent" means Telegram accepted the message.

**Two workers.** Messages are sent by `bin/notifications-worker.php` (schedule below; add `--watch=3` to keep it running and send every 3 seconds). Customers pressing Start or sending `/stop` are read by `bin/telegram-updates.php`, which long-polls Telegram and so needs only an outbound internet connection, not a public address; run one copy. `bin/demo-online.ps1` starts both automatically when a bot is configured.

**Checks.** `php bin/test-telegram.php` runs the whole flow against a stand-in for Telegram's API (`bin/support/telegram-stub.php`), so it needs no network and no real bot. Run it against a test database. The QR code is drawn in the browser by `public/assets/js/telegram-connect.js` using the bundled MIT-licensed `vendor/qrcode-generator.js` (Kazuhiko Arase, v2.0.4), as an SVG built with DOM calls because the content security policy forbids inline styles and `data:` images.

## Schema consolidation (migrations 013 to 017)

The schema went from 42 tables to 32 by merging tables that held the same kind of record. Every merge kept its foreign keys, CHECK rules and append-only protection; the review, the reasons and the list of what was deliberately not merged are in [docs/SCHEMA_CONSOLIDATION_PLAN.md](docs/SCHEMA_CONSOLIDATION_PLAN.md).

| Was | Now |
|---|---|
| `token_usages` | `security_logs` rows with a `token_id` (events `magic_link_redeem`, `magic_link_redeem_rejected`) |
| `damage_charge_postings` | `rental_charges.damage_decision_id` and `rental_charges.damage_adjustment_reason` |
| `rate_limits`, `sms_daily_budgets`, `magic_link_booking_limits` | `rate_counters`, scopes `throttle`, `message_daily`, `magic_link_booking` |
| `vehicle_photos`, `damage_photos`, `maintenance_photos` | `photos`, with one owner column per kind (`vehicle_id`, `damage_report_id`, `maintenance_service_id`) and exactly one set |
| `vehicle_status_logs`, `driver_status_logs`, `rental_status_logs`, `deposit_status_logs`, `maintenance_service_status_logs` | `status_logs`, told apart by `subject`, with one owner column per kind |

Older documents in `docs/` (the `FEATURE_*` files and `DATABASE_AUDIT.md`) use the names on the left.

Since then, online booking, payments and live tracking added four tables, and migration `022_remove_maintenance_and_roles.sql` removed the four maintenance tables together with the maintenance owner column in `photos` and `status_logs`. The schema has 32 tables.

Two suites cover the merged tables directly: `php bin/test-rate-counters.php` and `php bin/test-status-logs.php`. Run migrations with a permanent migration account: a trigger belongs to the account that created it and fails (MySQL error 1449) if that account is later removed.

## Magic-link infrastructure

Migration `002_magic_links.sql` creates the booking-independent token store. `booking_access_tokens` stores only the SHA-256 token hash, purpose, TTL expiry, use timestamp, and creation timestamp. Migration 007 adds a restrictive FK to rental agreements. Reserved-agreement links are capped at hold expiry; confirmed and later agreements use ordinary TTL. A `magic_link_booking` row in `rate_counters` atomically enforces up to six links per booking over its lifetime. Tokens are purpose-scoped and single-use. Only `booking_manage` is accepted; a purpose is added when the page it opens exists.

### Redemption API contract

SMS links open `/magic-link#token=<base64url-token>&purpose=<purpose>`. The fragment is read client-side and removed from browser history; fragments are not sent in the initial HTTP request. The redemption endpoint is **POST `/api/magic-links/redeem`** with `Content-Type: application/json` and same-origin `Origin`. Its body is `{"token":"<43-character-base64url-token>","purpose":"booking_manage"}`. **The token is accepted in the POST body only; it must never be sent as a URL path or query parameter.** Wrong-purpose, expired, already-used, and unknown tokens receive the same generic error. A successful redemption consumes the token atomically, records a `magic_link_redeem` event in `security_logs` tied to the token (a refused attempt is recorded as `magic_link_redeem_rejected`), rotates the session ID, and stores a purpose-bound session context through the token's expiry. The response returns `verified` and the purpose only; it does not expose booking PII.

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
See [docs/MASTER_FEATURE_BUILD_PLAN.md](docs/MASTER_FEATURE_BUILD_PLAN.md) for the current reconciled master plan and [docs/FEATURE_M7.md](docs/FEATURE_M7.md) for the M7 damage-reporting contract and acceptance evidence.
See [docs/ops/LOCAL_MYSQL8.md](docs/ops/LOCAL_MYSQL8.md) for the portable Windows MySQL 8 start, verification, backup, and restore runbook.
See [docs/RECONNAISSANCE.md](docs/RECONNAISSANCE.md) for the greenfield Step 0 findings and decisions that still need resolution.

## Rental agreements and costing (M5)

Migration `007_rentals.sql` adds rental agreements, append-only status/deposit histories and charge corrections, plus STOP-event consent imports. Migration `008_rental_agreement_identity_immutable.sql` adds a database trigger preventing reassignment of a rental's vehicle or customer after creation. Scheduled rental dates are Manila-calendar `DATE` values by design: billing uses `GREATEST(DATEDIFF(end_date,start_date),1)`, counting calendar-date boundaries with a one-day floor rather than elapsed 24-hour periods. M5 has no late-return grace-hour conversion; any such rule needs a product decision and billing acceptance case. The Philippines has no daylight-saving changes, and this date-only calculation needs no `CONVERT_TZ()` or MySQL timezone tables. Actual pickup/return times and event timestamps are UTC `DATETIME(6)`. Configure `RESERVATION_HOLD_MINUTES` and `NO_SHOW_GRACE_MINUTES` independently (both default to 60). Reserved booking links expire no later than the hold; links issued from confirmed onward use normal TTL. M6 (Chauffeur Rentals) adds driver assignment, conflict protection via `ChauffeurService`, and Migration `009_chauffeur_guards.sql` for DB-level `driver_id` immutability.

For multi-row operations, use the fixed lock order **vehicle → customer → driver → agreement** to avoid cycles and deadlocks when concurrent workflows touch the same records. New code that needs multiple locks must follow that order; otherwise redesign its transaction boundary. M5 creation locks vehicle then customer; lifecycle operations read the agreement only to discover its immutable vehicle/customer IDs, then lock vehicle, customer, and agreement. Agreement vehicle/customer IDs have no application reassignment path after creation. M6 adds the driver lock before the agreement; agreement-only finance operations lock only the agreement. Overlap queries use `idx_rentals_vehicle_dates_status` and `idx_rentals_driver_dates` to constrain candidate rows before the transaction-level recheck. Fleet-status reconciliation after return, confirmed cancellation, or confirmed no-show is centralized and prioritizes any other active agreement (`rented`), then another current/future confirmed agreement (`reserved`), then `available`. Creating or expiring an unconfirmed hold does not change fleet status. The live fleet list uses immediate reservation semantics: any confirmed future agreement sets `current_status` to `reserved`; overlap checks still permit non-overlapping date ranges. Period-based M10 availability must be derived from rental date ranges and/or the vehicle rows of `status_logs`, not the current-status snapshot alone. See `docs/FEATURE_M5.md` for the full transition map and runtime scenario.

Charge correction is presented as two separate staff actions: reverse the original charge with a reason, then add a replacement charge if needed. A reversal is valid by itself; the UI does not make the two actions atomic or force a replacement.

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
Before beginning M6, run the consolidated [M1–M5 local runtime acceptance checklist](docs/LOCAL_ACCEPTANCE_M1_M5.md) and record pass/fail evidence for every section.


**Double-booking protection.** A vehicle or driver conflicts with a booking when their Manila dates overlap (one booking may start on the day another ends) or, on any day, when the scheduled pickup and return times overlap, with `BOOKING_TURNAROUND_MINUTES` kept free between a return and the next pickup. The conflict check is a locking read (`FOR SHARE`), so two requests booking the same vehicle or driver at the same moment cannot both pass. The database itself allows only one `active` agreement per vehicle and per driver (migration 023). A vehicle that is out on a rental can still be booked and confirmed for later, non-overlapping dates. A staff booking may start up to `RENTAL_MAX_BACKDATE_DAYS` (7) in the past and last up to `RENTAL_MAX_DAYS` (90); a chauffeur's license must be valid through the rental's last day. `php bin/test-booking-integrity.php` checks all of this, including two simultaneous driver assignments.

**Returns.** A return more than `LATE_RETURN_GRACE_MINUTES` (60) after the scheduled time adds a `fee` charge for each started day late, at the agreement's daily rate plus the vehicle's chauffeur rate for a chauffeur rental (`LATE_RETURN_CHARGE=off` turns it off; finance can reverse it like any charge). Damage keeps a vehicle off the road: moderate damage puts it in `maintenance` and severe damage `out_of_service`, either when it is recorded at the return inspection or, for damage recorded during the rental, when the vehicle is returned. Such a vehicle cannot be booked until a fleet manager makes it available again. `php bin/test-return-rules.php` checks both.
## Downpayment (30%)

A reservation needs a 30% downpayment before it can be confirmed. Bookings are made by front desk at the counter or by the customer online; the downpayment rules below are the same for both. The ways to pay it, online and at the counter, are in [Payments](#payments-every-method-and-the-simulated-checkout).

- **Amount.** When a booking is made, 30% of the rental (days billed x the vehicle's daily rate at that moment) is worked out, rounded to the centavo and stored in `rental_agreements.downpayment_amount`. A later change to the vehicle's rate changes neither the booked rate nor the downpayment; a database trigger refuses any edit to the stored amount.
- **Paying at the counter.** Finance staff or a system administrator record the payment on the agreement page (`POST /rentals/downpayment`): the method (cash, GCash, Maya, GrabPay, card or bank transfer) and, for everything except cash, its reference number. It becomes a row in `payments` with a receipt number, who recorded it and when. A reference can be used once, and a recorded payment cannot be edited or undone.
- **Confirming.** `reserved` is the hold; `confirmed` means the downpayment was received and front desk confirmed. Confirming is refused while `downpayment_status` is `due`. The confirmation message reads "Downpayment received: ₱3,000. Balance of ₱7,000 is due at pickup."; the balance is the agreement's total less the downpayment and is paid in person at pickup.
- **Hold.** A new reservation holds its vehicle for `RESERVATION_HOLD_MINUTES` (24 hours). `bin/rentals-expire.php` cancels reservations whose hold has run out, which frees the vehicle. A recorded downpayment stops the clock: a paid reservation is never cancelled by the expiry job, and can be confirmed after the hold time has passed.
- **Customer's page.** The secure booking link shows the downpayment, whether it has been received, the balance at pickup and the non-refundable notice. It never shows the reference number.
- **States.** `downpayment_status` is `not_required` (amount 0: agreements made before this rule, or a zero rate), `due` or `received`, the same wording as `deposit_status`. The database marks a downpayment `received` only when a paid downpayment exists for that agreement in `payments`.

Checks: `php bin/test-downpayment.php`, and the downpayment steps in `bin/test-roles-http.php`. Migrations: `018_downpayment.sql`, `020_payments.sql`.

## Payments: every method, and the simulated checkout

A booking can be paid by GCash, Maya, GrabPay, credit or debit card, online banking or cash. The methods are listed in `config/payments.php`; every payment and every online attempt is a row in the `payments` table (migration `020_payments.sql`). The plan and its "what if" demonstration script are in [docs/PAYMENT_METHODS_MASTER_PLAN.md](docs/PAYMENT_METHODS_MASTER_PLAN.md).

- **Three ways to pay the downpayment.** (1) Online, from the customer's booking page. (2) A GCash transfer with a screenshot proof that finance verifies (next section). (3) At the counter, where finance records it, in cash or by any other method. Whichever way it arrives, front desk still confirms the reservation.
- **The online checkout is simulated. No real money moves.** With `PAYMENT_GATEWAY=simulated`, "Continue to payment" sends the customer to `/pay/demo`, a stand-in for a payment gateway's page. It carries a "Demonstration checkout" banner, uses no wallet or bank logos, and never asks for a PIN, a one-time code or a password. Buttons choose what happens: approve, not enough balance, cancel, or let it time out. The card form accepts only the test numbers in `config/payments.php` (for example `4242 4242 4242 4242` approved, `4000 0000 0000 0002` declined); any other number is refused, and a card number is never stored or logged, only "Visa ending 4242".
- **How a result is trusted.** The checkout's outcome is turned into a message signed with `PAYMENT_WEBHOOK_SECRET` and handed to `PaymentService::handleGatewayResult()`, the way a real gateway calls a webhook (`POST /webhooks/payments` accepts the same message from outside). A message with a wrong signature, for an unknown payment or for a different amount changes nothing and is written to `security_logs`. A result for a payment already settled changes nothing, so a reload or a repeat cannot pay twice. The amount always comes from the booking, never from the browser, and a database trigger refuses a downpayment for any other amount.
- **What a payment does.** A paid result marks the payment `paid` and the downpayment `received` in one transaction, and the customer is sent the receipt number. A failed, cancelled or timed-out attempt is kept with its reason; the downpayment stays `due` and the customer can try again or pay another way. A checkout stays open for `PAYMENT_PENDING_MINUTES` (15) and never past the end of the hold; one can be open per booking at a time, and finance cannot record a counter payment while it is.
- **Consent.** Policy version 2 no longer names GCash as the only method. A customer who booked online accepted the policy then; one whose booking was made at the counter accepts it before paying online, and that acceptance is recorded in `rules_acceptances`.
- **The balance.** What is left after the downpayment is recorded by finance on the agreement page (`POST /rentals/payment`) once the reservation is confirmed: any method, in full or in parts, never more than is owed. An agreement that took a downpayment cannot be completed while money is still owed. Pickup is not blocked.
- **What staff see.** The agreement page lists every payment and attempt with its receipt number. **Payments** (`/payments`) shows the proofs to check, the money received in the last 30 days by method with demonstration payments listed apart, and the latest payments and attempts. `/payments/receipt?receipt=…` is a printable receipt. Finance and administrators see and record them.
- **Each row says how it arrived.** `payments.channel` is `staff` (recorded by finance, at the counter or by verifying a proof, whose id is in `proof_id`) or `online_demo` (the simulated checkout), so a simulated payment can never be counted as real money.
- **Turning it off, or making it real.** Leave `PAYMENT_GATEWAY` empty and the "Pay online" choice disappears; the proof upload and the counter still work. A real gateway would be a second class beside `app/Services/Payments/SimulatedGateway.php` implementing `PaymentGateway`; `PaymentService`, the table and the pages would not change.

Checks: `php bin/test-payments.php` (58 checks: each outcome, forged and repeated results, the balance, the database's own rules) and the "Paying online" section of `bin/test-roles-http.php`, which drives the checkout through the real pages.

## Online booking, proof of payment and recorded consent

A customer can book without an account and pay the downpayment from their own booking page: online (previous section), or by a GCash transfer with a proof, described here. Migration: `019_online_booking.sql`.

- **Booking page.** `/book` is public and uses the public site's layout. A fleet card on the landing page opens it for that class (`/book?class=sedan`); the class can be changed on the page, and "All vehicles" lists every class. The customer chooses dates, sees the vehicles free for the whole period with each one's total, 30% downpayment and balance, enters a name and mobile number, and accepts the downpayment policy. Online bookings are self-drive and for 1 to 30 days, up to 90 days ahead; a rental with a driver is arranged by phone. The reservation itself is made by `RentalService::create()`, the same path staff use, so availability, pricing, the downpayment and the 24-hour hold follow one set of rules. A typed address and a QR code lead to the same page: staff print the QR code from `/staff/booking-qr`.
- **Who the customer is.** A mobile number already on record reuses that customer; otherwise a customer of type `online` is created. A blacklisted number is refused. Records made on a customer's behalf are attributed to the first active system administrator, and the agreement carries `booking_source = 'online'`.
- **Recorded consent.** The policy text lives in `rules_versions`, one row per version, never edited (a change is a new version). Booking online writes an `accepted` row to `rules_acceptances`: which version, for which agreement, from which address and browser. That table also still holds the SMS STOP rows (`revoked`). Bookings made at the counter have no acceptance row, and the agreement page says so.
- **Finding the booking again.** Every agreement has an 8-character `booking_reference`. The customer's page (`/customer/booking`) opens straight after booking, from the secure link, or from `/book/find` with the reference and the mobile number together. A wrong pair gets one generic message, and attempts are limited.
- **Proof of payment.** On their page the customer enters the GCash reference number and uploads a screenshot (JPEG, PNG or WebP, up to 8 MB, stored in private storage, never in the web root). It becomes a `payment_proofs` row; the database allows one waiting proof per booking. While a proof is waiting the reservation is not cancelled by the expiry job.
- **Staff decision.** Finance staff and administrators see proofs under **Payments** (`/payments`) and on the agreement page. **Verify** records the downpayment as a GCash payment tied to that proof, in one transaction, even if the hold time has since passed. **Reject** needs a reason, which is sent to the customer, who can then send another proof. A decision is never changed. Front desk then confirms the reservation as for any other, and the customer receives the confirmation with the amount received and the balance due at pickup.
- **Limits against abuse.** Bookings are limited per connection and per mobile number, one mobile number can hold one unpaid online reservation at a time, and lookups and proof uploads are limited.
- **GCash number.** Set `payments.gcash_number` and `payments.gcash_account_name` in `config/site.php`. While they are empty the customer's page tells them to call the office for the number.

Checks: the "Online booking" section of `bin/test-roles-http.php` drives the whole flow through the real pages as a visitor, finance and front desk.


**Who a booking belongs to, and how many can wait.** An online booking is filed under the customer who already owns its mobile number. So that nobody can file a booking (and its texts) under someone else's number, the visitor first types back a 6-digit code texted to it (`/book/verify`; the code is kept hashed in the session, lasts 10 minutes and allows five tries). `ONLINE_BOOKING_VERIFY_PHONE=auto` asks for the code on the live site and skips it while `config/site.php` has `'is_demo' => true`, because a demonstration may have no SMS provider; set `on` or `off` to force it. Each unpaid online reservation holds a vehicle for the whole hold, so at most `ONLINE_MAX_UNPAID_PER_ADDRESS` (3) may wait from one visitor address and `ONLINE_MAX_UNPAID_HOLDS` (15) across the site; a recorded downpayment frees the slot. `php bin/test-online-booking-guards.php` checks both.
## Live tracking: the tracker phone and the live map

`/fleet/locations` shows a map with every vehicle that is out on rental. The positions are real: they come from the GPS of a phone travelling with the vehicle. Migration: `021_vehicle_tracking.sql`. Settings: `config/tracking.php`.

- **Connecting a phone.** On a confirmed or picked-up agreement, front desk, a fleet manager or an administrator opens **Connect a tracker phone** (`/fleet/tracking/connect`) and makes a tracker code. It is shown once, as a QR code. The phone scans it and lands on `/track`. One phone per rental: making a new code switches the old one off, and staff can disconnect a phone.
- **The tracker (`/track`).** A one-screen web app in the same design as the customer pages, with no sign-in. It shows the vehicle and booking reference, and after the person presses **Start sharing location** and allows it in the browser, sends the phone's position every 5 seconds. It asks the phone to keep its screen on, and can be added to the home screen. Phones only share a location over https, so on a phone it needs the public address from `bin\demo-online.ps1`.
- **How the phone is trusted.** The link ends in `#t=<token>`, which browsers never send to a server; the page keeps it on the phone and sends it in the `X-Tracker-Token` header. Only its hash is stored, as a `booking_access_tokens` row with purpose `vehicle_tracker`. An unknown or switched-off token gets nothing and saves nothing, and the attempt is written to `security_logs`.
- **What is kept.** `vehicle_positions` has one row per vehicle: its latest position, replaced by each newer one. No trail is stored. Positions are accepted only while the rental is `active`; before pickup the phone is told to wait, and after return it is told the rental has ended.
- **The map.** Drawn by this site's own script (`public/assets/js/fleet-map.js`), with map pictures from OpenStreetMap. The Locations page is the only page whose security header allows pictures from that one server; scripts and styles stay "this site only". The page asks `GET /api/fleet/positions` every 5 seconds and glides each marker to its new place. The line behind a vehicle is where it has been since the page was opened.
- **What it tells staff.** Live with its speed; "Stopped for 12 minutes"; "Last seen 4 minutes ago" in grey when the phone has gone quiet for 2 minutes; "Overdue by 2 h 10 min" in red past the return time; "Outside the service area" in amber beyond the circle around the centre. A vehicle with no phone connected is listed but not drawn. A returned vehicle leaves the map at once.
- **Who sees it.** The map and positions: administrators and fleet managers. Connecting a phone: those two and front desk.
- **To set.** `config/tracking.php` has the map centre (approximate until it is set to the rental office), the service radius and the timings.

Checks: `php bin/test-tracking.php` (32 checks) and the "Live tracking" steps in `bin/test-roles-http.php`.

## Damage reporting (M7)

Migration `010_damage.sql` adds agreement-linked, append-only inspection reports, private photo evidence, superseding liability decisions, and audited damage-charge postings. Capture is available to `front_desk` and `fleet_manager`; liability decisions are limited to `fleet_manager` and `system_admin`; only `finance_staff` can post the charge through the M5 append-only charge path. Clean inspections may have no photos; newly reported during/post damage requires photo evidence. See [docs/FEATURE_M7.md](docs/FEATURE_M7.md) for phase/cardinality rules and the MySQL 8 database and HTTP acceptance results.

## Vehicle fleet (M2)

Migration `004_vehicles.sql` adds vehicles, photos, locations, status history, and mileage history. `php bin/migrate.php` applies it after 001–003. Ensure `STORAGE_PATH` is writable by PHP and outside the public document root; uploaded vehicle photos are stored privately and streamed through an authenticated staff route. `system_admin` and `fleet_manager` can use Fleet → Vehicles and Fleet → Locations. Locations use an active/retired state: retired locations are hidden from new selections while remaining valid in history; `deleted_at` is reserved for removal of unused locations. The FR-01 field list is documented in `docs/FEATURE_M2.md`. No weekly/monthly pricing or GPS device identifier is part of M2.

## Customer management (M3)

Migration `005_customers.sql` adds customers, encrypted contacts/identity documents, append-only notes, and append-only `customer_identity_document_audit_logs`. Before first customer entry, set a dedicated `CUSTOMER_PII_KEY` in `.env` using a fresh base64-encoded 32-byte random value (`php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`). Do not use or reuse `APP_KEY` or `SMS_CIPHER_KEY`; back up this key securely because it is required to decrypt existing customer values. Customer routes are available to `system_admin` and `front_desk`. The M3 schema, key contract, migration sequence, and runtime checklist are in `docs/FEATURE_M3.md`.

## Driver records (M4)

Migration `006_drivers.sql` adds encrypted driver records and contacts plus append-only status history. Set `DRIVER_PII_KEY` to a separate base64-encoded 32-byte random value before opening driver pages; generate it with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`. Do not reuse `CUSTOMER_PII_KEY`, `APP_KEY`, or `SMS_CIPHER_KEY`. Back it up securely; key rotation requires re-encrypting driver PII and recomputing license fingerprints before retiring the old key. `system_admin` and `fleet_manager` can manage and reveal driver PII. `driver_coordinator` can browse names, license expiry, and status but cannot decrypt PII or change records. Assignment candidates require active status, no soft deletion, and a license valid through the current Manila date. See `docs/FEATURE_M4.md` for the full contract and local verification checklist.

## Maintenance (M8, removed)

The Maintenance module (schedules, service and cost records, before/after photos, the due report) was removed by migration `022_remove_maintenance_and_roles.sql`. What remains is the vehicle status: a fleet manager or administrator puts a vehicle in **Maintenance** from its page (`/fleet/vehicles/detail`, "Change status to"), it is not offered for booking while in that status, and the change is kept in the vehicle's status history. [`docs/FEATURE_M8.md`](docs/FEATURE_M8.md) describes the module as it was.
