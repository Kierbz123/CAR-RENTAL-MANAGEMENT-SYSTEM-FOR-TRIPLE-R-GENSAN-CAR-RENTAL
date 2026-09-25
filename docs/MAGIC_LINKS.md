# Shared Magic-Link Infrastructure

This feature builds the no-customer-account access layer in the approved plan order. Booking and hold rows do not exist yet. Tokens therefore use the configured TTL until a future caller associates them with a booking; `booking_id` is present now and reserved for that integration.

## File trace

| File | Layer | Change | Reason |
|---|---|---|---|
| `database/migrations/002_magic_links.sql` | DB | New | Add hashed single-use tokens, nullable reserved booking linkage, append-only use audit, and an atomic per-booking issuance cap. |
| `app/Repositories/MagicLinkRepository.php` | Backend | New | Store hashes, attach booking/hold data, serialize redemption, and append usage records. |
| `app/Services/MagicLinkService.php` | Backend | New | Create random tokens, enforce allowed purposes/contact limits/TTL, queue high-priority links, redeem once, and expose purpose-bound session context. |
| `app/Controllers/MagicLinkController.php` | Backend | New | Serve the public redemption page and accept the token only in a same-origin JSON POST body. |
| `app/Services/SmsMessageCipher.php` | Backend | New | Encrypt sensitive SMS outbox content with the dedicated SMS cipher key and decrypt it only in the worker/server. |
| `app/Services/NotificationService.php` | Backend | Modified | Support encrypted-at-rest queue bodies, decrypt immediately before provider send, and stop before claiming encrypted work if no valid key is configured. |
| `app/Repositories/NotificationRepository.php` | Backend | Modified | Persist encrypted bodies, redact magic-link bodies after terminal delivery/failure, retain other encrypted bodies for staff history, and detect due encrypted jobs before worker claims. |
| `app/Controllers/StaffNotificationController.php` | Backend | Modified | Return staff-only safe message previews, never raw encrypted data. |
| `app/Views/staff/notifications.php` | Frontend | Modified | Add a message-preview column to the authenticated delivery history. |
| `public/assets/js/notifications.js` | Frontend | Modified | Render the server-provided safe preview, never ciphertext or raw magic-link bodies. |
| `app/Views/magic-link.php` | Frontend | New | Provide a public, customer-only link-verification page without the staff shell. |
| `public/assets/js/magic-links.js` | Frontend | New | Read the token from the URL fragment, remove it from browser history, and POST it in the JSON body after an explicit Continue click. |
| `public/index.php` | Backend | Modified | Wire the page and redemption API with the current PDO, SMS queue, rate limiter, and session. |
| `bin/notifications-worker.php` | Backend/Ops | Modified | Supply the SMS body cipher to the queue worker. |
| `.env.example` | Setup | Modified | Add the public origin, dedicated cipher key, optional previous key, TTL, and rate limits. |
| `README.md` | Setup | Modified | Specify the token schema/API contract, body-only token transport, encrypted queue/staff behavior, and key rotation. |
| `docs/MAGIC_LINKS.md` | Documentation | New | Keep this file trace, schema contract, API contract, and feature round trips. |
| `docs/RECONNAISSANCE.md` | Documentation | Modified | Record the approved booking-independent token decision. |

## Database contract

`booking_access_tokens` in `database/migrations/002_magic_links.sql` contains:

| Column | MySQL type | Contract |
|---|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Internal primary key |
| `token_hash` | `CHAR(64) CHARACTER SET ascii COLLATE ascii_bin` | Unique SHA-256 hex digest; raw token is never stored in this table |
| `purpose` | `VARCHAR(64)` | One of `booking_manage`, `accept_rules`, `submit_payment`; checked against the expected purpose during redemption |
| `booking_id` | `BIGINT UNSIGNED NULL` | Reserved for Feature C; no FK before the bookings table exists |
| `expires_at` | `DATETIME(6)` | UTC TTL expiry; when linked to a booking it is reduced to the hold expiry if earlier |
| `used_at` | `DATETIME(6) NULL` | Set once by the conditional single-use redemption write |
| `created_at` | `DATETIME(6)` | UTC creation time |

`token_usages` references the token row and appends action, IP, user agent, and UTC timestamp. Database triggers reject updates and deletes. Tokens are issued with 32 cryptographically random bytes and base64url encoding; the unique SHA-256 digest is stored. Purpose mismatch, unknown, expired, and consumed links return the same generic error. A purpose mismatch is recorded for known tokens but does not consume the valid token.

`magic_link_booking_limits` has `booking_id BIGINT UNSIGNED PRIMARY KEY`, `issue_count TINYINT UNSIGNED`, and `updated_at DATETIME(6)`. It reserves the six-link lifetime cap (initial issue plus five re-sends) under a conditional increment, so concurrent issuance cannot exceed the configured cap. It has no FK because bookings do not exist yet.

## API contract and token transport

The SMS link is `APP_BASE_URL/magic-link#token=<43-char-base64url-token>&purpose=<purpose>`. The fragment is not sent in the page request. The JavaScript removes it from browser history before making any API call. No route accepts a token in a URL path or query string.

Redemption is **POST `/api/magic-links/redeem` only**, with a same-origin `Origin` header and `Content-Type: application/json`:

```json
{"token":"<43-character-base64url-token>","purpose":"booking_manage"}
```

The controller rejects a `token` query parameter and reads the token only from the JSON POST body. The service rate-limits IP and token-hash attempts, then the repository locks the hash row, checks purpose/expiry/use state, atomically sets `used_at`, and appends the audit record. On success the session ID rotates and the server stores a purpose-bound context through token expiry. The response contains only `verified` and `purpose`; booking ID and customer PII are not returned. The page then calls `GET /api/magic-links/session?purpose=...` to confirm that the same-origin session context is active; that endpoint reads no token, and its query parameter is only the non-secret purpose name.

Issuance is an internal service call for future booking/consent handlers; there is no public arbitrary-contact SMS issue endpoint. Allowed purposes are fixed in `MagicLinkService`. Per-contact rate limits use normalized phone and optional normalized email identities; only the existing rate-limit hash is stored. The defaults are 10 per phone/email per fixed 24-hour window, 20 redemption attempts per IP per hour, and 10 per token hash per hour. Booking-linked issuance is capped at six total tokens for the booking lifetime (initial send plus up to five re-sends). Current unlinked issuance remains limited by phone/email and the shared Feature E SMS budget.

## SMS body encryption and staff history

The token table stores only the hash. All newly queued SMS bodies are persisted as `smsenc:v1:` AES-256-GCM ciphertext in `notifications.rendered_message`; a bearer token exists raw only in process memory. `SMS_CIPHER_KEY` is a dedicated base64-encoded 32-byte key and must not reuse `APP_KEY` or a session key. `SMS_CIPHER_KEY_PREVIOUS` is optional during key rotation. The worker decrypts the body only immediately before provider send. Magic-link bodies are deleted after terminal send/failure; other encrypted bodies (including policy-suppressed messages) remain encrypted for authenticated staff history. Retryable rows retain ciphertext. If an encrypted job is due but no valid current/previous key is configured, the worker exits before claiming it. A decryption or worker configuration failure preserves ciphertext for key recovery and marks the row failed for manual reconciliation.

The authenticated staff API returns a `message_preview`, never `rendered_message`. Ordinary content is decrypted server-side for authorized staff; `magic_link.*` is always `[Magic-link content masked]`. Ciphertext is never rendered. Decryption failure returns `[Encrypted message unavailable]`. To rotate keys, pause the worker, install the new current key and old key as previous, then resume. Retain the old key until no old-key encrypted rows remain in `notifications`, including failed or policy-suppressed rows retained for history/key recovery; drain or securely remove them before a subsequent rotation because the app supports one previous key.

## End-to-end round trips

1. A future booking or consent service requests a link with normalized phone, optional email, purpose, and optional booking/hold details → `MagicLinkService` applies contact and per-booking rate limits → it generates 32 random bytes and stores only their hash and expiry in `booking_access_tokens` → it builds a fragment URL and enqueues a high-priority transactional SMS with an encrypted body through Feature E → Feature E applies its SMS daily budget/idempotency rules → the token ID is returned to the internal caller, never the raw token.
2. The customer opens `/magic-link#...` → the server receives only `GET /magic-link` → the page offers an explicit Continue button so link scanners do not consume the one-time token → JavaScript removes the fragment from history and sends `{token,purpose}` in a same-origin JSON POST body → the service rate-limits IP/hash → the repository locks and conditionally consumes the matching token and appends `token_usages` → the controller rotates the session ID, stores purpose/booking/expiry context server-side, and returns a minimal success response → JavaScript displays verification success. No PII is returned.
3. Feature C creates/associates booking context → it calls `MagicLinkService::attachBooking(tokenId, bookingId, holdExpiresAt)` → repository fills the already-existing nullable `booking_id` and sets `expires_at = LEAST(expires_at, hold_expires_at)` → later redemption cannot outlive the booking hold; no migration change is needed.
4. Staff opens `/staff/notifications` → authenticated JavaScript calls `/api/staff/notifications` → the controller transforms stored bodies to safe previews and removes the original field → magic-link entries are masked, normal messages remain readable, and ciphertext is never sent to the browser.

## Runtime limits

The current environment has no PHP/MySQL runtime, so neither migration execution nor HTTP/provider delivery has been run here. Static source review and local run instructions are the available verification; install `SMS_CIPHER_KEY` before any notification enqueue and verify the behavior against the README on PHP/MySQL.
