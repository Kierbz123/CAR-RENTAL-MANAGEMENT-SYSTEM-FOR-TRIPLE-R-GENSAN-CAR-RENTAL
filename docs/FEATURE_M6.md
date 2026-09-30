# M6: Chauffeur Rentals

## Overview
M6 enables chauffeur rentals, separating the driver assignment lifecycle from the vehicle booking lifecycle. It allows `driver_coordinator` and `front_desk` roles to assign drivers to chauffeur rentals while ensuring drivers are not double-booked and vehicles eligible for chauffeur use are selected.

## File Trace
*   `database/migrations/009_chauffeur_guards.sql`: Added DB-level `driver_id` immutability trigger and FR-05 CHECK constraint.
*   `app/Repositories/DriverRepository.php` & `app/Services/DriverService.php`: Added `availableForAssignment` for date-overlap filtering.
*   `app/Repositories/RentalRepository.php`: Removed M5 guards, added `lockDriver`.
*   `app/Services/ChauffeurService.php`: Core logic for assigning, removing, and validating drivers, plus handling the `chauffeur_fee`.
*   `app/Controllers/Rentals/DriverAssignmentController.php`: Handles POST requests for assigning and removing drivers.
*   `app/Services/RentalService.php`: Injects `ChauffeurService`, enforces confirmation rules, and reverses fees on cancellation/no_show.
*   `public/index.php` & `app/Services/RentalRuntimeFactory.php`: Dependency injection wiring and new routes.
*   `app/Views/rentals/booking-new.php`: Enhanced with conditional driver picker using the new filtered driver list.
*   `app/Views/rentals/agreement-detail.php`: New Driver Assignment panel added for eligible roles.
*   `app/Views/rentals/agreements.php`: Added "Needs driver" marker.

## Lock Order
The canonical lock order is strictly enforced: `vehicle -> customer -> driver -> agreement`.
This order is maintained even for driver removal, avoiding any potential deadlocks across the system.

## Role Scope
- `driver_coordinator` can assign, reassign, and remove drivers for existing agreements.
- `front_desk` can create chauffeur bookings and assign drivers simultaneously.
- Driver assignment only works on `reserved` (or `confirmed` for reassignment) agreements.

## Immutability & Safety
- `driver_id` is protected by `rental_agreements_driver_immutable` which prevents reassignments after the rental is active.
- `chauffeur_fee` reversals follow the append-only ledger pattern.
- Two-phase booking failures cleanly notify the user without losing the created reservation.

## Acceptance Checklist

All 15 items were verified by `bin/test-m6.php` against a fresh MySQL 8 acceptance database with `009_chauffeur_guards.sql` applied. The two license-expiry cases were added during the final trace-and-fix and passed on 2026-09-30.

| # | Scenario | Result |
|---|---|---|
| 1 | Chauffeur creation with driver assigns `driver_id` and appends `chauffeur_fee` charge | PASS |
| 2 | Self-drive creation works unchanged (no driver, no fee) | PASS |
| 3 | FR-05 Confirmation Guard: rejects confirming a driverless chauffeur agreement | PASS |
| 4 | FR-05 Confirmation Guard: allows confirming once a driver is assigned | PASS |
| 5 | BR-4 Overlap: assigning a driver who has an overlapping rental is rejected | PASS |
| 6 | BR-4 Adjacent: assigning a driver to a date-adjacent (non-overlapping) rental succeeds | PASS |
| 7 | Driver reassignment on `reserved`: reverses old fee and appends new fee (3 charge rows) | PASS |
| 8 | Driver removal blocked on `confirmed` agreement (restricted to `reserved` only) | PASS |
| 9 | Driver removal on `reserved`: sets `driver_id=NULL` and reverses the fee | PASS |
| 10 | Vehicle status unaffected by driver assignment/removal operations | PASS |
| 11 | Charge immutability trigger: direct `UPDATE` on `rental_charges` rejected with `append-only` | PASS |
| 12 | Chauffeur lifecycle: pickup transitions vehicle to `rented` | PASS |
| 13 | Chauffeur lifecycle: return transitions vehicle to `available` | PASS |
| 14 | Assignment rejects a driver whose license is expired as of the Manila date | PASS |
| 15 | Confirmation rejects an assigned driver whose license expired after assignment | PASS |
