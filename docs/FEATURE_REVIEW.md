# Feature Review: Database, Back End, Front End and Role Access

**Reviewed:** 2026-10-01
**Scope:** every feature in [MASTER_FEATURE_BUILD_PLAN.md](MASTER_FEATURE_BUILD_PLAN.md) that exists in this checkout: M1 accounts and sessions, M2 fleet, M3 customers, M4 drivers, M5 rentals, M6 chauffeur rentals, M7 damage, M8 maintenance, SMS notifications and customer secure links. M9 and M10 are not built, as the plan states.
**Method:** a clean MySQL 8 database, freshly migrated (001 to 011) and seeded, with a live server on top. Nothing below was checked against the everyday local database.

## Result

Everything passes after five fixes made during the review (listed below).

| What was run | Checks | Result |
|---|---|---|
| Module database suites: `test-m4`, `test-m5-reconciliation`, `test-m6`, `test-m6-db-guards`, `test-m7`, `test-m8` | 90 | All pass |
| SMS STOP handling suite: `test-stop-idempotency` | its own verdict | Pass |
| Existing page-level suites: `test-m4-http`, `test-m7-http`, `test-m8-http` | 53 | All pass |
| New eight-role end-to-end check: `test-roles-http` | 1,464 | All pass |
| PHP warnings and notices raised by any page during the role check | — | None |
| Scheduled jobs run once each (`rentals-expire`, `rentals-reminders`, `consume-stop-events`, `sessions-sweep`, `notifications-worker`, `maintenance-due`) | 6 | All exit cleanly |
| Syntax check of every PHP file | 129 files | All pass |

## What the eight-role check does

It signs in as one account per role and works the system the way staff would, through the pages themselves:

1. **A full flow through real forms.** For each step it loads the page, confirms the form on it carries every field the server reads, and submits it. The flow covers: creating an account and its forced password change, ending a session, account lockout and unlock; adding, retiring and removing a location; registering a vehicle, odometer reading, photo upload; adding a driver and revealing the licence; adding a customer and revealing the phone; a chauffeur reservation, driver assignment, confirmation, pre-rental inspection, pickup, return, damage with photo, liability decision, damage charge, fee, deposit, completion; a cancelled reservation; a maintenance schedule, service start, costs, photo, completion; the SMS history; and the customer's secure link, from the queued message to the booking page.
2. **Every page against every role.** 29 pages × 8 roles, plus a signed-out visitor: allowed roles get the page, everyone else is refused.
3. **Every action against every role that must be refused.** 61 actions, 367 refusals, plus a signed-out visitor for each.
4. **Menus.** Each role's sidebar is exactly its own list.
5. **Links.** Every link shown to each role is followed (about 590 in the last run); none leads to a refused, missing or broken page.

## Who can open what

| Area | System admin | Fleet manager | Front desk | Driver coordinator | Mechanic | Finance | Auditor | Support |
|---|---|---|---|---|---|---|---|---|
| Workspace (dashboard) | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Agreements: list and detail | ✓ | ✓ | ✓ | ✓ schedule and driver only | – | ✓ | ✓ read only | – |
| New reservation, confirm, cancel, no-show, send booking link | ✓ | – | ✓ | – | – | – | – | – |
| Record pickup and return | ✓ | ✓ | ✓ | – | – | – | – | – |
| Assign, change or remove the driver | ✓ | – | ✓ | ✓ | – | – | – | – |
| Charges, deposit, complete the agreement | ✓ | – | – | – | – | ✓ | – | – |
| Damage: record an inspection | – | ✓ | ✓ | – | – | – | – | – |
| Damage: liability decision | ✓ | ✓ | – | – | – | – | – | – |
| Damage: post the charge | – | – | – | – | – | ✓ | – | – |
| Damage: read reports and photos | ✓ | ✓ | ✓ | – | – | ✓ | ✓ | – |
| Customers | ✓ | – | ✓ | – | – | – | – | – |
| Vehicles and locations | ✓ | ✓ | – | – | – | – | – | – |
| Remove a retired location | ✓ | – | – | – | – | – | – | – |
| Drivers: read (personal details restricted) | ✓ | ✓ | – | ✓ | – | – | – | – |
| Drivers: add, edit, reveal, remove | ✓ | ✓ | – | – | – | – | – | – |
| Maintenance: read | ✓ | ✓ | – | – | ✓ | – | ✓ | – |
| Maintenance: start, costs, photos, complete, cancel | ✓ | ✓ | – | – | ✓ | – | – | – |
| Maintenance: schedules and status reviews | ✓ | ✓ | – | – | – | – | – | – |
| SMS notifications | ✓ | ✓ | – | – | – | – | – | ✓ |
| Staff accounts and sessions | ✓ | – | – | – | – | – | – | – |

Two rows are deliberate, per the M7 contract, and not oversights: a system admin does not record damage inspections, and only finance posts a damage charge.

## Problems found and fixed

| # | Problem | Effect before the fix | Fix |
|---|---|---|---|
| 1 | The driver coordinator could not open any agreement page. M6 gives that role driver assignment, and the assignment form is on the agreement page. | The role's main job was unreachable from the screen; after assigning by any other means, the redirect landed on a refusal. | `AgreementController` now lets the coordinator open the list and the detail page. The views show that role the booking, schedule and driver panel only; charges, deposit, damage and amounts are hidden. |
| 2 | The menu and dashboard offered "Vehicles" to the front desk, which the vehicle pages refuse. | A menu item and a dashboard card that led to "You can't open this page". | `Navigation` and the dashboard now match the vehicle pages. Front desk still sees the available-vehicle count, without a link. |
| 3 | The Locations page was handed the wrong list: the render helper pre-set `$locations` and then ignored the full list passed in. | Status and creation date were blank, retired locations never appeared, and the Retire and Remove buttons were never shown, so both features were unreachable. | `VehicleController::render` uses the list it is given. The role check now covers adding, retiring and removing a location. |
| 4 | Maintenance history linked "Vehicle record" for mechanics and auditors, who cannot open vehicle pages. | A button that led to a refusal. | Shown only to system admin and fleet manager. |
| 5 | The dashboard's "Needs attention" list showed items a role cannot act on (for example "Reservations to confirm" to an auditor). | Misleading: the panel says the items are waiting on the reader's role. | Each item is shown only to the roles that can perform that step. |

Found earlier the same day, before this review, and already fixed: the customer controller was never created in `public/index.php` (every customer page failed); one undecryptable driver row crashed the whole drivers page; and the everyday local database was missing migrations 010 and 011.

## Things that work as designed but are worth knowing

- **No SMS is sent.** No provider key is configured, so queued messages fail when the worker runs. Bookings still work; staff can re-send a link once a provider is set up.
- **Customer secure links are tied to `APP_BASE_URL`.** The link is only accepted when the page is opened at that exact address. `bin/demo-online.ps1` sets it to the tunnel address for the presentation.
- **Sign-in limits.** 20 attempts a minute per visitor address and 5 a minute per email; five wrong passwords in a row lock the account until an admin unlocks it.
- **PHP errors are not being logged on this computer.** PHP is set to write to `C:\xampp\php\logs\php_error_log`, and that folder does not exist. Creating the folder turns logging on.

## Not covered

- Real SMS delivery and provider callbacks, which need a provider account.
- Behaviour that only runs in a browser (confirmation dialogs, Reveal buttons, the reservation form's live summary, phone layout). These were checked by hand earlier in the redesign, not by this automated review.
- Load: the review used one visitor at a time.

## Running it again

```powershell
# a migrated, seeded, otherwise empty database and a server pointed at it, then:
$env:ROLES_HTTP_BASE_URL = 'http://127.0.0.1:18090'
$env:ROLES_HTTP_TEST_PASSWORD = '<any 14+ character password>'
php bin/test-roles-http.php
```

`.local-acceptance-setup.ps1` builds such a database for the existing suites. The role check creates its own records and never deletes them, so use a throwaway database.
