# Plan: Customer Notifications Through Telegram

**Written:** 2026-10-01
**Status:** phases 1 to 3 are built and tested (2026-10-01), with the QR code from decision 5. Phase 4 (self-service connection from the customer's booking page, and webhook mode) is not built. What is still needed from the owner is in section 9; how to switch it on and try it is in section 11. This document started as the trace proposal that step 1 of the build workflow in [MASTER_FEATURE_BUILD_PLAN.md](MASTER_FEATURE_BUILD_PLAN.md) requires.
**Goal:** the messages the system already queues for customers (booking link, confirmation, pickup and return notices, reminders) reach the customer in Telegram, free of charge, without weakening anything the SMS path guarantees today.

Telegram facts below are from general knowledge of the Bot API and were not re-checked against Telegram's documentation today. Confirm them at <https://core.telegram.org/bots/api> before building.

---

## 1. What Telegram can and cannot do

This shapes the whole design, so it comes first.

| Fact | Consequence |
|---|---|
| A bot cannot message a phone number. It can only message someone who has opened the bot and pressed **Start**. | Each customer must connect once. The system cannot simply swap "send SMS to 09xx" for "send Telegram to 09xx". |
| When a customer presses Start, Telegram tells the bot that chat's id. | Connecting means: prove which customer is pressing Start, then store that chat id against the customer. |
| Sending is a single HTTPS call (`sendMessage`) and costs nothing. | No per-message fee and no sender-name registration. |
| Telegram gives bots no delivery receipt. | "Sent" will mean "Telegram accepted it", not "the customer's phone received it". |
| A customer can block the bot at any time; the next send is refused. | A refusal must disconnect that customer cleanly and fall back, not retry forever. |
| A bot learns about Start and other messages either by a webhook (needs a fixed public HTTPS address) or by polling (`getUpdates`). The two cannot be on at once. | Polling works from the laptop with no public address, which suits the presentation. A webhook suits a permanent server. |
| Bot chats are not end-to-end encrypted. | Message text passes through Telegram's servers, comparable to an SMS provider seeing the text. |

Telegram's separate "Gateway" service does send to phone numbers, but only verification codes and for a fee. It is not suitable for booking notifications and is not used here.

---

## 2. How it works today

| Piece | Where | What matters for this plan |
|---|---|---|
| Queue | `notifications` table | One row per message. Body encrypted at rest. `channel` is already a column, with `'sms'` as its only value. Addressed by `recipient_phone`. |
| Queueing | `NotificationService::enqueue()` | Validates, enforces the per-phone daily limit and the idempotency key, suppresses non-transactional messages by policy. Called from `RentalService` (lifecycle notices, reminders) and `MagicLinkService` (booking links). |
| Sending | `bin/notifications-worker.php` → `processBatch()` | Claims rows with `SKIP LOCKED`, decrypts, calls a provider through `SmsProviderInterface::send(recipient, message, priority)`, records sent, retry with backoff, or failed. |
| Providers | `SmsProviderFactory` | `semaphore` or `philsms`. Errors carry a `retryable` flag. |
| Inbound | `/webhooks/sms/*`, `inbound_sms_events`, `rules_acceptances` | STOP replies are stored once (idempotent) and block non-transactional messages. |
| Staff view | `/staff/notifications` | History with masked recipient and message preview. |

No SMS provider key is configured in this installation, so every queued message currently ends as "failed".

---

## 3. Design

### 3.1 The clean rule

**The queue, its guarantees and its callers stay as they are. Telegram is added as a second delivery channel behind the same queue.** Nothing that creates a notification needs to know how it will be delivered.

Concretely:

1. A message is queued exactly as today, with one addition: the caller passes the customer's id.
2. At queue time the system picks the channel: Telegram if that customer has an active connection, otherwise SMS. The choice is written on the row, so the staff history shows how each message went.
3. The worker sends each row through the provider for its channel.
4. If Telegram refuses because the customer blocked the bot, the connection is ended, and the message falls back to SMS when an SMS provider is configured. Otherwise it is marked failed with a plain reason.

### 3.2 Connecting a customer (the opt-in)

One mechanism, a short **one-time connection code**, delivered two ways.

**At the counter (phase 1):**

1. Staff chooses **Show QR code** in the Telegram column of the customer list, or opens the customer's page and chooses **Show Telegram QR code**.
2. The page shows a code (8 characters, valid for 15 minutes, usable once) and a link `https://t.me/<bot>?start=<code>`.
3. The customer opens the bot on their own phone and presses Start (the link carries the code), or types the code to the bot.
4. The bot replies "You're connected to Triple R Gensan Car Rental. You'll get your booking updates here. Send /stop at any time to disconnect."
5. The customer's page now shows **Telegram connected** with the date, and a **Disconnect** button.

**From the customer's own booking page (phase 2):** a "Get updates on Telegram" button on `/customer/booking` creates a code for the customer whose secure link was verified, with no staff step.

Why a code and not the customer's phone number: the code proves which customer is pressing Start without the system having to match phone numbers, and pressing Start is itself the customer's consent. Only private chats are accepted; group chats are ignored.

### 3.3 Disconnecting

| Trigger | Result |
|---|---|
| Customer sends `/stop` to the bot | Connection ended at once; bot confirms. |
| Staff chooses Disconnect | Connection ended; recorded with the staff member. |
| Telegram refuses a send (bot blocked, account deleted) | Connection ended automatically; message falls back. |
| Customer connects a different Telegram account | Old connection ended, new one active. |

A connection is never edited. Ending one marks it revoked with a reason and time, and a new connection is a new row, so the history stays complete, in line with the system's other records.

### 3.4 What the bot answers

| Customer sends | Bot does |
|---|---|
| `/start <code>` or a bare valid code | Connects, confirms. |
| `/start` with no code, or an invalid or expired code | Explains that a code comes from the rental office, with the office phone number. Attempts are rate-limited per chat. |
| `/stop` | Disconnects, confirms. |
| `/help` | One line on what the chat is for, plus the office phone number. |
| Anything else | A fixed reply that this chat only sends booking updates. The text is not stored. |

---

## 4. Data changes

One forward-only migration. Use the next free number when implementation starts: it is 012 today, but the proposed views and procedures from the database documentation would also need a migration and must not collide with it.

| Table | Change | Purpose |
|---|---|---|
| `customer_telegram_links` | New | One row per connection: customer, chat id (encrypted, plus a keyed fingerprint for lookup), status `active` or `revoked`, who or what created it, when, and the revoke reason and time. Uniqueness: one active connection per customer and one active customer per chat. Foreign key to `customers` with `RESTRICT`. |
| `telegram_link_codes` | New | One row per connection code: customer, SHA-256 hash of the code (never the code itself), expiry, used-at, created by. |
| `telegram_updates` | New | One row per inbound update, keyed by Telegram's `update_id`, so a repeated update is ignored. Stores the kind of event and the chat fingerprint, not message text. |
| `notifications` | Alter | `channel` gains the value `'telegram'`. New nullable `customer_id` and `telegram_link_id`. `recipient_phone` stays, so the daily limit, idempotency and staff history keep working unchanged. |

The chat id is treated like a phone number: encrypted with `CustomerPiiCipher` under its own context and looked up by fingerprint, the same pattern as `customer_contacts`.

Adding three tables changes the counts quoted in the database documentation (39 tables), which would need updating.

---

## 5. Code changes

| File | Change |
|---|---|
| `app/Services/Telegram/TelegramBotClient.php` (new) | The only place that talks to Telegram: `sendMessage`, `getUpdates`, `setWebhook`, `deleteWebhook`. Plain text only. The bot token appears in the request address, so this class never logs addresses or raw errors. |
| `app/Services/Telegram/TelegramProvider.php` (new) | Implements the existing `SmsProviderInterface`. Maps Telegram's answers: rate limit (wait the stated seconds, retry), server error or timeout (retry), blocked or chat not found (do not retry, signal "disconnect"). |
| `app/Services/Sms/SmsProviderFactory.php` | Accept `telegram`. |
| `app/Services/TelegramLinkService.php` (new) | Create a code, redeem a code, disconnect, find the active connection for a customer. All writes in transactions that lock the customer row, following the system's lock order. |
| `app/Repositories/TelegramLinkRepository.php` (new) | Queries for the three new tables. |
| `app/Services/TelegramUpdateHandler.php` (new) | Takes one update from either source and applies section 3.4. |
| `app/Services/NotificationService.php` | `enqueue()` takes an optional customer id and chooses the channel. `processBatch()` resolves the chat id for Telegram rows and handles the "disconnect and fall back" signal. The `SMS_PROVIDER` check applies only to SMS rows. |
| `app/Services/RentalService.php`, `MagicLinkService.php` | Pass the customer id when queueing. No other change. |
| `bin/telegram-updates.php` (new) | Polling worker: asks Telegram for new updates, hands each to the handler, remembers where it got to. Safe to run concurrently and to re-run. |
| `app/Controllers/TelegramWebhookController.php` (new, phase 4, not built) | For a permanent server: receives updates, checks Telegram's secret header in constant time, answers 200 quickly. |
| `app/Controllers/Customers/CustomerController.php` | Two actions: create a connection code, disconnect. Roles: system admin and front desk, the same as the rest of the customer page. |
| `app/Views/customers/detail.php` | A "Telegram" panel: status, Connect (shows the code and link), Disconnect with confirmation. |
| `app/Views/staff/notifications.php`, `notifications.js` | A Channel column. Page title becomes "Notifications". |
| `.env.example` | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_LINK_CODE_TTL_MINUTES`. (`TELEGRAM_UPDATES_MODE` and `TELEGRAM_WEBHOOK_SECRET` belong to phase 4 and were not added.) |
| `bin/demo-online.ps1` | Also start the polling worker for the presentation. |

---

## 6. Safeguards

| Risk | Safeguard |
|---|---|
| The code is given to the wrong person, who then receives someone else's booking links | Short expiry, single use, the staff screen shows the customer's name while the code is displayed, the customer page shows the connection afterwards, and staff can disconnect at once. |
| Someone guesses codes by messaging the bot | Codes are 8 characters from a 32-character set, expire in 15 minutes, and attempts are limited per chat using the existing `RateLimiter`. |
| The same update arrives twice | `update_id` is unique in `telegram_updates`; the second is ignored. |
| A forged webhook call | Telegram's secret header is required and compared in constant time; anything else gets a quiet 200 with no action, as the SMS webhooks do. |
| The bot token leaks | Kept only in `.env`; never written to logs, the database or error messages. |
| Messages loop or flood | The existing per-phone daily limit applies to both channels. |
| A customer who opted out still gets messages | `/stop` ends the connection before the reply is sent. Non-transactional messages stay suppressed by policy on every channel, exactly as now. |
| Telegram is down | Retry with the existing backoff; after the last attempt the row is failed with a readable reason, as for SMS. |
| Staff outside the customer roles change connections | Same role check as the customer page, and the new actions are added to the role matrix in `bin/test-roles-http.php`. |

Privacy note for the customer-facing wording: connecting means booking updates, including the secure booking link, are sent through Telegram.

---

## 7. Phases

| Phase | Delivers | Done when |
|---|---|---|
| 0. Decisions and bot | Section 9 answered; bot created with BotFather; token and username in `.env` | The bot answers `/start` by hand in Telegram. |
| 1. Sending (built) | Migration, `TelegramBotClient`, `TelegramProvider`, channel choice in `enqueue()`, worker dispatch | A message queued for a connected customer arrives in Telegram; an unconnected customer's message still goes the SMS route; all existing suites pass. |
| 2. Connecting (built) | Link codes, QR code, polling worker, update handler, customer page panel, `/stop` | A customer can be connected and disconnected end to end from the counter. |
| 3. Fallback and history (built) | Block detection, fallback to SMS, Channel column | Blocking the bot ends the connection on the next send and the history shows why. |
| 4. Self-service and webhook (not built) | "Get updates on Telegram" on the booking page; webhook mode | A customer connects from their own booking page; a permanent server can use the webhook. |
| 5. Verify and document (done for phases 1 to 3) | Tests, role matrix, README | Section 8 passes and the documents match the code. |

Phases 1 to 3 are enough for the presentation.

---

## 8. How it will be checked

- **`bin/test-telegram.php` (new), against a stand-in for Telegram's API so no network is needed:** connect; reject an expired, used or wrong code; rate-limit guesses; `/stop`; relink; a repeated update changes nothing; a "blocked" answer disconnects and falls back; a rate-limit answer waits and retries; the daily limit and idempotency key behave as before; non-transactional messages stay suppressed.
- **Database guards:** the uniqueness rules on connections hold under two simultaneous attempts; the foreign keys refuse deleting a customer with a connection history.
- **Role matrix:** the two new actions refused for every role except system admin and front desk.
- **Existing suites:** all of them, unchanged, still pass.
- **One live check by hand:** connect a real Telegram account, make a reservation, receive the booking link, open it, send `/stop`.

---

## 9. Decisions needed from the owner

1. **Create the bot.** Only you can do this: in Telegram, message `@BotFather`, send `/newbot`, choose a name and a username, and put the token it gives you in `.env`. Suggested username: something like `TripleRGensanBot`.
2. **Fallback.** When a customer is not connected, should the message go by SMS (needs a paid provider account) or simply not be sent? With no SMS provider configured, the second is what will happen.
3. **Who must connect.** Optional for every customer, or asked of everyone at booking?
4. **Self-service connection** from the customer's booking page (phase 4): wanted, or counter-only?
5. **QR code** on the staff screen so the customer can scan instead of typing. **Decided and built:** the MIT-licensed `qrcode-generator` 2.0.4 is bundled at `public/assets/js/vendor/qrcode-generator.js`.
6. **Module name.** `FEATURE_T` is proposed, since M9 and M10 are reserved for documents and reporting.

---

Decision 1 is the only one that blocks use: until a bot exists and its token is in `.env`, the feature stays switched off. Decisions 2 to 4 currently behave as: SMS route when not connected (which fails without an SMS provider), connecting is optional, and counter-only.

---

## 10. For the presentation

- The polling worker needs only an internet connection, not a public address, so Telegram works from the laptop even without the tunnel.
- The secure booking link inside a message still has to be openable, so run `bin/demo-online.ps1` if you want to tap that link on a phone.
- Connect your own Telegram account to a demo customer before the defense, and make one reservation to see the message arrive.

---

## 11. Switching it on and trying it

Built on 2026-10-01. Migration `012_telegram.sql` is already applied to the local everyday database (a backup was taken first).

**Once, by the owner:**

1. In Telegram, open a chat with `@BotFather`, send `/newbot`, and choose a display name and a username ending in `bot`.
2. BotFather replies with a token. Open `.env` and set `TELEGRAM_BOT_TOKEN=` to that token and `TELEGRAM_BOT_USERNAME=` to the username without the `@`. Treat the token like a password: do not paste it into chats, screenshots or Git.

**Each time, for the presentation:** run `.\bin\demo-online.ps1`. It prints "Telegram is ON" when the bot is reachable and starts both workers. To use Telegram on the laptop without going online, run these two in separate terminals instead, alongside the normal web server:

```bash
php bin/telegram-updates.php
```

```bash
php bin/notifications-worker.php --watch=3
```

**The demonstration:**

1. Sign in as front desk and go to Customers. Pick a customer who has a primary phone number.
2. In the customer list, click **Show QR code** on that customer's row (or **Show Telegram QR code** in the Telegram panel on their page). A QR code and a code appear.
3. Scan the QR code with a phone and press **Start** in Telegram. The bot replies that the chat is connected and the staff page changes to **Connected** within a few seconds.
4. Make a reservation for that customer. The secure booking link arrives in Telegram. **Notifications** in the staff menu shows the row with Channel = Telegram.
5. Send `/stop` to the bot, or choose **Disconnect Telegram** on the customer page, to show opting out.

**What was checked, and what was not.** `bin/test-telegram.php` (92 checks) and `bin/test-roles-http.php` (1,468 checks with a bot configured, 1,478 without) pass, and every earlier suite still passes. The QR code on the page was decoded independently and carries the right link. All of that ran against a stand-in for Telegram on this machine. Nothing has yet been sent through the real Telegram service, because that needs the owner's bot: the first run with a real token and a real phone is the one check still to do.
