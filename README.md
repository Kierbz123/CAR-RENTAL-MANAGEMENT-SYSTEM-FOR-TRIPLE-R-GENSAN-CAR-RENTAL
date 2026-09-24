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

2. Copy `.env.example` to `.env`, set the runtime database details, set `APP_BASE_URL` to the site's public origin, and change the example seed password. Before issuing magic links, set the dedicated `SMS_CIPHER_KEY` to a base64-encoded 32-byte key generated with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`.

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

   Login credentials are `SEED_ADMIN_EMAIL` and `SEED_ADMIN_PASSWORD` from `.env`. Example values are `admin@example.test` / `ChangeMe-Now-123!`; replace the password before seeding. Seeding will not reset an existing account's password.

4. Start the development server from the project root:

   ```sh
   php -S 127.0.0.1:8000 -t public public/router.php
   ```

5. Sign in at `http://127.0.0.1:8000/staff/login`; the live history is at `/staff/notifications`.

## SMS provider and callbacks

Set `SMS_PROVIDER=semaphore` or `SMS_PROVIDER=philsms` and the matching API credential. Semaphore is the default. The service uses its normal `/api/v4/messages` endpoint for normal priority and `/api/v4/priority` for high priority ([official Semaphore API docs](https://api.semaphore.co/docs)). High priority uses `/priority` because `/otp` inserts an OTP code into the body, while these notifications carry links or payment details. PhilSMS uses `/api/v3/sms/send`; its published API does not document a separate priority route ([official PhilSMS docs](https://app.philsms.com/developers/documentation)). Set only a provider-registered sender name/ID.

Configure provider forwarding to `https://your-domain.example/webhooks/sms/inbound` and delivery callbacks to `/webhooks/sms/delivery`. Each request must carry `X-Webhook-Signature: sha256=<hex HMAC-SHA256 of the exact raw request body using SMS_WEBHOOK_SECRET>`. Normalize callback JSON to the fields shown below. Inbound callback fields: `provider_message_id`, `sender_number`, `message`, and optional `provider`. Delivery callback fields: `provider_message_id`, `status`, and optional `error`. Inbound records are stored before a fast HTTP 200 acknowledgement. Invalid/unparseable callbacks also receive HTTP 200 and are discarded. A provider or forwarding gateway must supply stable message IDs.

Feature E enqueues transactional messages. A STOP event blocks later non-transactional sends to its normalized phone number, while transactional messages remain permitted. Non-transactional sends are unavailable until Feature C adds consent acceptance. Feature C must consume STOP records into `rules_acceptances` using the contract below.

## Magic-link infrastructure

Migration `002_magic_links.sql` creates the booking-independent token store. `booking_access_tokens` stores only the SHA-256 token hash, purpose, TTL expiry, use timestamp, and creation timestamp. Its nullable `booking_id` column is reserved for Feature C; when a feature links a token to a booking it must also attach the hold expiry, which caps the token's existing TTL. No booking FK is installed before the bookings table exists. `magic_link_booking_limits` atomically enforces up to six links per booking over its lifetime. Tokens are purpose-scoped and single-use. Only `booking_manage`, `accept_rules`, and `submit_payment` are accepted.

### Redemption API contract

SMS links open `/magic-link#token=<base64url-token>&purpose=<purpose>`. The fragment is read client-side and removed from browser history; fragments are not sent in the initial HTTP request. The redemption endpoint is **POST `/api/magic-links/redeem`** with `Content-Type: application/json` and same-origin `Origin`. Its body is `{"token":"<43-character-base64url-token>","purpose":"booking_manage"}`. **The token is accepted in the POST body only; it must never be sent as a URL path or query parameter.** Wrong-purpose, expired, already-used, and unknown tokens receive the same generic error. A successful redemption consumes the token atomically, records a `token_usages` row, rotates the session ID, and stores a purpose-bound session context through the token's expiry. The response returns `verified` and the purpose only; it does not expose booking PII.

The issue service rate-limits by normalized phone and, when supplied, normalized email (10 per fixed 24-hour window each by default); booking-linked issues have a concurrency-safe lifetime cap of six tokens per booking (initial send plus five re-sends). Redemption is limited by IP and token hash. `GET /api/magic-links/session?purpose=...` checks the purpose-bound session after redemption; its query contains only the non-secret purpose, never the token. The initial Feature C integration will attach booking IDs and cap expiry at `hold_expires_at`. No customer accounts are introduced.

### SMS body encryption and staff history

Magic-link SMS bodies contain the raw bearer token only in process memory and as AES-256-GCM ciphertext in `notifications.rendered_message` before sending. The encryption key is the dedicated `SMS_CIPHER_KEY` (base64-encoded 32-byte key); it is separate from and must not reuse `APP_KEY` or a session key. The worker decrypts only when sending. Authenticated staff history receives a message preview: normal messages are readable, while `magic_link.*` entries always display `[Magic-link content masked]`; ciphertext is never returned to the browser. If other encrypted templates are added, the staff API decrypts them server-side and masks the preview if decryption is unavailable.

For rotation, pause the worker, set the new `SMS_CIPHER_KEY` and the former key as `SMS_CIPHER_KEY_PREVIOUS`, then resume. Keep the previous key until no `smsenc:v1:` rows encrypted under it remain in `notifications` (including failed rows retained for key recovery). Drain or securely remove those rows before rotating again; only one previous key is supported. Sent magic-link bodies are redacted after provider acceptance and remain masked in the staff UI.

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

## Backlog

- Auth feature: force a password change on first login.

## Configuration and storage

`.env.example` lists every runtime setting. `DB_MIGRATION_USER` and `DB_MIGRATION_PASSWORD` are CLI-only migration settings. Keep `.env`, provider credentials, and webhook secrets out of version control. Runtime logs and private files are kept in `storage/`, outside the public document root.

See [docs/FEATURE_E.md](docs/FEATURE_E.md) for the implementation file trace and UI-to-database round trips.
See [docs/MAGIC_LINKS.md](docs/MAGIC_LINKS.md) for the token schema, API contract, and round trips.
See [docs/RECONNAISSANCE.md](docs/RECONNAISSANCE.md) for the greenfield Step 0 findings and decisions that still need resolution.
