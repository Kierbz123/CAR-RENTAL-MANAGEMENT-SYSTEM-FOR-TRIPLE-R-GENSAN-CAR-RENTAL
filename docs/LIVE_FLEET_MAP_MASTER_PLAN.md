# Master Plan: Live Map of Rented Vehicles on the Locations Page

**Written:** 2026-10-02
**Status:** built on 2026-10-03, but **not as written below**. The owner asked for real positions, not a simulation, and for "a simple app" to provide them. What was built: a tracker web app a phone opens at `/track` (real GPS), the `vehicle_positions` table, and the live map on `/fleet/locations` drawn by the site's own script with OpenStreetMap pictures. Not built: the simulated tracker and its routes (section 4), the Simulation box, Leaflet (nothing was downloaded), and coordinates on locations (section 5.1). The overdue, outside-the-area and last-seen states of section 4.3 are built, from real positions. The README section "Live tracking: the tracker phone and the live map" describes what exists; treat the rest of this document as the original proposal.
**Goal:** `/fleet/locations` shows a map, like Google Maps, with a moving marker for every vehicle that is out on rental. Staff see where each one is, who has it, when it is due back, and what the system does when something goes wrong (overdue, out of the allowed area, signal lost). The positions are **simulated**: no vehicle carries a tracker, so the system plays the part of one, the same way the payment checkout plays the part of a gateway.

Statements about Google Maps, OpenStreetMap, routing services and the Data Privacy Act are from general knowledge and were not re-checked today. Confirm them before saying them at the defense.

---

## 1. Where the system is today

Read from the code and the everyday database on 2026-10-02.

| Piece | State |
|---|---|
| `/fleet/locations` | A list of named places ("Main office lot") that staff add and retire. Open to system administrators and fleet managers. |
| `vehicle_locations` table | A name and a status. **No coordinates.** The everyday database has no locations yet. |
| Where a vehicle is | `vehicles.current_location_id` says which named place it is parked at. Nothing records where a vehicle is while it is rented. |
| Rented vehicles | An agreement with status `active` (picked up, not yet returned). The everyday database has none at the moment, so the map would be empty until a rental is picked up. |
| Scripts and styles | The site's security header allows scripts, styles and images **from this site only**. `three.js` and the QR library are kept in `public/assets/js/vendor/` for that reason. A map library loaded from Google or a CDN would be blocked. |
| The server | PHP's built-in server answers one request at a time. A connection held open for a "push" feed would block every other page. |
| Earlier scope | GPS and a driver sign-in were left out of earlier work on purpose. |

What this means for the plan:

1. There is no location data to show, so the feature needs a **source of positions** before it needs a map.
2. The map library has to be copied into the project, and the security header has to allow map images from one named map server, on this page only.
3. "Live" has to mean the page asking for fresh positions every few seconds, not the server pushing them.

---

## 2. The three parts

```
 Where positions come from          Where they are kept            What staff see
+---------------------------+     +------------------------+     +---------------------------+
| Simulated tracker          | --> | vehicle_positions      | --> | Map on /fleet/locations   |
| (moves each rented vehicle |     | one row per vehicle:   |     | asks for positions every  |
|  along a real road route)  |     | latest position only   |     | 5 seconds, glides markers |
|                            |     +------------------------+     +---------------------------+
| Later, if ever: a GPS      |
| device or a driver's phone |
+---------------------------+
```

Keeping the three apart is what makes the simulation honest. The map and the table do not know whether a position was made up or came from a device; only the first box changes if real tracking is ever added.

---

## 3. The map: Leaflet with OpenStreetMap, not Google Maps

| Option | Cost and setup | Fit |
|---|---|---|
| **Google Maps JavaScript API** | Needs a Google Cloud account with a billing card and an API key. The script must load from Google's servers and it writes styles into the page, both of which the site's security header refuses. | Would mean weakening the security header for the whole map page, and a card on file for a school project. |
| **Leaflet + OpenStreetMap** (recommended) | Free, no account, no key. Leaflet is one script and one stylesheet copied into `public/assets/js/vendor/leaflet/`. Only the map **images** come from outside. | Same experience: drag, zoom, markers, pop-ups. The security header changes by one line, on one page. |
| A drawn map of the city | Nothing from outside at all. | Does not look or behave like a map. Kept only as the fallback when there is no internet. |

What the recommended option needs:

- **Leaflet copied into the project.** This is a download from the internet, so it waits for the owner's go-ahead.
- **One change to the security header, on this page only:** images allowed from this site and from `https://tile.openstreetmap.org`. Scripts and styles stay "this site only". `Response` gets a way to widen the header for one response; every other page keeps today's header.
- **Attribution** "© OpenStreetMap contributors" on the map. Their tile servers are free for light use and ask for it.
- **Internet at the defense** for the map images. The tunnel needs internet anyway. Without it the markers still move on a plain grey background with the place names listed beside it.
- To check during the build: the site sends no referrer to other sites, and OpenStreetMap asks for one. If the map images are refused, the map page sends its origin as referrer, or the images come from another free provider.

---

## 4. Where positions come from: the simulated tracker

### 4.1 Rules that keep it honest

| Rule | Why |
|---|---|
| The map carries a fixed label: "Simulated positions. No vehicle carries a tracker." | A fleet manager must never act on a made-up position as if it were real. |
| Every stored position records its source (`simulated` or `device`). | The same reason, in the database and in any report. |
| The simulator only runs when `TRACKING_SOURCE=simulated`. | A real deployment cannot show made-up positions by accident. |
| Positions exist only for agreements that are `active`. They stop at return. | The map shows rentals, not a customer's movements after the rental ends. |

### 4.2 How a vehicle moves

- **Routes.** A handful of real trips out of General Santos (for example office to airport, office to the fish port, office to Koronadal, office to Glan) are kept in `config/tracking.php` as lists of coordinates that follow the roads. They are produced once with a free routing service and saved, so nothing is fetched while the system runs. This too is a one-time download that waits for the owner's go-ahead; the fallback is clicking the points on the map by hand.
- **Assignment.** Each active agreement is given a route from its agreement number, so the same rental always takes the same trip.
- **Position.** Where the vehicle is now is worked out from the clock: time since pickup, a speed of about 35 km/h in the city and 60 outside it, a stop at the far end, then the way back. No random jumping: reload the page and the vehicle is where it should be.
- **When it is worked out.** At the moment the map asks. The request for positions moves each simulated vehicle to "now", saves it, and returns it. No background program has to be running, which is one less thing to fail during a presentation.

### 4.3 The "what if" cases

As with payments, the point of simulating is to show what the system does when things go wrong. A system administrator gets a small **Simulation** box under the map to put one vehicle into each state.

| # | What if… | What the map and the system show |
|---|---|---|
| 1 | a rental is going normally | Green marker gliding along its route; pop-up with plate, vehicle, customer, agreement link, speed, "due back Oct 5, 9:00 AM" |
| 2 | the vehicle is not back by its return time | Marker turns red, "Overdue by 2 h 10 min"; the vehicle is listed first; the workspace dashboard counts it under Needs attention |
| 3 | the vehicle leaves the allowed area | A circle of 150 km around the office is drawn on the map. Outside it the marker turns amber, "Outside the service area", with the distance from the office |
| 4 | the tracker goes quiet | No position for 2 minutes: the marker greys out where it was last seen, "Last seen 4 minutes ago". It is never shown as still moving |
| 5 | the vehicle stops for a long time | "Stopped for 25 minutes" in the pop-up; no alarm, since a parked rental is normal |
| 6 | the vehicle is returned | Its marker leaves the map at once; the vehicle is counted at the location it was returned to |
| 7 | a role without access asks for positions | Refused. Positions are personal data about where a customer is |
| 8 | a made-up position is sent to the device address | Refused without the vehicle's device key, and written to `security_logs` |

---

## 5. Database: migration `021_vehicle_tracking.sql`

### 5.1 Locations get coordinates (no new table)

`vehicle_locations` gains `latitude` and `longitude` (`DECIMAL(9,6)`, both or neither, checked to be valid). The add and edit forms get a small map: click to place the pin. Locations then appear on the live map as depots with a count of the vehicles parked there, and simulated trips start from the vehicle's own location.

### 5.2 One new table: `vehicle_positions`

One row per vehicle, replaced each time a newer position arrives. It holds **where the vehicle is now**, not everywhere it has been.

| Column | Type | Meaning |
|---|---|---|
| `vehicle_id` | PK, FK to `vehicles` | One row per vehicle |
| `agreement_id` | FK to `rental_agreements` | The rental this position belongs to |
| `latitude`, `longitude` | DECIMAL(9,6) | Checked to be a valid place on Earth |
| `heading_degrees` | SMALLINT, NULL | Which way it is pointing, for the marker's arrow |
| `speed_kph` | DECIMAL(5,1) | 0 when stopped |
| `source` | ENUM(`simulated`, `device`) | Made up, or reported by a device |
| `recorded_at` | DATETIME(6) | When the vehicle was there |
| `stopped_since` | DATETIME(6), NULL | For "stopped for 25 minutes" |

Why one row per vehicle and not a history of every position:

- A history grows by thousands of rows per vehicle per day, and the application's database account cannot delete, so it could never be trimmed.
- A full movement history of a customer is far more sensitive than a current position, and nothing in this feature needs it.
- The line showing where a vehicle has been is drawn by the browser from what it has seen since the page was opened, and for simulated trips from the route itself.

This is one more table: **36** in the database, counting the migration ledger. Section 10 has the alternative that adds none.

---

## 6. Code structure

Follows the existing layers and the gateway pattern used for SMS and payments.

| New file | Job |
|---|---|
| `config/tracking.php` | The routes, the office position, the service-area radius, speeds |
| `app/Services/Tracking/TrackingSource.php` | Interface: bring the positions of these agreements up to now |
| `app/Services/Tracking/SimulatedTrackingSource.php` | Works out each position from the clock and the route; the what-if states |
| `app/Services/Tracking/TrackingSourceFactory.php` | Picks the source from `TRACKING_SOURCE`; empty switches the map's live part off |
| `app/Services/FleetTrackingService.php` | Which vehicles are out, overdue, out of area, quiet; what each role may see |
| `app/Repositories/VehiclePositionRepository.php` | Save the latest position; read positions with vehicle, agreement and customer |
| `app/Controllers/Fleet/TrackingController.php` | `GET /api/fleet/positions`; the simulation controls; later `POST /api/tracking/ping` |
| `public/assets/js/vendor/leaflet/` | Leaflet's script, stylesheet and marker images |
| `public/assets/js/fleet-map.js` | Draws the map, asks for positions every 5 seconds, glides markers, colours them |
| `bin/test-tracking.php` | Acceptance checks |

| Changed file | Change |
|---|---|
| `app/Views/fleet/locations.php` | The map and the list of vehicles out on rental go at the top; the locations list stays below, with coordinates and a pin picker |
| `app/Controllers/Fleet/VehicleController.php`, `VehicleLocationRepository.php` | Coordinates on create and update |
| `app/Http/Response.php` | A way to allow map images for one response |
| `app/Support/Navigation.php` | The menu entry becomes "Locations and live map" |
| `app/Controllers/StaffHomeController.php`, dashboard | Overdue vehicles under Needs attention |
| `public/index.php`, `.env.example`, `README.md`, `database/schema.sql` | Routes, settings, a new section, the regenerated schema |
| `bin/test-roles-http.php` | The page and the positions address in the role matrix |

---

## 7. How "live" works

1. The page loads with the map centred on General Santos and the depots drawn.
2. Every 5 seconds the browser asks `GET /api/fleet/positions`. The answer is a short list: vehicle, position, heading, speed, state, when it was recorded.
3. The browser moves each marker smoothly from where it was to where it is over those 5 seconds, so it glides instead of jumping.
4. When the tab is in the background the page stops asking, and catches up when it returns.
5. Clicking a vehicle in the list centres the map on it and opens its pop-up; "Follow" keeps it centred.

Asking every few seconds is the right choice here, not a shortcut: the built-in PHP server handles one request at a time, so a feed that holds a connection open would freeze the rest of the site.

---

## 8. Who sees what

| Role | Map | Customer name on a marker |
|---|---|---|
| System administrator, fleet manager | Yes (they open this page today) | Yes; both already see agreements |
| Front desk | See decision 4 | |
| Everyone else, and customers | No | No |

A customer's location while renting is personal information. For a real business this needs a line in the rental terms saying the vehicle is tracked, and the Data Privacy Act applies. For the demonstration the positions are made up, the map says so, and nothing is kept after the vehicle is returned.

---

## 9. From simulated to real, if it ever happens

Two routes, neither part of this plan's required work:

- **A GPS tracker in each vehicle** that reports to `POST /api/tracking/ping` with the vehicle's device key. The work is a `DeviceTrackingSource`, a key per vehicle, and buying and fitting the hardware.
- **The driver's phone as the tracker** (optional phase 5 below). For a chauffeur rental, the driver opens a secure link on their phone and the page shares the phone's location while the trip is on. No hardware, real positions, and it can be shown at the defense by walking around with a phone. It needs HTTPS, which the tunnel provides, and the driver's permission in the browser.

In both cases the table, the map and the rules in section 4.3 stay as they are.

---

## 10. Decisions for the owner

The plan assumes the recommended answer. Say so if any should go the other way.

| # | Decision | Recommended | Alternative |
|---|---|---|---|
| 1 | The map | Leaflet with OpenStreetMap: free, no account | Google Maps: needs a Google Cloud account with a billing card, and a weaker security header on that page |
| 2 | Where the latest position is kept | A new `vehicle_positions` table (36 tables) | Five columns on `vehicles`, no new table. Simpler to count, but every position update would touch the row that bookings lock, and the vehicle's "last updated" time would change every few seconds |
| 3 | Position history | Latest position only | Keep every position. Shows a full trail after a reload, but grows without limit and is far more sensitive |
| 4 | Front desk | No map; fleet staff only | Front desk sees the map too, since they take the calls about late returns |
| 5 | Route data | Fetched once from a free routing service and saved in the project | Clicked by hand on the map, with no download |
| 6 | Demo rentals | A small script that takes four or five bookings through to "picked up", so the map has vehicles on it | Make them by hand through the normal pages before the defense |

Two steps download files from the internet (Leaflet, and the route data in decision 5). They wait for a yes.

---

## 11. Build order

Each phase ends with something that can be shown and with its tests passing. S is a sitting, M a day or two, L several days.

| Phase | Contents | Size | Can be shown afterwards |
|---|---|---|---|
| 1. Map and depots | Leaflet copied in; the security header change for this page; coordinates on locations with the pin picker; the map on `/fleet/locations` showing the depots and parked vehicle counts | M | A real, draggable map of General Santos with the office and lots on it |
| 2. Moving vehicles | `021_vehicle_tracking.sql`; routes in `config/tracking.php`; the simulated tracker; `GET /api/fleet/positions`; markers that glide; the list of vehicles out on rental; the "simulated" label | L | Rows 1, 5 and 6 of section 4.3 |
| 3. What-ifs | Overdue, outside the service area, signal lost; the Simulation box for the administrator; overdue on the dashboard | M | Rows 2, 3 and 4 |
| 4. Proof and paperwork | `bin/test-tracking.php`; role matrix; the device address refusing unsigned positions (rows 7 and 8); README; regenerated `schema.sql`; the database documentation; the demo-rentals script | M | Every suite green on a test database |
| 5. Optional: the driver's phone | A secure link for the assigned driver; the phone shares its position during the trip; shown as `device` on the map | L | A real marker that moves when the phone moves |

Phases 1 and 2 alone meet the goal. Phase 3 is what makes it worth presenting.

---

## 12. Risks

| Risk | Handling |
|---|---|
| No internet at the defense, so no map images | Markers and the vehicle list still work on a plain background; test this once beforehand |
| The map images are refused by OpenStreetMap | Check in phase 1; send the referrer on this page or switch to another free provider |
| The simulation is taken for real tracking | The fixed label, the `source` on every row, the setting that turns the simulator on |
| The teacher questions another table | One table with one row per vehicle, used by a page; decision 2 offers the no-table version |
| Positions asked for every 5 seconds slow the single-request server | The answer is one small query; the page stops asking when it is in the background |
| Scope creep into trip history, geofence editors, route planning | Not in this plan. One fixed service-area circle, latest position only |
