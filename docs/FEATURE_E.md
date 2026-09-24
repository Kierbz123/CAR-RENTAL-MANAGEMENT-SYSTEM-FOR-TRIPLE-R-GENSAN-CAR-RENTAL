# Feature E — SMS notification service

This module is the shared SMS service used by later booking, payment, and magic-link features. The staff view reads the real notification table. It contains no test adapter or hardcoded response data.

## File trace

| File | Layer | Change | Reason |
|---|---|---|---|
| `.gitignore` | Setup | New | Keep environment secrets and runtime files out of Git. |
| `.env.example` | Setup | New | Document runtime database, seed, provider, budget, and webhook settings. |
| `README.md` | Setup | Modified | Provide local setup, database account scopes, seed login, provider configuration, worker schedules, and the STOP schema contract. |
| `docs/FEATURE_E.md` | Documentation | New | Keep this trace and module round trip with the implementation. |
| `database/migrations/001_notifications.sql` | DB | New | Create staff users, login limits, daily SMS budgets, notifications, inbound events, and append-only triggers. |
| `app/Config.php` | Backend | New | Load `.env` and expose validated configuration access. |
| `app/Database.php` | Backend | New | Create PDO runtime and CLI migration connections with separate credentials. |
| `app/bootstrap.php` | Backend | New | Load config, namespace autoloading, and timezone setup. |
| `app/Http/Request.php` | Backend | New | Normalize HTTP request method, path, headers, form, JSON, and raw body. |
| `app/Http/Response.php` | Backend | New | Produce safe HTML, JSON, and redirect responses with security headers. |
| `app/Http/Router.php` | Backend | New | Dispatch the defined HTTP routes. |
| `app/Controllers/AuthController.php` | Backend | New | Render staff login, validate CSRF, rate-limit attempts, and start/end sessions. |
| `app/Controllers/SmsWebhookController.php` | Backend | New | Verify webhook HMAC, append inbound events, and apply delivery updates. |
| `app/Controllers/StaffNotificationController.php` | Backend | New | Serve authenticated staff history page and its live JSON endpoint. |
| `app/Repositories/StaffUserRepository.php` | Backend | New | Read and seed staff accounts. |
| `app/Repositories/NotificationRepository.php` | Backend | New | Persist queue entries, budgets, worker claims, delivery states, and history. |
| `app/Repositories/InboundSmsEventRepository.php` | Backend | New | Append inbound events and query STOP records. |
| `app/Security/Csrf.php` | Backend | New | Manage session cookies and CSRF tokens for staff forms. |
| `app/Security/StaffAuth.php` | Backend | New | Resolve authenticated staff sessions and rotate session IDs. |
| `app/Services/RateLimiter.php` | Backend | New | Enforce database-backed staff login limits. |
| `app/Services/NotificationService.php` | Backend | New | Enforce consent gates, idempotency, per-phone budgets, queueing, retries, and priorities. |
| `app/Services/Sms/SmsProviderInterface.php` | Backend | New | Define the swappable provider contract. |
| `app/Services/Sms/SmsProviderException.php` | Backend | New | Carry retryable vs. uncertain/terminal provider errors. |
| `app/Services/Sms/SmsHttpClient.php` | Backend | New | Send bounded, TLS-verified provider HTTP requests. |
| `app/Services/Sms/SemaphoreSmsProvider.php` | Backend | New | Send Semaphore form requests through regular or priority route. |
| `app/Services/Sms/PhilSmsProvider.php` | Backend | New | Send PhilSMS authenticated JSON requests. |
| `app/Services/Sms/SmsProviderFactory.php` | Backend | New | Select the configured adapter. |
| `app/Support/PhoneNumber.php` | Backend | New | Normalize and validate E.164 phone numbers. |
| `bin/migrate.php` | Setup/DB | New | Apply ordered SQL migrations and create private storage. |
| `bin/seed.php` | Setup/Backend | New | Create the initial system administrator without resetting existing passwords. |
| `bin/notifications-worker.php` | Backend/Ops | New | Process one bounded queue batch for cron or Task Scheduler. |
| `public/index.php` | Backend | New | Wire routes, controllers, sessions, and fast webhook responses. |
| `public/router.php` | Backend | New | Route PHP development-server requests and serve public assets. |
| `public/.htaccess` | Backend/Ops | New | Route Apache requests to the front controller. |
| `app/Views/staff/login.php` | Frontend | New | Provide the real staff login form. |
| `app/Views/staff/notifications.php` | Frontend | New | Provide the staff notification history shell. |
| `public/assets/css/app.css` | Frontend | New | Style the functional staff pages. |
| `public/assets/js/notifications.js` | Frontend | New | Fetch and render real notification history from the API. |

### Explicit trace additions to the originally approved list

The shared Feature E page required a staff session and database-backed rate limiting, so this module adds `users`, `rate_limits`, the login/session controllers and security helpers, plus `bin/seed.php`. The README also documents `bin/migrate.php`, `bin/seed.php`, the staff login, and Windows scheduling; all are implemented above. Auth backlog: **force password change on first login**.

## End-to-end round trips

1. Staff submits the login form with its CSRF token → `POST /staff/login` → `AuthController` checks IP/email limits and verifies the password against `users` → `StaffAuth` rotates the session ID → browser redirects to `/staff/notifications`.
2. Staff opens history → `GET /staff/notifications` returns the staff page → `notifications.js` calls `GET /api/staff/notifications` with the session cookie → controller checks the session → repository reads the latest notification rows and monthly provider-accepted count → JavaScript renders those database values.
3. A later feature calls `NotificationService::enqueue` → the service normalizes the phone, rejects non-transactional sends until Feature C, checks STOP records, applies its per-phone daily budget and idempotency policy inside a transaction → repository inserts a `queued` row → caller receives its notification ID.
4. Cron or Task Scheduler runs the worker → repository locks due rows using `SELECT ... FOR UPDATE SKIP LOCKED`, sets `sending` and a unique claim token, and commits → worker checks the STOP/consent gate before any non-transactional send → selected adapter calls the configured SMS provider → a conditional update records the provider message ID and `sent` status, or returns a known retryable failure to `queued` with backoff.
5. Provider delivery callback → HMAC validation against the exact request body → update the matching notification's delivery status → HTTP 200 acknowledgement. The callback contains no staff/customer data beyond its contract.
6. Provider inbound STOP callback → HMAC validation → normalize the sender and classify the message → insert the full raw body and normalized event into `inbound_sms_events` using its unique provider message ID → HTTP 200 acknowledgement. Later non-transactional sends check the STOP table before delivery; transactional notifications remain permitted. Feature C consumes these immutable records into `rules_acceptances`.

## Operational limits

- Two workers cannot claim the same queued row. Claimed `sending` rows are not automatically reclaimed after a process crash; staff must reconcile provider acceptance before any manual retry.
- A network failure with an unknown provider outcome is terminal for automatic retry to avoid a possible duplicate SMS. The row remains available in staff history for reconciliation.
- Provider callbacks use the documented application HMAC contract. If a provider cannot set the required header or does not offer the event directly, configure a trusted forwarding gateway to map its payload into that contract.
