# UI Layout & Design Master Plan — Triple R Gensan Car Rental

**Written:** 2026-10-01
**Scope:** the public landing page, the staff sign-in and other entry pages, and every staff and customer page in `app/Views/` (29 view files plus `public/triple-r-landing.html`).
**Out of scope:** new business features, database changes, and the unbuilt M9/M10 modules. This plan changes how pages look and are laid out, not what the system does.

**Evidence used:** source review of all views, `public/assets/css/app.source.css`, `app.legacy.css`, `app-shell.js`, `public/index.php` and `app/Http/Response.php`; the landing and sign-in pages rendered locally at desktop and 375 px phone widths; and the saved staff-shell screenshots in `.runtime/`. Staff pages behind sign-in were reviewed from source and those saved screenshots, not from a fresh signed-in session.

---

## Status (updated 2026-10-01)

Phases 0 to 5 are implemented, and most of Phase 6. Sections 1 to 5 below are the plan as written before the work started; they are kept as the record of what was found and why.

**Done**

- Phase 0: `package.json` build scripts; `app.legacy.css` retired; tokens completed; shared layouts (`staff`, `entry`, `public`) through `TripleR\Support\View`; sidebar rendered on the server from `Support\Navigation`; `StatusPresenter`, `Format`, `Pager`, `Icon` helpers; HTML error pages for 4xx and 5xx.
- Phase 1: landing page rebuilt as `app/Views/public/landing.php` with `landing.css` and `landing.js`. The page is about 15 KB of HTML plus 35 KB of CSS and script; three.js is a separate cached file fetched after load.
- Phase 2: sign-in, change password, secure link and customer booking pages share the `entry` layout.
- Phase 3: grouped sidebar with icons, breadcrumb bar, phone drawer, and a dashboard with read-only counts.
- Phase 4: all list pages use the toolbar, table, empty-state, stacked-card and paging pattern.
- Phase 5: all detail and form pages use the split layout, fact lists, timelines and sectioned forms. The agreement page has the lifecycle stepper, cost summary and a single "Next step" panel.
- Phase 6: print styles; skip links, landmarks and focus handling; Content Security Policy tightened to `script-src 'self'; style-src 'self'`; README updated.

**Owner decisions applied**

| Question | Decision |
|---|---|
| Business details | Fictional placeholders, all in `config/site.php` and marked `DEMO-PLACEHOLDER`. "Book now" is an in-page link to the contact section. |
| Photos | Stylised SVG illustrations in `public/assets/img/landing/` (four vehicle classes and a chauffeur), captioned as illustrations. |
| Fleet section | Hand-written list in `config/site.php`. No public vehicle endpoint. |
| Brand colours | Teal ink, rust and amber kept; the landing page moved to them. |
| Dashboard counts | Read-only queries in `DashboardRepository`, used by `StaffHomeController`. |

**Differences from the plan**

- Fonts are not self-hosted yet. Adding Italiana and Outfit means downloading font files, which needs the owner's go-ahead. Until then the display font is the system serif stack (`--font-display`), and it is a one-line change once the files are in `public/assets/fonts/`.
- "Sessions" is not a sidebar item, because that page needs a staff account to be chosen first. It is reached from Staff accounts, and the sidebar keeps Staff accounts highlighted there.
- Cancel and no-show use an expanding panel (which works without scripts) followed by the confirmation dialog, instead of a dialog that collects the reason.
- Reference screenshots were not saved under `docs/design/`.
- Dark mode for staff pages remains deferred.

**Not verified, and why**

- The HTTP acceptance scripts (`bin/test-m4-http.php`, `test-m7-http.php`, `test-m8-http.php`) were not run: they need the isolated acceptance databases created by `.local-acceptance-setup.ps1`. The page text those scripts look for ("Restricted", "Damage inspections", "Liability decision history", "Complete service", "Save costs", "Maintenance") and the `name="_csrf" value="..."` pattern were checked by hand and are unchanged.
- The agreement detail, damage report, maintenance and driver pages were checked with sample data, not live data, for the two reasons in the next list.

**Problems found in the existing system during the work**

1. `public/index.php` never created the customer controller, so every `/customers` page failed. Fixed (one line).
2. The local database in `.env` has migrations 001 to 009 only. Without 010 and 011, the agreement detail page and every maintenance page fail. Run `php bin/migrate.php` with the migration credentials (see README, "First run").
3. Driver rows in the local database are encrypted in a format the current `DriverPiiCipher` rejects, so `/fleet/drivers` fails there. Re-seed the drivers or restore the matching `DRIVER_PII_KEY`.

**Files removed** (all recoverable from the Git ref `refs/backup/pre-ui-redesign`): `public/triple-r-landing.html`, `public/assets/css/app.legacy.css`, `public/assets/js/gsap.min.js`, `public/assets/js/vendor/ScrollTrigger.min.js`.

---

## 1. Where the design stands today

A redesign is already half-started. `app.source.css` defines a good token set (colors, spacing, radius, shadows, status colors), a sidebar shell, badges, toasts and a confirmation dialog. The work below finishes that job rather than starting over.

### What already works

- One token set and one set of status colors (`--status-success`, `--status-warning`, and so on) with checked contrast.
- A role-aware sidebar, a confirmation dialog for risky actions, toasts, visible keyboard focus and reduced-motion support.
- A distinctive landing hero (a 3D wheel scene with an animated headline) that falls back to a still image when WebGL is unavailable.

### Problems found

| # | Problem | Where | Effect |
|---|---|---|---|
| 1 | The landing page loads the staff shell script. `Response::html()` injects `gsap.min.js` and `app-shell.js` into any page containing `class="topbar"`, and the landing header uses that class. | `app/Http/Response.php:20`, `public/triple-r-landing.html:224` | Every public visitor triggers a failed `401` call to `/api/staff/navigation`. |
| 2 | The landing page is one 1.3 MB HTML file with three.js pasted inline, sent with `Cache-Control: no-store`. | `public/triple-r-landing.html` | The full 1.3 MB is downloaded again on every visit. |
| 3 | The landing fonts never load. The CSS names "Italiana" and "Outfit", but there is no `@font-face` and the Content Security Policy blocks outside font hosts. | landing `<style>`, `Response::send()` | Headlines fall back to Georgia; the intended look is not what visitors see. |
| 4 | The landing page has four slogans and nothing else: no vehicles, no rates, no requirements, no location, no phone number. The "Book Now" button goes to a placeholder WhatsApp number, and a "demo image placeholder" label is visible. | landing markup | A visitor cannot find a car, a price, or a way to reach the business. |
| 5 | The landing layout breaks between phone and wide desktop. At about 800 px the word "Complicated" splits mid-word, body copy runs underneath the wheel, and two links are cut off at the left edge. | landing CSS | Tablet and small-laptop visitors see overlapping text. |
| 6 | The landing page hides the scrollbar, replaces the mouse cursor, and stretches four slides over nine screen-heights of scrolling. | landing CSS and script | Slow to navigate; the staff link sits at the very bottom. |
| 7 | Two unrelated brand identities. The landing is black and gold with a serif "R" in a circle; the staff side is teal and rust with a three-slash mark (which is also the favicon). | landing vs `app.source.css` | The sign-in page does not look like it belongs to the site that linked to it. |
| 8 | There is no shared page layout. Each of the 29 views repeats its own `<head>` and top bar; there are 10 different top-bar link sets; 23 views omit the favicon link and rely on a string replacement to add it. | `app/Views/**` | Every shell change must be made 29 times. |
| 9 | The sidebar is built in the browser after a network call. Until it arrives, the old top bar shows, then the page jumps. If the script fails, most navigation is missing. | `public/assets/js/app-shell.js:78` | Layout shift on every page load. |
| 10 | The old stylesheet is still imported and still wins in places. The "Show" password button renders in the old blue (`rgb(23, 92, 211)`) instead of the brand green. About 120 lines of `.fleet-preview` / `.ops-*` rules are used by no view. | `app.legacy.css`, `app.source.css:54` | Stray off-brand colors; dead CSS. |
| 11 | Classes used in views but defined nowhere: `button-row`, `reason-action`, `magic-link-shell`, `magic-link-panel`. `visually-hidden` is only defined inside `.fleet-preview`, so the hidden "Actions" column header is visible. | views, CSS | The secure-link page and the agreement action row are unstyled. |
| 12 | Panels have no padding unless the content is wrapped in `.panel-body`, and the agreement detail page puts headings and paragraphs directly in `.panel`. | `rentals/agreement-detail.php` | Text sits against the panel edge on the busiest page in the system. |
| 13 | The staff home page is a list of text links. | `staff/home.php` | No at-a-glance view of today's work. |
| 14 | Stored values are printed as-is in 12 views (`no_show`, `out_of_service`, `self_drive`); money prints as `₱2000.00` with no thousands separator; timestamps print as raw UTC strings. | views | Harder to scan; inconsistent between pages. |
| 15 | "Sign out" in the sidebar is a solid rust button, the loudest element on every screen. | `app.source.css:109,134` | The eye is drawn to the least important action. |
| 16 | In the saved 1440 px screenshot, the vehicles page runs off the right edge (the filter's button and the last table column are cut off). | `.runtime/staff-shell-preview.png` | Needs re-checking in a live session; likely the legacy `.page-shell` width fighting the new one. |
| 17 | On phones, tables scroll sideways. The `data-label` attributes needed for stacked rows exist on only one table. | list views | Awkward on a phone at the rental counter or in the yard. |
| 18 | Unknown addresses and server errors return raw JSON (`{"error":"Not found"}`) to the browser. | `app/Http/Router.php:30`, `public/index.php:270` | A mistyped address shows a line of code-like text. |
| 19 | There is no `package.json`. `node_modules` holds the Tailwind CLI and GSAP, but the command that builds `app.css` is not recorded anywhere. | project root | Nobody else can rebuild the stylesheet. |
| 20 | Many views are written as single very long lines (`agreement-detail.php` is 40 lines, several over 2,000 characters). | views | Any layout edit is slow and error-prone. |

---

## 2. Design direction

**One brand, two moods.** The public pages are dark and cinematic; the staff workspace is light and calm. They share a mark, an accent and a type system so that moving from one to the other feels like one product.

| Element | Decision |
|---|---|
| Brand mark | The three-slash mark already used by the favicon and sidebar. It replaces the serif "R" in a circle on the landing page. |
| Brand accent | Amber (`#e3a45b` on dark, already the sidebar's active indicator). It links the landing, the sign-in page and the sidebar. |
| Action color | Rust (`--color-brand: #a74420`) stays the primary button color on light surfaces, where amber cannot carry white text at readable contrast. |
| Dark surface | One ink color for the landing background, the sign-in brand panel and the sidebar (`#20343a` family), replacing the landing's pure black. |
| Display type | Italiana for landing and sign-in headlines only, self-hosted as `woff2` in `public/assets/fonts/`. |
| Body type, public pages | Outfit, self-hosted. |
| Body type, staff pages | The existing system font stack with tabular numerals. Dense tables stay fast and familiar. |
| Motion | 120–320 ms, the existing easing token, always disabled under reduced-motion. No custom cursor. |
| Dark mode for staff pages | Deferred. It doubles the testing surface for little benefit to counter staff. |

**Layout rules for staff pages**

- Content column up to 80 rem beside a 16.25 rem sidebar; 24 px gutters; an 8 px spacing rhythm using the existing spacing tokens.
- Every page has the same header block: breadcrumb, title, one line of context, and at most one primary action on the right.
- One primary (rust) button per view. Everything else is secondary or a text link. Destructive actions are red and always confirmed.
- Three breakpoints: phone (up to 650 px), tablet (651–900 px, sidebar becomes a drawer), desktop (above 900 px).

---

## 3. Guardrails

These hold for every phase.

1. Do not change routes, form field names, hidden CSRF fields, HTTP methods, role checks or controller logic. This plan touches views, CSS, browser scripts and view helpers only. The two exceptions are named explicitly in Phases 0 and 3.
2. After each phase, the existing acceptance scripts must still pass: `bin/test-m4-http.php`, `bin/test-m7-http.php`, `bin/test-m8-http.php` and the database checks.
3. Every page must work with scripts disabled: navigation, forms and confirmations degrade to plain links and posts.
4. Text contrast of at least 4.5:1; touch targets of at least 44 px on phones; status is never shown by color alone.
5. Commit the current uncommitted redesign work as a baseline before starting. There are 16 modified files and 12 untracked entries today, including `app.source.css`, `app-shell.js` and the landing page itself. Keep `Triple Car Rental System Credentials.txt` and `.tmp-shell-review.cjs` out of that commit, since both hold sign-in details.

---

## 4. Phases

Phases run in order. Phase 0 is a short prerequisite; the landing page, sign-in page and system pages then follow in the order requested.

### Phase 0 — Foundations (small)

The goal is to make every later page change a one-file edit.

1. **Record the build.** Add `package.json` with `build:css` and `watch:css` scripts for the Tailwind CLI, and document them in `README.md`.
2. **Retire the legacy stylesheet.** Move the rules still in use from `app.legacy.css` into `app.source.css` under clear section headings, delete the unused `.fleet-preview` / `.ops-*` block, then remove the import. Define the four missing classes and a global `.visually-hidden`.
3. **Complete the tokens.** Add the shared dark-surface and amber tokens, a type scale (`--text-xs` through `--text-3xl`), and a z-index scale. Replace the hard-coded hex values scattered through the component rules with tokens.
4. **Add a shared layout.** Create `app/Views/layouts/staff.php`, `layouts/public.php` and `partials/` (head, sidebar, page header, flash message), plus a small `View::render($view, $data, $layout)` helper. This is the first permitted non-view change.
5. **Render the sidebar on the server.** Move the role-to-menu map out of the `/api/staff/navigation` closure in `public/index.php` into one class that both the layout and that endpoint read. Remove the script injection in `Response::html()`; the layout includes its own scripts. This fixes problems 1, 8 and 9.
6. **Add presentation helpers.** `app/Support/StatusPresenter.php` (stored value to label and badge tone, for vehicle, rental, deposit, driver and maintenance states) and `app/Support/Format.php` (`₱2,000.00`, Manila-time dates, kilometres).
7. **Add HTML error pages** for 403, 404 and 500 when the request accepts HTML; keep JSON for `/api/*`.

**Done when:** `app.legacy.css` is gone, a signed-in page loads with the sidebar already in the HTML and no layout jump, the landing page makes no call to `/api/staff/navigation`, and the acceptance scripts pass.

### Phase 1 — Landing page (medium)

1. **Split the file.** Convert `triple-r-landing.html` into `app/Views/public/landing.php` using `layouts/public.php`. Move three.js to `public/assets/js/vendor/three.min.js`, the scene script to `landing.js`, and the styles to `landing.css`. Static files are cached by the browser, so repeat visits no longer re-download 1.3 MB.
2. **Load the fonts** from `public/assets/fonts/` with `font-display: swap`.
3. **Change the page structure** from four scroll-locked slides to one full-height hero followed by ordinary scrolling sections:

   | Section | Content |
   |---|---|
   | Header | Mark, section links, "Staff sign in" text link, primary "Book now" button. Collapses to a menu button on phones. |
   | Hero | The 3D wheel scene, headline, one line of copy, primary and secondary buttons. |
   | Fleet | Cards by vehicle class (sedan, SUV, van): photo, seats, transmission, "from ₱2,000 / day". |
   | Services | Self-drive and chauffeur side by side, each with what is included. |
   | How it works | Three steps that match the real process: inquire, receive a secure booking link by SMS, pick up. |
   | Requirements | Valid ID and licence, security deposit, same-day rentals billed as one day. |
   | Why Triple R | The existing trust copy as three short points. |
   | Visit or contact | Address, hours, phone, map link, and the booking button. |
   | Footer | Mark, contact line, staff sign-in link. |

4. **Fix the responsive layout** at 768–1100 px: no mid-word breaks, no copy beneath the wheel, nothing clipped at the left edge. Restore the scrollbar and the normal cursor.
5. **Remove demo artefacts:** the placeholder WhatsApp number, the "demo link only" note and the placeholder image label. This needs the real contact details and photos listed in section 6.
6. **Add** a page description, social-sharing tags and a correct heading outline.

**Done when:** the page is under 150 KB of HTML and CSS before the 3D script, renders correctly at 375, 768, 1024 and 1440 px, shows no console errors, and a visitor can see vehicles, rates and a working way to book without scrolling past more than two screens.

### Phase 2 — Sign-in and entry pages (small)

These four pages share one layout so they look like one family: `staff/login.php`, `auth/change-password.php`, `magic-link.php` and `customer/booking.php`.

1. **Sign-in layout.** On desktop, two panels: a dark brand panel on the left (mark, Italiana headline, one line about the workspace, a still from the landing scene) and the form on the right. On phones, the brand panel collapses to a compact header above the form.
2. **Form details.** Focus the email field on load; keep the typed email after a failed attempt; disable the button and show "Signing in…" on submit; show a Caps Lock hint on the password field; restyle the "Show" toggle in the brand color; keep the existing lockout and error messages.
3. **Help line.** "Forgot your password? Ask a system administrator to reset it." There is no self-service reset, so the page should say what to do.
4. **Change password.** Same layout; a live checklist of the password rules; a matching-passwords check before submit.
5. **Secure link and customer booking pages.** A centred light card under a slim brand header. The booking page gets a status badge, a clear summary of vehicle, dates and amount using the new format helpers, and an office contact button.

**Done when:** all four pages share the layout, pass keyboard-only use, and the sign-in page at 375 px shows the whole form without scrolling.

### Phase 3 — Staff shell and dashboard (medium)

1. **Sidebar.** Group the menu under three headings: *Operations* (Workspace, Agreements, Customers), *Fleet* (Vehicles, Locations, Drivers, Maintenance) and *Administration* (Notifications, Staff accounts, Sessions). Add a small inline SVG icon to each item. Move the user's email, role and a quiet "Sign out" link into a block at the bottom.
2. **Top bar.** A slim bar above the content with a breadcrumb on the left and the menu button on phones.
3. **Phone drawer.** A dimmed backdrop, focus kept inside the open drawer, Escape to close, and the page behind not scrollable.
4. **Dashboard** replacing the link list in `staff/home.php`, shown according to role:
   - Count cards: vehicles available, rentals active, pickups today, returns today, maintenance due, reservations needing a driver.
   - A "Needs attention" list: overdue returns, reservations about to expire, chauffeur bookings without a driver, services due.
   - Today's pickups and returns in time order.
   - Quick actions: new reservation, add customer, register vehicle.

   This is the second permitted non-view change: `StaffHomeController` needs read-only count queries through the existing repositories. No writes and no new tables.

**Done when:** every role sees only the cards and menu groups it is allowed, and the shell behaves correctly at all three breakpoints.

### Phase 4 — List pages (medium)

One pattern applied to eleven pages: vehicles, locations, drivers, customers, agreements, staff accounts, sessions, notifications, maintenance schedules, maintenance history and the due report.

1. **Toolbar.** Search and filters in one compact row above the table, with the result count. Filters apply on change, with the "Apply" button kept for use without scripts.
2. **Table.** Sticky header; numbers and money right-aligned; status badges from `StatusPresenter`; the whole row clickable to open the record, with the visible link kept for keyboard users.
3. **Empty state.** A short message and the relevant action ("No vehicles match this filter. Clear filter").
4. **Phones.** Add `data-label` to every cell and switch tables to stacked cards below 650 px, removing the sideways-scroll hint.
5. **Paging.** Page links when a list exceeds 25 rows, using the query string so it works without scripts.
6. **Reformat** each view from single-line markup to indented markup as it is migrated to the layout.

**Done when:** all eleven lists share the pattern, none scrolls sideways at 375 px, and none overflows at 1440 px.

### Phase 5 — Detail pages and forms (large)

**Detail pattern** for vehicle, driver, customer, agreement, damage report and maintenance service:

- A header with the title, status badge and the actions available to the signed-in role.
- A main column of sections and a narrow summary card on the right, stacking on tablets and phones.
- Facts as label-and-value lists, history as a vertical timeline instead of a raw table, photos as a thumbnail grid that opens full size.

**Agreement detail** (`rentals/agreement-detail.php`) is the priority, as it is the most used and currently the least structured:

- A lifecycle stepper across the top: reserved, confirmed, active, returned, completed. The `.stepper` styles already exist.
- A cost card: base amount, charges and discounts, total, deposit and deposit status.
- Tabs or anchored sections for Overview, Charges, Deposit, Damage and History.
- One "Actions" card. Cancel and no-show open a dialog that asks for the required reason, replacing the always-visible reason fields.

**Form pattern** for vehicle, driver, customer, staff account, reservation and maintenance service:

- Fields grouped into titled sections, with help text under the field and the error message beside the field it belongs to.
- Required fields marked; a Cancel link beside the submit button; the action row stays visible at the bottom on long forms.
- **Reservation form** (`rentals/booking-new.php`): sections for type, customer, vehicle and schedule, with a live summary card showing daily rate, days, base amount and deposit. Replace the inline `onchange` handler and inline style with code in `rentals.js`.

**Done when:** every detail and form page uses the patterns, no form control is unstyled, and the acceptance scripts still pass against the unchanged field names.

### Phase 6 — Finish and verify (small)

1. A printable layout for the rental agreement and the maintenance service record.
2. An accessibility pass: skip link, one `h1` per page, labelled landmarks, focus order, dialog focus handling, contrast re-check of every token pair.
3. A responsive pass at 375, 768, 1024 and 1440 px on every page, with screenshots saved under `docs/design/`.
4. Tighten the Content Security Policy by removing `'unsafe-inline'` from `script-src` once no inline scripts or handlers remain.
5. Update `README.md` and replace `docs/APPLE_DESIGN_AUDIT.md`'s open items with their outcomes.

---

## 5. Page inventory

| Page | File | Pattern | Phase |
|---|---|---|---|
| Landing | `public/triple-r-landing.html` | Public | 1 |
| Staff sign-in | `staff/login.php` (`auth/login.php` wraps it) | Entry | 2 |
| Change password | `auth/change-password.php` | Entry | 2 |
| Secure link | `magic-link.php` | Entry | 2 |
| Customer booking | `customer/booking.php` | Entry | 2 |
| Workspace | `staff/home.php` | Dashboard | 3 |
| Vehicles | `fleet/vehicles.php` | List | 4 |
| Locations | `fleet/locations.php` | List with inline add | 4 |
| Drivers | `drivers/list.php` | List | 4 |
| Customers | `customers/list.php` | List | 4 |
| Agreements | `rentals/agreements.php` | List | 4 |
| Staff accounts | `admin/users.php` | List with add | 4 |
| Sessions | `admin/sessions.php` | List | 4 |
| Notifications | `staff/notifications.php` | List | 4 |
| Maintenance schedules | `maintenance/maintenance-schedules.php` | List with add | 4 |
| Maintenance history | `maintenance/maintenance-history.php` | List | 4 |
| Maintenance due report | `maintenance/maintenance-due-report.php` | List | 4 |
| Vehicle detail | `fleet/vehicle-detail.php` | Detail | 5 |
| Driver detail | `drivers/detail.php` | Detail | 5 |
| Customer detail | `customers/detail.php` | Detail | 5 |
| Agreement detail | `rentals/agreement-detail.php` | Detail | 5 |
| Damage report | `rentals/damage-report.php` | Detail | 5 |
| Maintenance service | `maintenance/maintenance-service-form.php` | Detail and form | 5 |
| Vehicle form | `fleet/vehicle-form.php` | Form | 5 |
| Driver form | `drivers/form.php` | Form | 5 |
| Customer form | `customers/form.php` | Form | 5 |
| Staff account form | `admin/user-form.php` | Form | 5 |
| New reservation | `rentals/booking-new.php`, `rentals/reserve.php` | Form | 5 |
| Error pages (new) | `errors/403.php`, `404.php`, `500.php` | Entry | 0 |

---

## 6. Decisions needed from the owner

*All five were answered on 2026-10-01; see "Owner decisions applied" in the Status section. The original questions follow.*

1. **Real business details for the landing page:** phone number, booking channel (WhatsApp, Messenger or phone), address, opening hours. Without these, the placeholder link cannot be removed.
2. **Photos:** vehicle photos by class and one chauffeur photo that the business has the right to use.
3. **Fleet section data.** Recommended: a short hand-written list of vehicle classes and starting rates for now. Reading live vehicles from the database would need a new public endpoint and a decision on which fields and photos may be shown without sign-in, which is a feature rather than a design change.
4. **Brand colors.** This plan keeps the teal, rust and amber already in the staff system and brings the landing page to it. If the black-and-gold look is the preferred identity instead, the token values in Phase 0 change but the rest of the plan does not.
5. **Dashboard counts.** Confirm that read-only count queries in `StaffHomeController` are acceptable ahead of the M10 reporting module.

---

## 7. How each phase is checked

- Run the page in a browser at 375, 768, 1024 and 1440 px and compare against the "Done when" line of the phase.
- Run the acceptance scripts in `bin/` after any phase that touches a view those scripts request.
- Sign in as each of the eight roles after Phases 3 to 5 and confirm that no menu item, card or action appears that the role could not use before.
- Check the browser console and network panel for errors on every page.
