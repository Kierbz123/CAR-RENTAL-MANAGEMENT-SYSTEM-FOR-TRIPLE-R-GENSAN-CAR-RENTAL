# Why the tables are separate

All 34 tables of the Triple R database, group by group: what each table holds, how it is written, and what would go wrong if the group were merged into one table.

## Staff accounts and sign-in

* `users` — one row per account, staff or driver: email, password hash, role, whether it is locked. Edited now and then.
* `sessions` — one row per sign-in on a device. A person can be signed in on a computer and a phone at once. The row is touched on every page they open and ends when they sign out or it expires. Only a hash of the session key is stored.
* `security_logs` — every security event: sign-in succeeded or failed, sign-out, password changed or reset, account created, and every time a customer's secure link is opened or refused. Append-only.

Merge them and the account row would be rewritten on every page view, a person could be signed in on only one device, and the sign-in history could not be locked while the account stays editable. A failed sign-in with an email that has no account would also have no row to go into.

## Secure customer links

* `booking_access_tokens` — the private links sent to a customer to manage a booking, and to the phone that shares a rented vehicle's location. Each has a purpose, an expiry and a used date. Only a hash of the link is stored. A booking can have several, because a link can be sent again.

Put them on `rental_agreements` and a booking could hold only one link, with no record of the earlier ones. Put them in `sessions` and they would need a user account, which a customer does not have. Each use of a link is recorded in `security_logs`, next to the sign-ins.

## Messages

* `notifications` — the outgoing queue: every SMS or Telegram message the system has to send. The sending worker updates the row many times: queued, sending, sent or failed, how many tries, when to try again. The phone number is stored encrypted.
* `inbound_sms_events` — the incoming side: every text received from the SMS provider, such as a customer replying STOP. Keyed by the provider's own message number, so the same text is never counted twice. Append-only.

Merge them and a table that is rewritten constantly by the worker would also have to be the locked record of a customer opting out. The two also share almost no columns: one has retries and schedules, the other has what the provider delivered.

## Histories

* `status_logs` — every status change of a vehicle, a driver, a rental or a rental's deposit: from what, to what, who did it, when and why. Append-only. This one is already a merge: it used to be five separate tables.
* `record_lifecycle_logs` — who removed or restored a customer or a driver, when and why. Append-only.
* `audit_seals` — a fingerprint of each history table at a point in time: how many rows it had and a hash over all of them. A later check recomputes it, so a history row that was changed or removed behind the system's back shows up. Append-only.

Put the history on the record itself and each change would overwrite the last: the vehicle row only has room for its status now. Merge `record_lifecycle_logs` into `status_logs` and every status row would carry removal columns it never uses, for customers who have no status at all. `audit_seals` has to stand outside the tables it seals, or writing a seal would change the very thing being sealed.

## Photos

* `photos` — every stored photo, of a vehicle or of a damage report: where the file is, its size and type, who uploaded it. Many per vehicle and per report. A vehicle's photos can be reordered or removed; a damage report's photos are locked. This one is also already a merge, of three photo tables.

Put them on `vehicles` or `damage_reports` and you are back to `photo1`, `photo2` columns with a fixed limit. A damage report is locked evidence, so its photos could not be added by editing it either.

## Counters

* `rate_counters` — small counters that limit how often something may happen: sign-in attempts from one address, messages to one phone in a day, secure links for one booking. Rows are updated in place all the time and stop mattering once their time window has passed. Three counter tables were merged into this one.

Put the counters on `users`, `customers` or `rental_agreements` and every sign-in attempt would rewrite an important permanent row. Many counters also belong to something that has no row anywhere, such as an internet address or an email nobody has registered.

## System

* `schema_migrations` — which changes to the database structure have been applied, with a checksum of each file. Written only when the database is upgraded.

It describes the database itself, not a customer, a vehicle or a booking, so there is no business table it could belong to.

---

## Customers

* `customers` — one row per customer. Edited now and then (name, blacklist, removal).
* `customer_contacts` — a customer can have several phone numbers and emails. Each is stored encrypted, with a fingerprint so staff can find a customer by number without decrypting anything.
* `customer_identity_documents` — a customer can have several IDs. Each is encrypted and has its own expiry date.
* `customer_identity_document_audit_logs` — written by the database itself every time an ID is added or changed. Append-only: it can never be edited or deleted.
* `customer_notes` — staff notes, many per customer. Append-only.
* `customer_telegram_links` — the customer's Telegram connection and its history. An old connection is marked revoked, never deleted, and only one can be active at a time.

Merge them and one customer row would need `phone1`, `phone2`, `id1`, `id2`, `note1`… columns, with a fixed limit and mostly empty cells. Worse, the customer row must stay editable while notes and the ID audit must be locked against edits; one table cannot be both.

## Damage

* `damage_reports` — what was seen on the vehicle at an inspection (where, what type, how severe). It is evidence, so it is append-only.
* `damage_liability_decisions` — the judgement made afterwards: is the customer liable, and for how much. A decision can be replaced by a later one, and the old one is kept.

Merge them and changing a decision would mean editing the inspection report, which is the evidence. Kept apart, one report can carry several decisions over time and the original observation stays untouched.

## Drivers

* `drivers` — one row per driver: name, licence (encrypted), licence expiry, status.
* `driver_contacts` — a driver can have several phone numbers, each encrypted, one marked primary.

Merge them and you are back to `phone1`, `phone2` columns. The split also matches the access rule: front desk may reveal a driver's phone number but not the licence or address, and those live in different tables.

## Payments

* `payment_proofs` — what the customer claims: the uploaded screenshot and reference number. Staff approve or reject it; the submitted details cannot be edited afterwards. A booking can have several (rejected, then sent again).
* `payments` — the money itself, for every method: amount, receipt number, status. The amount and method are locked once recorded.

Merge them and a cash payment would carry empty file columns, while a rejected proof would sit in the money table as if it were a payment. "The customer says they paid" and "we received the money" are different facts.

## Rentals

* `rental_agreements` — one row per booking: who, which vehicle, dates, rates. Its status changes as the rental moves from reserved to returned.
* `rental_charges` — the bill: every fee, damage charge and reversal, many per booking. Append-only: a mistake is corrected by adding a reversing line, never by editing.

Merge them and one booking could hold only a fixed number of charges, and the status changes would be rewriting the same row that holds the money records. A ledger has to be add-only; a booking has to be updatable.

## Rules

* `rules_versions` — the text of the rental rules, one row per published version. Never edited; a change is a new version. A handful of rows.
* `rules_acceptances` — who accepted or withdrew consent, to which version, when, from which address. Append-only, one row per event.

Merge them and the full rules text would be copied into every acceptance. Kept apart, each acceptance points at the exact version the customer agreed to, which is what proves consent.

## Telegram

* `telegram_link_codes` — one-time codes staff issue so a customer can connect. Stored as a hash, expire in minutes, used once.
* `telegram_updates` — every message the bot receives, keyed by Telegram's own message number so the same one is never processed twice. The message text is deliberately not stored.

Merge them and there is no common key: a code belongs to one customer and one staff member, while an update can come from any stranger who messages the bot.

## Vehicles

* `vehicles` — one row per car. Written rarely (register a car, change its status or rate).
* `vehicle_locations` — the list of branches and garages a car can be kept at. A few rows, shared by many vehicles.
* `vehicle_mileage_logs` — every odometer reading. Append-only: a wrong reading is corrected by a new row that points at it.
* `vehicle_positions` — live GPS: one row per vehicle, overwritten by every new position from the phone. It is not a history, so it never grows.

Merge them and every GPS update would rewrite the row that holds the car's permanent details, the location name would be retyped on every car, and the mileage history could not be locked.
