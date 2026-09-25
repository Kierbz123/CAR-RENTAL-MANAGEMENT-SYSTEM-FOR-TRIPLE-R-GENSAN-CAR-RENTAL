# M3 — Customer Management

## Trace

- `database/migrations/005_customers.sql` creates `customers`, encrypted `customer_contacts`, `customer_identity_documents`, append-only `customer_notes`, and append-only `customer_identity_document_audit_logs`.
- `app/Repositories/CustomerRepository.php` owns customer, contact, document, note, and rental-history queries. `eligibleForBooking()` is the canonical `is_blacklisted = 0 AND deleted_at IS NULL` scope.
- `app/Services/CustomerPiiCipher.php` encrypts values and computes keyed fingerprints. `CustomerService.php` validates and performs customer, contact, document, blacklist, note, and soft-delete operations.
- `app/Controllers/Customers/CustomerController.php` serves the staff UI and POST actions. Routes are wired in `public/index.php`; customer management is restricted to `front_desk` and `system_admin`.
- Customer list/form/detail views use `public/assets/js/customers.js` and `public/assets/css/app.css`; the reveal endpoint decrypts values only for the same two roles.

## Data and privacy contract

`customers` stores type (`walk_in`, `online`, `corporate`, `repeat`, `referral`), full name, optional company/referral source, blacklist state/reason/actor/time, timestamps, and `deleted_at`. Service validation and database CHECK constraints both require `company_name` for corporate customers and `referral_source` for referral customers.

Phone/email contacts and identity-document numbers are encrypted at rest. Identity documents are in one table so customers can hold multiple documents without schema changes. Canonical document types include `ph_driver_license`, `passport`, `national_id`, and `other_government_id`. Driver-license aliases normalize to `ph_driver_license` wherever entered. Fingerprints are HMAC-SHA256 over canonical type plus normalized identifier using a separate HKDF-derived key. A single unique index across all customer document rows prevents duplicates across active, blacklisted, and soft-deleted customers; formatting differences normalize before indexing. There is no fuzzy matching for mistyped numbers.

`CUSTOMER_PII_KEY` is a dedicated base64-encoded 32-byte secret, separate from `APP_KEY` and `SMS_CIPHER_KEY`. HKDF derives independent AES and fingerprint subkeys. AES-256-GCM generates a fresh random 12-byte nonce for every encryption; the binary envelope stores version byte, nonce, 16-byte authentication tag, and ciphertext. The type-bound context is authenticated as AAD. Keep the key backed up securely; losing it makes contact and identity values unrecoverable. Key rotation requires re-encrypting every contact/document and recomputing fingerprints under the new key before removing the old key.

Only `front_desk` and `system_admin` can call the decryption endpoint. Values are masked by default, and reveal actions POST with CSRF protection and return no-store JSON. `auditor` and all other roles have no reveal access. Automated application and identity-document audit logs contain no raw contact or document values. Staff notes are free text; staff must not use them to store identity document numbers.

## Append-only audit behavior

`customer_notes` rejects UPDATE and DELETE through database triggers. `customer_identity_documents` is mutable master data and has no application DELETE path. Every insert/update is audited by database triggers into **`customer_identity_document_audit_logs`**, with actor, customer, document, document type, old/new fingerprints, operation, and timestamp. A connection actor variable must be set by the authenticated service transaction; the triggers reject document writes without it. The audit table rejects UPDATE and DELETE. A fingerprint conflict while correcting a typo rolls back the correction; staff must correct the conflicting record rather than bypass uniqueness.

This M3 audit target is separate from M1's append-only `security_logs`. They serve distinct event contracts and are not generic replacements for one another.

## Locking, blacklist, and booking integration

Blacklist and soft-delete actions lock the customer row in a transaction. Blacklisting is allowed during an open rental; it blocks future bookings without changing the existing agreement. Blacklist/unblacklist requires a reason and appends a `customer_notes` event.

Soft-delete is refused when an agreement has status `reserved`, `confirmed`, `active`, or `returned`. Before M5 migration 007 creates `rental_agreements`, the guard sees no agreement table and permits deletion because agreements cannot yet exist. After M5, it checks those statuses. M5 rental creation must lock the same customer row, then verify `is_blacklisted = 0 AND deleted_at IS NULL` before inserting, serializing booking creation against blacklist/delete.

All new-booking selectors in M5 must call `CustomerRepository::eligibleForBooking()` and repeat the eligibility check under the customer row lock. This keeps blacklisted and soft-deleted records out of new selection while preserving historical joins.

## Routes and run instructions

- `GET /customers`, `/customers/new`, `/customers/edit?customer_id=…`, `/customers/detail?customer_id=…`
- `POST /customers/create`, `/customers/update`, `/customers/contacts/{add,update,remove}`, `/customers/documents/{add,update}`, `/customers/notes/add`, `/customers/{blacklist,unblacklist,delete,reveal}`
- Apply `005_customers.sql` with `php bin/migrate.php` after migrations 001–004. Generate `CUSTOMER_PII_KEY` with `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` and preserve it in the secret store before first customer data is written.

## Acceptance checklist and requirement map

- FR-03: all five types, contacts, blacklisting, customer notes, details, and guarded soft-delete.
- BR-5: `eligibleForBooking()` filters blacklisted and soft-deleted customers; M5 selectors and create transaction use it.
- BR-12: `customer_notes` append-only triggers; identity document writes auditable through the distinct `customer_identity_document_audit_logs`.
- Verify same driver's-license number submitted under a license alias and a government-ID alias maps to `ph_driver_license` and is blocked by the shared unique fingerprint.
- Verify an encrypted identity value decrypts only for `front_desk`/`system_admin`, uses a fresh nonce per write, and stays masked to other roles.
- Verify an identity correction changes the row and appends an audit row; UPDATE/DELETE against either append-only log is rejected.
- Verify blacklist is allowed with an active agreement, future eligibility is false, and soft-delete is blocked for the four defined open statuses.
- Verify M5 rental creation and M3 blacklist/delete serialize by customer-row lock once migration 007 exists.

## M11 coverage carry-forward

| Requirement / scope | Coverage and disposition |
| --- | --- |
| BR-12 append-only security and customer-document evidence | M1 `security_logs` and M3 `customer_identity_document_audit_logs` exist as separate append-only logs with distinct schemas and purposes. They are not conflated with each other or with a generic `audit_logs` table. If the source requirements call for a generic audit log, M11 records a separate module decision rather than treating either table as its implementation. |
| GPS tracking | Deferred from this build; M6 adds no GPS behavior or schema. |
| GCash verification | Deferred from this build. |
