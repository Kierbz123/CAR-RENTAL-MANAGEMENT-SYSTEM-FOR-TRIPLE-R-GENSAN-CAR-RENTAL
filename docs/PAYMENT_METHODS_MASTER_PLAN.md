# Master Plan: Payment Methods (E-Wallets, Card, Online Banking, Cash) with a Simulated Checkout

**Written:** 2026-10-02
**Status:** built and tested on 2026-10-02, the same day, after the owner said "go implement". All five phases are done. Sections 1 to 13 are the plan as it was written; **section 14 says what was built and where it differs from the plan**, and is the part to trust where the two disagree. The largest difference: the screenshot-proof path was built by then and has been kept, so `payments` is an added table (35 in all, counting the migration ledger), not a replacement for `payment_proofs`.
**Goal:** the 30% downpayment and the balance can be paid by GCash, Maya, GrabPay, credit or debit card, online banking, or cash. The online methods run through a **simulated checkout**: no real money moves, and the presenter can choose what happens (approved, declined, insufficient balance, cancelled, timed out) so the system's handling of each case can be shown. Cash and other counter payments are recorded by staff as today.

Statements about real payment gateways in section 10 are from general knowledge and were not re-checked today. Confirm them before saying them at the defense.

---

## 1. Where the system is today

Read from the code and the local database on 2026-10-02.

| Piece | State |
|---|---|
| 30% downpayment (`018_downpayment.sql`) | Built. Amount stored at booking; finance or an administrator records a GCash reference on the agreement page; a reservation cannot be confirmed while the downpayment is `due`. |
| Online booking tables (`019_online_booking.sql`) | Applied locally today. `booking_reference` and `booking_source` are written by the code. `rules_versions` holds one policy (version 1, which says "by GCash"). `payment_proofs` exists, but no page or service writes to it; only the expiry check reads it. |
| Customer's page | The secure booking link (`/customer/booking`) shows the downpayment, its state and the balance. The customer cannot pay from it. |
| Balance at pickup | Shown as a figure. Receiving it is not recorded anywhere. |
| Wording | "GCash" is written into the service (`normalizeGcashReference`), four staff views, the customer page, the policy text, the README and the tests. |
| Database | 34 tables locally. `database/schema.sql` still describes 32: it has not been regenerated since 019. No agreement has a recorded downpayment yet, so nothing needs careful data migration. |

What this means for the plan:

1. Migrations are checked by checksum, so 019 cannot be edited. The change is a new migration, `020_payments.sql`.
2. `payment_proofs` is the "dead table" the teacher warned about unless something uses it. This plan **replaces** it with one `payments` table, so the table count stays at 34.
3. The customer already has a signed-in page per booking. Paying online hangs off that page; no public booking form is needed for this feature.

---

## 2. What "simulated" means, and the rules that keep it honest

The checkout is a page of this system that plays the part of a payment gateway. It follows the same steps a real one would (start a payment, send the customer to checkout, receive a signed result, settle the booking), so swapping in a real gateway later changes one class, not the booking logic.

Rules that are part of the design, not optional polish:

| Rule | Why |
|---|---|
| Every simulated screen carries a fixed banner: "Demonstration checkout. No real money is moved." | The demo runs through a public tunnel. Nobody who opens the link should believe they paid. |
| Neutral "Demo Pay" look. Method names appear as text; no GCash, Maya or bank logos, and no copy of their sign-in screens. | A public page that looks like a wallet's login is a phishing lookalike, whatever the intent. |
| No MPIN, OTP or password fields. E-wallet and bank screens show a made-up account and outcome buttons. | An audience member must never be invited to type a real secret. |
| The card form accepts **only** the test numbers listed on the page. Any other number is refused and nothing is kept. | A real card number can then never reach the server's storage or logs. |
| Card number and CVV are never stored or logged. Only the brand and last four digits are kept ("Visa ending 4242"). | This is how a real integration behaves, and it is the answer to "where do you keep card numbers?" |
| Each payment row records its channel (`counter` or `online_demo`). | A simulated payment can never be mistaken for real money in a report. |
| The simulated gateway only loads when `PAYMENT_GATEWAY=simulated`. | A real deployment cannot accept pretend payments by accident. |

---

## 3. Payment methods

Kept in `config/payments.php` (label, group, where it can be used, on or off), the same idea as `config/site.php`. The database stores the key in an `ENUM`; there is no lookup table.

| Key | Shown as | Online (simulated) | At the counter (staff records) |
|---|---|---|---|
| `gcash` | GCash | Yes | Yes, with the GCash reference number |
| `maya` | Maya | Yes | Yes, with the reference number |
| `grabpay` | GrabPay | Yes | Yes, with the reference number |
| `card` | Credit or debit card | Yes, test cards only | Yes, with the terminal's approval code |
| `online_banking` | Online banking (BPI, BDO, UnionBank) | Yes | Yes, as a bank transfer with its reference |
| `cash` | Cash | No. The customer chooses "Pay cash at the office" and is shown the address, hours and the hold deadline. | Yes. No outside reference; the system's receipt number is the record. |

Adding a method later is one config entry and one `ENUM` value.

---

## 4. Database: migration `020_payments.sql`

### 4.1 One new table, one table removed

`payments` holds every attempt and every payment received, online or at the counter, for the downpayment or the balance. `payment_proofs` is dropped (it is empty and unused).

| Column | Type | Meaning |
|---|---|---|
| `payment_id` | BIGINT UNSIGNED, PK | |
| `agreement_id` | FK to `rental_agreements` | The booking paid for |
| `purpose` | ENUM(`downpayment`, `balance`) | What the money is for |
| `channel` | ENUM(`counter`, `online_demo`) | Staff recorded it, or the simulated checkout did |
| `method` | ENUM(`cash`, `gcash`, `maya`, `grabpay`, `card`, `online_banking`) | |
| `method_detail` | VARCHAR(60), NULL | "Visa ending 4242", "BPI". Never a full card number |
| `amount` | DECIMAL(18,2), > 0 | Set by the server, never by the browser |
| `payment_status` | ENUM(`pending`, `paid`, `failed`, `cancelled`, `expired`) | |
| `receipt_number` | CHAR(12) ascii, UNIQUE | Made by the system for every row; also identifies the checkout |
| `external_reference` | VARCHAR(40) ascii, UNIQUE, NULL | The provider's reference (GCash number, card approval code, simulated transaction id). NULL for cash |
| `failure_reason` | VARCHAR(160), NULL | Required when `failed` |
| `recorded_by` | FK to `users`, NULL | Required for `counter`; NULL for online |
| `created_at`, `expires_at`, `settled_at` | DATETIME(6) | `expires_at` only while an online payment is pending |
| `pending_agreement_id` | generated, UNIQUE | Allows one pending payment per booking at a time |
| `paid_downpayment_agreement_id` | generated, UNIQUE | Allows one paid downpayment per booking, ever |

Guards, in the style of the existing migrations:

- CHECKs: cash is counter-only; a counter row is created already `paid` with `recorded_by` and `settled_at`; an online row has no `recorded_by`; `failed` needs a reason; non-cash `paid` rows need an `external_reference`.
- Trigger: the booking, purpose, method, amount and receipt number never change; a row that has left `pending` is never changed again. No deletes.
- Real foreign keys, as in the consolidation (no `entity_type` + `entity_id`).

### 4.2 `rental_agreements` loses three duplicate columns

`downpayment_reference`, `downpayment_received_by` and `downpayment_received_at` move into `payments` (who, when, reference and now method live in one place). `downpayment_amount` and `downpayment_status` stay: the amount is fixed at booking, and the status is the gate that confirming checks. The 018 CHECK and trigger are re-created without the removed columns. Any agreement already `received` is copied into `payments` first as a counter GCash payment.

This also removes the rule that a received downpayment must name a staff member, which an online payment cannot satisfy.

### 4.3 Policy text

`rules_versions` is append-only, so the policy gets **version 2** with the method-neutral wording ("…a downpayment of 30% of the rental cost, by any payment method the office accepts…"). The customer ticks it before paying online, which writes the `accepted` row in `rules_acceptances` that 019 prepared. Both tables then have a page behind them.

### 4.4 Migration safety

- Run every suite against a separate test database first.
- On the local everyday database, migrate with the permanent migration account. Triggers created by a temporary account fail with error 1449 once that account is dropped.
- Regenerate `database/schema.sql` afterwards; it is already one migration behind.

---

## 5. Code structure

Follows the existing layers (controller, service, repository) and the SMS provider pattern.

| New file | Job |
|---|---|
| `config/payments.php` | The method list from section 3 and the test cards |
| `app/Repositories/PaymentRepository.php` | Inserts, the guarded status change, lists and totals |
| `app/Services/PaymentService.php` | The rules: amounts, who may pay what and when, settling, expiry, messages |
| `app/Services/Payments/PaymentGateway.php` | Interface: start a checkout, verify a result |
| `app/Services/Payments/SimulatedGateway.php` | The demo implementation; signs its results with `PAYMENT_WEBHOOK_SECRET` |
| `app/Services/Payments/PaymentGatewayFactory.php` | Picks the gateway from `PAYMENT_GATEWAY`, like `Sms/SmsProviderFactory.php` |
| `app/Controllers/CheckoutController.php` | Customer side: choose method, checkout, result, receipt |
| `app/Controllers/Rentals/PaymentController.php` | Staff side: record a counter payment, payments report |
| `app/Views/customer/pay.php`, `checkout-demo.php`, `payment-result.php` | Customer pages |
| `app/Views/rentals/payments.php` | Finance report |
| `public/assets/js/checkout-demo.js` | Card field formatting and the outcome buttons |
| `bin/test-payments.php`, `bin/test-payments-http.php` | Acceptance checks |

| Changed file | Change |
|---|---|
| `app/Services/RentalService.php` | `recordDownpayment` hands over to `PaymentService`; `normalizeGcashReference` becomes method-neutral; `hasProofAwaitingReview` becomes "has a pending payment"; the `complete` gate from section 8 |
| `app/Repositories/RentalRepository.php` | `recordDownpayment` only flips the status; `find` no longer joins the receiver |
| `app/Views/rentals/agreement-detail.php` | The Downpayment panel becomes a Payments panel: every attempt, a record-payment form with a method list, the balance |
| `agreements.php`, `booking-new.php`, `reserve.php`, `customer/booking.php`, `rental-booking.js` | "by GCash" wording becomes method-neutral; the customer page gains "Pay now" |
| `app/Support/StatusPresenter.php`, `Navigation.php` | Payment status badges; a Payments menu entry for finance, administrators and the auditor |
| `public/index.php` | New routes; `/rentals/downpayment` stays as it is so nothing bookmarked or tested breaks |
| `bin/rentals-expire.php` | Also expires pending payments past their time |
| `.env.example`, `README.md`, `database/schema.sql` | New settings, a rewritten "Downpayment" section, the regenerated schema |
| `bin/test-downpayment.php`, `test-roles-http.php`, `test-m7*.php`, `test-m6-db-guards.php` | Updated for the moved columns and the new record-payment form |

---

## 6. How an online payment runs

```
Customer's booking page          This system                          Simulated gateway (also this system)
        |  Pay now, picks a method   |                                         |
        |--------------------------->| accept policy v2, create payment        |
        |                            | (pending, amount from the agreement)    |
        |                            |---------------- start checkout -------->|
        |<------------------------------------------- checkout page -----------|
        |  chooses an outcome / enters a test card                             |
        |--------------------------------------------------------------------->|
        |                            |<------------- signed result ------------|
        |                            | verify signature, settle once:          |
        |                            | payment paid + downpayment received     |
        |<--- result page, receipt --| message to customer, badge for staff    |
```

Points that matter:

1. **Who may start a payment.** Only the holder of the booking's secure link session, for an agreement that is `reserved`, with the downpayment `due` and the hold still running.
2. **Amount.** Read from `downpayment_amount` on the server. The browser sends no amount.
3. **One at a time.** A second "Pay now" while one is pending returns the customer to the same checkout. Pending payments last 15 minutes (`PAYMENT_PENDING_MINUTES`) or until the hold ends, whichever is sooner.
4. **Settling is one transaction and idempotent.** The payment row and `downpayment_status` change together or not at all. A repeated result for the same receipt number does nothing.
5. **Hold.** The expiry job leaves a reservation alone while a payment is pending, and for good once the downpayment is received, as today.
6. **After success.** The downpayment is `received` with no staff check needed, because the gateway's signed result is the check. Front desk still presses Confirm (a chauffeur booking needs its driver first). The confirmation message keeps the agreed wording: "Downpayment received: ₱3,000. Balance of ₱7,000 is due at pickup."
7. **After failure.** The row is closed with its reason. The customer may try again, with the same or another method, up to five attempts per booking.

### The checkout screens

| Method | Screen | How the outcome is chosen |
|---|---|---|
| GCash, Maya, GrabPay | A made-up wallet ("Juan Dela Cruz, balance ₱10,000") and the amount | Buttons: Approve, Insufficient balance, Cancel, Let it expire |
| Card | Card number, expiry, CVV, then a "bank verification" step | The test number decides: `4242 4242 4242 4242` approved, `4000 0000 0000 0002` declined, `4000 0000 0000 9995` insufficient funds, `4000 0000 0000 0069` expired card. The verification step can also be failed. |
| Online banking | Choose BPI, BDO or UnionBank, then a made-up account | Buttons: Approve, Cancel, Let it expire |

---

## 7. Counter payments, including cash

The record-payment form on the agreement page replaces the GCash-only one:

- **Method**: any enabled method. **Reference**: required for everything except cash; each outside reference can be used once, as now.
- **Cash**: no reference to type. The system's receipt number is printed on the receipt page.
- **Who**: finance staff and system administrators, as today.
- A recorded payment cannot be edited or undone, as today.
- A printable receipt (receipt number, booking reference, method, amount, date, who received it) is available to staff, and to the customer from the booking link.

---

## 8. The balance

Today the balance is a number on a page. With `payments` in place it can be received properly:

- Balance = the agreement's total (base plus charges) less everything paid.
- Staff record it at the counter by any method, usually at pickup. It may be paid in parts.
- **Gate:** an agreement that took a downpayment cannot be marked `completed` while money is still owed. This sits next to the existing "settle the security deposit first" rule and is done by the same roles. Pickup is not blocked, so later charges (damage, extra days) do not trap a vehicle at the counter.
- Agreements from before the downpayment rule (`not_required`) are not affected.

Paying the balance online from the booking link reuses everything in section 6 and is left as an optional last step.

---

## 9. The "what if" demonstration

The reason for the simulation. Each row is something to show at the defense and a case in `bin/test-payments.php`.

| # | What if… | What the presenter does | What the system shows and records |
|---|---|---|---|
| 1 | the customer pays by e-wallet | Pay now, GCash, Approve | Payment `paid`; downpayment `received`; message sent; staff see "Confirm the reservation" |
| 2 | the customer pays by card | Test card 4242, pass verification | Same, with "Visa ending 4242" on the receipt and nothing else of the card kept |
| 3 | the bank declines the card | Test card ending 0002 | Payment `failed`, "Declined by the issuing bank"; booking still `due`; customer offered another method |
| 4 | the wallet has too little money | Maya, Insufficient balance | `failed` with that reason; retry allowed |
| 5 | the customer backs out | Cancel on the checkout | `cancelled`; nothing else changes |
| 6 | the customer walks away | Let it expire | `expired`; when the 24-hour hold ends the reservation is cancelled and the vehicle is free again |
| 7 | the result arrives twice, or the customer reloads | Reload the result page | Still one `paid` row; the database refuses a second paid downpayment |
| 8 | the customer tries to pay after the hold ended | Open an old booking link | Refused: the reservation was released |
| 9 | someone changes the amount in the browser | Edit the form | No effect; the amount is never read from the browser |
| 10 | a forged "paid" result is sent | Run the check in the test script | Rejected for a bad signature and written to `security_logs` |
| 11 | someone types a real card number | Any number not on the test list | Refused with "Use a test card"; nothing stored |
| 12 | the customer prefers cash | "Pay cash at the office", then finance records cash | Counter payment, `cash`, receipt number, no outside reference |
| 13 | a reference is reused at the counter | Record Maya twice with one reference | Second one refused |
| 14 | the balance is unpaid at the end | Try to complete the agreement | Refused until the balance is recorded |

The payments report for finance (totals by day and by method, with simulated payments marked) closes the demonstration.

---

## 10. From simulated to real, if it ever happens

For the "what would it take" question:

- Payment gateways in the Philippines such as PayMongo and Xendit offer e-wallets, cards and online banking through one integration. Not re-checked today.
- The work would be a new class beside `SimulatedGateway` (start a checkout through the gateway's API; verify its webhook signature), a public HTTPS address for the webhook, a registered business account with the gateway, and a refund process for payments that arrive after a hold has ended.
- Nothing in `PaymentService`, the table or the pages would need to change. Card data would be entered on the gateway's page and never touch this system.

---

## 11. Decisions for the owner

The plan assumes the recommended answer. Say so if any should go the other way.

| # | Decision | Recommended | Alternative |
|---|---|---|---|
| 1 | The screenshot-proof path that 019 prepared | Drop `payment_proofs`; the simulated checkout takes its place and the table count stays at 34 | Keep it as a seventh, manual method. One more table, an upload page and a review queue to build |
| 2 | After an online payment succeeds | Front desk still confirms | Confirm self-drive bookings automatically |
| 3 | The balance | Record it; block `completed` while money is owed | Record it with no block, or leave the balance out |
| 4 | Who records counter payments | Finance and administrators, as today | Also front desk, for cash at pickup |
| 5 | Methods | The six in section 3 | Add or remove any |

---

## 12. Build order

Each phase ends with something that can be shown and with its tests passing. Sizes are relative: S is a sitting, M is a day or two, L is several days.

| Phase | Contents | Size | Can be shown afterwards |
|---|---|---|---|
| 1. Table and counter payments | `020_payments.sql`; `PaymentRepository`, `PaymentService`; record-payment form with all methods and cash; Payments panel; wording made method-neutral; existing tests updated | L | Staff record a downpayment by cash, GCash, Maya or card; rows 12 and 13 |
| 2. Simulated checkout | Gateway interface, simulated gateway, checkout controller and pages, policy version 2 and acceptance, "Pay now" on the booking page, pending expiry | L | Rows 1 to 9 and 11 |
| 3. Messages and receipts | Payment-received message, receipt page, staff badges | S | A complete customer journey on a phone |
| 4. Balance and report | Balance payments, the `completed` gate, finance payments report | M | Row 14 and the report |
| 5. Proof and paperwork | `bin/test-payments.php` and its HTTP twin, forged-result check (row 10), README section, regenerated `schema.sql`, the database documentation updated for `payments` | M | Every suite green on a test database |

If time runs short before the defense, phases 1 and 2 alone meet the goal; 3 to 5 make it complete.

---

## 13. Risks

| Risk | Handling |
|---|---|
| The big role test (`bin/test-roles-http.php`) leans on the downpayment form and columns | Keep the `/rentals/downpayment` route and its field name working; update the suite in phase 1, not at the end |
| Triggers owned by a dropped account (error 1449) | Migrate the everyday database with the permanent migration account |
| The simulated checkout is taken for a real one | The rules in section 2; the `online_demo` channel on every row; the "demonstration site" notice already on the public pages |
| Another table for the teacher to question | Net change is zero: `payments` in, `payment_proofs` out, three duplicate columns removed |
| Scope creep toward a public booking form | Not part of this plan. Bookings are still made by front desk; the customer pays from the secure link |

---

## 14. What was built (2026-10-02)

### Where it differs from the plan

| Plan | Built | Why |
|---|---|---|
| Drop `payment_proofs`; table count stays the same (decision 1) | `payment_proofs` is kept. `payments` is one more table: 35 in the database, counting the migration ledger | Between the plan and the build, the owner asked for the public booking form and the screenshot proof, and they were built. Dropping the table would have removed a working feature. A verified proof now writes a `payments` row that names it in `proof_id`, so both tables are in use |
| Channels `counter` and `online_demo` | `staff` and `online_demo` | A proof verified by finance is recorded by staff but is not a counter payment |
| The customer pays from the secure link only | Also straight after booking at `/book`, or after "Find my booking" | Those ways into the customer's page existed by then |
| Up to five online attempts per booking | Ten starts per booking per hour | A presenter showing every outcome on one booking would hit five |
| `app/Controllers/CheckoutController.php`, `customer/pay.php` | `DemoCheckoutController.php`; the choice of method is on the booking page itself | One page fewer for the customer. `PaymentController` already existed for the proofs and was extended |
| The expiry job waits for a pending payment | A checkout never outlives the hold, so the two end together | Simpler, and a late "paid" result is turned into "timed out" rather than a payment on a released vehicle |
| Paying the balance online (optional) | Not built | The owner's rule is that the balance is paid in person at pickup |

Decisions 2 to 5 went the recommended way: front desk still confirms; an agreement that took a downpayment cannot be completed while money is owed; finance and administrators record counter payments; the six methods.

### Files

| Area | Files |
|---|---|
| Database | `database/migrations/020_payments.sql`, `database/schema.sql` |
| Settings | `config/payments.php` (methods, banks, test cards); `PAYMENT_GATEWAY`, `PAYMENT_WEBHOOK_SECRET`, `PAYMENT_PENDING_MINUTES` in `.env` |
| Rules | `app/Services/PaymentService.php` (online checkout, balance), `app/Services/RentalService.php` (downpayment at the counter and from a proof, the completion gate), `app/Repositories/PaymentRepository.php`, `app/Support/PaymentMethods.php` |
| Gateway | `app/Services/Payments/PaymentGateway.php`, `SimulatedGateway.php`, `PaymentGatewayFactory.php` |
| Customer pages | `app/Controllers/CustomerBookingController.php`, `DemoCheckoutController.php`; `app/Views/customer/booking.php`, `checkout-demo.php`, `payment-result.php` |
| Staff pages | `app/Controllers/Rentals/AgreementController.php`, `PaymentController.php`; `app/Views/rentals/agreement-detail.php`, `payments.php`, `payment-receipt.php` |
| Checks | `bin/test-payments.php`, `bin/test-downpayment.php`, the "Paying online" and balance steps in `bin/test-roles-http.php` |

### Routes

| Route | Who | What |
|---|---|---|
| `POST /customer/booking/pay` | The customer whose booking is open | Starts a payment and goes to the checkout |
| `GET`, `POST /pay/demo` | The same customer | The simulated checkout |
| `GET /customer/booking/payment?receipt=` | The same customer | Receipt, or why the payment was not completed |
| `POST /webhooks/payments` | Anyone; only a correctly signed message does anything | Where a gateway's result arrives |
| `POST /rentals/downpayment` | Finance, administrator | Records a counter downpayment, now with a method |
| `POST /rentals/payment` | Finance, administrator | Records a payment toward the balance |
| `GET /payments/receipt?receipt=` | Finance, administrator, auditor | Printable receipt |

### How to show it

1. Start the demo as usual (`bin/demo-online.ps1`, or the local server). `PAYMENT_GATEWAY=simulated` is already in `.env`.
2. Book a vehicle at `/book`, or make a booking as front desk and open the customer's link.
3. On the booking page choose a method and press **Continue to payment**. Walk through the rows of section 9: decline a card (`4000 0000 0000 0002`), try a wallet with not enough balance, cancel one, then approve one.
4. As front desk, open the agreement: the attempts are listed, the reservation shows "Paid, to confirm". Confirm it.
5. As finance, open **Payments** for the totals by method, then record the balance in cash on the agreement and complete it after return.
6. For rows 7, 9 and 10 (repeat, changed amount, forged result) run `php bin/test-payments.php` against a test database and read the PASS lines.

### Test results on 2026-10-02

Run against separate review databases, never the everyday one: `test-payments` 58 passed; `test-downpayment` 32 passed; `test-roles-http` 1,372 passed, including the whole checkout driven through the real pages; `test-m4`, `m5-reconciliation`, `m6`, `m6-db-guards`, `m7`, `m8`, their HTTP suites, `test-rate-counters`, `test-status-logs`, `test-stop-idempotency` and `test-telegram` all passed. `database/schema.sql` was compared with a database built by running migrations 001 to 020: every column, constraint, index, the payments triggers, the policy versions and the migration checksums match.

### Still open

- The database documentation `.docx` does not list `payments` (it was already behind after the consolidation).
- The office's GCash number in `config/site.php` is still empty, so the proof-upload instructions tell the customer to call for it.
- A real gateway is not connected. Section 10 says what that would take.
