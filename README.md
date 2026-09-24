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

2. Copy `.env.example` to `.env`, set the runtime database details, and change the example seed password.

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
See [docs/RECONNAISSANCE.md](docs/RECONNAISSANCE.md) for the greenfield Step 0 findings and decisions that still need resolution.
