# M7 — Damage Reporting

**Trace status: requirements resolved; implementation may proceed.** The source plan's proposed migration `009_damage.sql` conflicts with M6, which owns migration 009. M7 uses `database/migrations/010_damage.sql`.

## Requirement and implementation trace

- **FR-07:** record agreement-linked damage at `pre`, `during`, and `post` phases; support private photo evidence; persist liability determinations; retain repair-cost suggestions for finance review.
- **BR-6:** every damage report references a rental agreement with `ON DELETE RESTRICT`; the service and database both refuse deleting an agreement that has damage reports. Agreement deletion is unavailable in the M5 state graph, and retained evidence remains restrictive if an administrative delete is attempted.
- **Schema:** `damage_reports` records agreement, phase, whether damage was found, damage location/type/severity when applicable, repair-cost suggestion, actor, and timestamp. A clean pre-inspection may record no damage. Photos are separate report-linked rows. Liability decisions are append-only superseding entries with a mandatory correction reason. Actual financial charges are not stored in M7 tables.
- **Repository/service/controller:** `DamageReportRepository` owns report/evidence/liability persistence; `DamageService` validates phase/status/data, stores evidence through `VehiclePhotoService`'s shared private-photo validation/storage pipeline, appends liability decisions, and supplies the repair-cost suggestion entered during capture. `DamageController` enforces HTTP role boundaries and CSRF.
- **UI:** agreement detail captures all phases, displays report/evidence/liability state, and offers role-specific decisions and finance posting. A dedicated read-only damage-report view shows the evidence, full liability correction chain, and finance posting audit. Photos are served only through an authenticated staff route.
- **Money integration:** finance staff reviews the suggestion and may adjust its amount with an auditable reason; posting uses the existing `RentalService::addCharge()` path and `rental_charges.charge_type='damage'`. M7 has no second charge writer.

## Decisions (reasoning)

- **Migration 010:** migration 009 is the shipped M6 guard migration. Reusing 009 would collide with the live migration ledger; M7 starts at 010.
- **Capture roles:** `front_desk` and `fleet_manager` may capture any phase, matching the M5 pickup/return operators. Only `fleet_manager` and `system_admin` may determine customer liability. Only `finance_staff` may convert an approved suggestion into a charge. `auditor` is read-only.
- **Charge authority:** DamageService stores the reported repair estimate as a suggestion; no pricing formula was specified, so it does not invent one. It never inserts a charge. Finance reviews/adjusts with a reason and calls the existing append-only M5 add-charge path. The charge and review-posting audit commit in one transaction. This preserves one ledger writer and its correction rules.
- **Liability corrections:** a liability decision is immutable. A correction appends a new decision that references the superseded decision and requires a reason, following M2's correction-chain approach. Only the latest unsuperseded decision is current.
- **Photo requirements:** a clean pre-rental walkaround may have no photos. When a during or post report records newly observed damage, at least one photo is required. Reuse `VehiclePhotoService`'s MIME/size validation, private storage outside `public/`, and authenticated serving; do not create another upload pipeline.
- **Phase cardinality:** allow one pre record and one post record per agreement; allow multiple during reports as separate discoveries. Report facts/evidence are append-only. The superseding correction chain applies to liability decisions, not report rows. Database uniqueness and service validation enforce this distinction.
- **Phase timing:** pre reports are allowed while the agreement is confirmed and before pickup; during reports while active; post reports after return. M7 does not silently make evidence upload a prerequisite to M5 transitions; whether inspection capture must gate pickup/return requires an explicit future product decision.
- **Terminal agreement edge case:** if a pre report exists and the confirmed agreement later becomes `cancelled` or `no_show`, the report remains attached as historical evidence. Reports are append-only and the agreement FK is `RESTRICT`; no deletion or cleanup is attempted.
- **Locks:** any operation that spans rental and damage rows uses vehicle → customer → driver → agreement, then damage records. Report writes lock/validate the agreement in a transaction. Agreement identity is immutable under migration 008/009.

## Acceptance criteria

- Migration 010 applies after migration 009; migration 009 remains unchanged. Its agreement FK uses RESTRICT, and raw SQL deletion is rejected.
- Reject reports without an agreement, invalid phases/severity, nonmatching lifecycle status, and unauthorized roles. A no-damage pre record accepts no damage location/type/severity; a damage finding requires those fields. CSRF protects all state changes.
- Enforce one pre and one post report per agreement while allowing multiple during reports. A clean pre report accepts zero photos; a during/post damage finding rejects zero photos and accepts validated private photos.
- Verify invalid MIME/oversize files are rejected; files remain outside `public/`; unauthorized requests cannot retrieve evidence.
- Verify `front_desk`/`fleet_manager` can capture, while only `fleet_manager`/`system_admin` can determine or correct liability and auditors remain read-only.
- Direct UPDATE/DELETE of liability decisions is rejected. Corrections require a reason, reference the prior decision, and preserve the chain; branching or correcting a superseded decision is rejected.
- A suggested/adjusted amount creates no rental charge by itself. Finance posting creates exactly one M5 `damage` charge through `RentalService::addCharge()`; the adjustment is auditable. Reversal uses M5's existing reversal path.
- Verify concurrent report/liability writes serialize without violating phase cardinality or the canonical lock order.

## Verification record

- MySQL 8.0.46 migration run applied `010_damage.sql` after `009_chauffeur_guards.sql`. A legacy `schema_migrations` table with its checksum column removed was baselined successfully, and a third run verified all ten migration checksums.
- All 19 M7 database checks passed: report lifecycle/cardinality, required evidence gates, reasoned liability correction, duplicate-charge prevention, finance posting through `RentalService::addCharge()`, agreement RESTRICT, and raw UPDATE/DELETE rejection for reports, liability decisions, and charge-posting audit rows.
- All 17 M7 HTTP checks passed: role boundaries, auditor read-only access to agreement/report detail, multipart PNG upload through the shared photo pipeline, private storage, authenticated photo serving, raw photo UPDATE/DELETE rejection, and `private, no-store` response headers.
- The combined acceptance run also passed M4 database/HTTP checks, M6's 15 chauffeur checks, and the seven migration 009 raw-SQL cases. PHP syntax checks passed for the new and modified PHP files.

## Dependencies and source precedence

M7 depends on M2 private photo storage and M5 agreement lifecycle/charge APIs. This trace resolves the supplied FR-07/BR-6 contract; where it conflicts with current source requirements, the source requirements and verified implementation take precedence and this trace must be updated before migration 010 is committed.
