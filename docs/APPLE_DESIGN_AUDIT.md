# Apple HIG Design Audit — Triple R Gensan Car Rental

**Reviewed:** 2026-10-01  
**Scope:** PHP-rendered staff application and the customer magic-link booking page. M9 and M10 are not present in this checkout.  
**Evidence:** Source review of `app/Views/`, `public/assets/css/app.css`, and the JavaScript used by the reviewed flows. No browser screenshots or runtime interaction session were available, so layout findings are based on source. Contrast ratios below are calculated from the declared CSS colors.

## Track A — Internal Staff Application

### 1. Visual Hierarchy & Typography

**Good.** `app.css` establishes one system-font stack, a shared heading scale, muted supporting text, common panels, tables, and two-column forms. The fleet, customer, driver, agreement, and maintenance screens reuse those primitives. Dense tables are appropriate for staff scanning and should keep their useful columns.

**Finding — Medium: status and enum labels are not normalized.** `fleet/vehicles.php`, `rentals/agreements.php`, `drivers/list.php`, and `maintenance/maintenance-schedules.php` print stored state values directly. That leaves `out_of_service`, `in_progress`, and `no_show` in the same visual language as human-written labels. Agreement list also defined “Needs driver” with undeclared CSS variables. **Resolved during this pass:** that badge now uses a shared danger style. The broader customer/staff label map remains a structural follow-up.

**HIG:** `design-principles.md › Familiarity` says, “Keep visuals and interactions consistent.” Use shared state labels and a small status component, retaining the textual state so color is never the only cue.

### 2. Simplicity & Minimalism

**Good.** The staff home page exposes tools by role, while fleet and customer lists keep the operational fields visible. The tables should not be simplified by hiding fields staff use to identify a vehicle, customer, driver, or agreement.

**Finding — Low: several screens place explanatory text beside every control.** In `maintenance/maintenance-schedules.php` and `maintenance/maintenance-service-form.php`, the larger explanatory paragraphs repeat details already expressed in labels or section headings. Preserve the safety rules, but group them into one concise note per workflow so the form remains scannable.

**HIG:** `design-principles.md › Simplicity` says, “Include just what’s necessary.” This is a small copy and grouping improvement, not a request to remove operational data.

### 3. Interactivity & Feedback

**Finding — Medium: consequential user actions did not share a confirmation pattern.** `admin/users.php` posted password-reset and deactivation actions directly. **Resolved during this pass:** reset and deactivate now ask for confirmation and name the effect; routine role saves remain immediate.

**Finding — Medium: feedback styles differ by module.** Some views render server errors with `.alert[role=alert]`, some notices use `.notice.panel`, and form scripts add their own alerts. **Partially resolved during this pass:** `.notice` now has a shared success treatment; controller-specific feedback wording still needs a separate pass.

**HIG:** `design-principles.md › Familiarity` says, “Provide clear feedback.” The current server-rendered errors are a sound base; the improvement is consistent styling and consequential-action confirmation.

### 4. Deference to Content

**Good.** The shared dark top bar and neutral tables keep the focus on records. `table-wrap` preserves all data on narrow screens instead of compressing it until values become unreadable.

**Finding — Medium: narrow-screen table scrolling is not signposted.** `app.css` sets `overflow-x: auto`, but there is no cue that more columns are available beyond the viewport. On phone widths, add a short “Scroll table horizontally for more columns” hint only when needed, or convert the most frequently used tables into stacked rows while retaining the full desktop table.

**Finding — Low: keyboard focus relied on browser defaults.** `app.css` did not define `:focus-visible`. **Resolved during this pass:** a shared three-pixel blue focus outline with a white halo now marks keyboard focus.

**HIG:** `accessibility.md › Speech` says, “Let people use the keyboard alone to navigate and interact with your app.” In this web app, a visible CSS focus treatment is the direct equivalent.

### 5. Actionable Recommendations

- Add shared state labels with text plus a status-specific cue; the undefined “Needs driver” badge style is fixed, while other enum labels still need a presentation map.
- **Done:** add a shared `:focus-visible` outline in `app.css`.
- **Done:** add `data-confirm` prompts for password reset and account deactivation in `admin/users.php`, handled by `auth.js`.
- Partially done: `.notice` now has a shared success style; align controller-specific message wording and semantics in a later pass.
- Add an accessible horizontal-scroll hint for dense tables on compact screens. Keep the existing table data and column order.
- Tighten repeated maintenance instructions without removing restrictions or safety information.

## Track B — Customer-Facing Booking Page

### 1. Visual Hierarchy & Typography

`customer/booking.php` gives the page a direct “Your rental booking” heading, a live status message, and a definition list for agreement, status, vehicle, dates, duration, and base amount. It inherits a readable system stack and sensible line height from `app.css`. Calculated contrast is 13.57:1 for `#1d2939` on `#f4f6f8`, 4.97:1 for muted `#667085` on white, and 14.70:1 for primary text on white.

**Finding — High: the panel had no interior padding.** `.panel` sets only overflow, border, background, and radius. Unlike other screens, `customer/booking.php` did not wrap content in `.panel-body`, so the heading and booking details sat against the panel edges. **Resolved during this pass:** the customer card now has responsive inset spacing.

**HIG:** `layout.md` says, “A consistent layout that adapts across display sizes…” The existing one-column content adapts naturally; the missing inset is a local spacing defect.

### 2. Simplicity & Minimalism

**What works.** The page contains only the secure booking details and a short support instruction. This matches the customer’s immediate task and avoids exposing staff controls or unrelated offers.

**Finding — Low: the status value was system vocabulary.** The view displayed raw values such as `reserved` or `no_show`. **Resolved during this pass:** known states now use customer-readable labels such as “Reserved” and “Not picked up.”

### 3. Interactivity & Feedback

`rental-booking.js` announces loading and success through `role="status"`, then exposes details only after the secure booking context verifies. An unavailable or expired context returns a short explanation, not a server error page.

**Finding — Medium: network failures exposed a browser error string and had no recovery action.** The catch handler printed `error.message`, which could read “Failed to fetch.” **Resolved during this pass:** network errors now provide a plain-language connection message and “Try again” button; expired-link errors direct the customer to reopen the latest secure link or contact the office.

**HIG:** `design-principles.md › Familiarity` says, “Provide clear feedback.” The live status region is already in place; the failure text and next action need to match that standard.

### 4. Deference to Content

The page is calm and content-first, with no staff navigation or visual clutter. The secure session boundary is communicated without exposing token details. Keep this presentation separate from the proposed public landing page and staff workspace.

### 5. Actionable Recommendations

- **Done:** add responsive panel insets on `customer/booking.php` without changing booking data or API behavior.
- **Done:** provide customer-friendly labels for known agreement states.
- **Done:** replace raw network errors with a retry action and distinguish expired links from temporary load failures.
- Keep the current live status region and verified-only rendering behavior.

## Prioritized Recommendations

### CSS/markup-only fixes

1. **Done:** add a consistent `:focus-visible` ring in `public/assets/css/app.css`.
2. **Done:** add responsive panel padding to `customer/booking.php` and shared state/feedback classes.
3. **Done:** replace the inline “Needs driver” badge style in `rentals/agreements.php` with a defined reusable class.
4. Add a compact-screen table-scroll hint and check hit targets at phone widths.

### Structural changes

1. Introduce a shared presentation map for vehicle, driver, rental, deposit, and maintenance states; keep each label and non-color cue in one place.
2. Establish a small CSS token set for surface, content, accent, error, warning, border, spacing, radius, and focus, with documented contrast pairs.
3. Standardize staff success/error feedback and confirmation behavior across views without changing controller authorization or business rules.
4. For the densest staff tables, evaluate responsive row cards as a separately scoped markup change; do not remove columns staff rely on.

## Platform Notes

This is a web application rather than an Apple-platform app. Apple’s guidance is applied to the web’s own controls and input modes: CSS focus for keyboard navigation, responsive layout for varying viewport sizes, textual status alongside color, and live regions for asynchronous feedback. No native Apple navigation convention is imposed on the staff console.
