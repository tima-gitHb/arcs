# B13b — Segments and coupons (design, Phase 1)

Status: **draft for approval**. No code is written until it is approved.

Goal: a reservation holds one or more flights in order: one-way (BEY→CDG), connection (BEY→IST→CDG),
round trip (BEY→CDG, later CDG→BEY). What is per flight today (status, seat, boarding pass, check-in/boarded
by and at) moves from `passengers` to a per-passenger, per-segment **coupon**.

Guiding rule: **a single-flight reservation behaves exactly as today.** The passenger-level API
(`$passenger->status`, `seatAssignment`, `checkedInBy`, `StatusService::checkIn($passenger, $by)`, …) keeps working
and means "the passenger's first coupon", which for a one-flight reservation is the only coupon. New code and
multi-flight journeys use coupons.

---

## 0. Scope conflict to resolve first

CLAUDE.md says "no connecting flights" (What ARCS is) and lists **connecting flights** as out of scope.
B13b builds exactly that. Before Phase 2 the team lead must confirm the scope change (and the client, since the
BRD has no SR for it). If confirmed, Phase 2 updates CLAUDE.md: remove "connecting flights" from out of scope,
add the journey rules below. Transit visas stay out of scope (already stated).

New SR numbers are needed for the tests. Proposed (please confirm or renumber): **SR-60** journey rules
(connect, minimum connection, return after outbound), **SR-61** check-in of a whole direction,
**SR-62** bags tagged to the final destination, **SR-63** entry check on the final destination only.

---

## a) Migrations (all new, none edited)

| # | Migration | Content |
|---|---|---|
| 1 | `add_arrives_at_to_flights_table` | `arrives_at` dateTime **nullable** (existing flights have no arrival). Required by the M1 form from now on; must be after `departure_at`. A flight without `arrives_at` cannot be followed by another segment ("Set the arrival time of ME201 first"). |
| 2 | `create_reservation_segments_table` | `id`, `reservation_id` FK, `flight_id` FK, `sequence` tinyint unsigned (1, 2, 3… across the whole journey), `direction` string(10) (`Outbound` / `Return`, enum `SegmentDirection`), timestamps. **UNIQUE** (`reservation_id`, `sequence`), **UNIQUE** (`reservation_id`, `flight_id`) (same flight twice is meaningless), INDEX (`flight_id`). |
| 3 | `create_coupons_table` | `id`, `passenger_id` FK, `reservation_segment_id` FK, `flight_id` FK (copy of the segment's flight: manifest, seat and no-show queries need it without a join), `status` string(20) default `Booked`, `checked_in_at` nullable, `boarded_at` nullable, timestamps. **UNIQUE** (`passenger_id`, `reservation_segment_id`), INDEX (`flight_id`, `status`). `status`, `checked_in_at`, `boarded_at` are not fillable (only `StatusService`). |
| 4 | `add_coupon_id_to_flight_records` | `seat_assignments.coupon_id` FK **UNIQUE** (one seat per coupon); drop the UNIQUE on `seat_assignments.passenger_id` (a connecting passenger has one seat per flight), keep the column and its FK. UNIQUE (`flight_id`, `seat_id`) **stays** (SR-7). `boarding_passes.coupon_id` FK + index. `gate_overrides.coupon_id` FK **UNIQUE**, drop UNIQUE on `gate_overrides.passenger_id`. `audit_log.coupon_id` FK nullable (the row also keeps `passenger_id` and `flight_id`). `bags.direction` string(10) default `Outbound`, `bags.destination` char(3) FK → `airports.code` (the final destination of the direction). All new FKs nullable in this migration. |
| 5 | `backfill_segments_and_coupons` | Data only (see b). |
| 6 | `move_status_from_passengers_to_coupons` | After the backfill: `coupon_id` NOT NULL on `seat_assignments`, `boarding_passes`, `gate_overrides`; `bags.destination` NOT NULL; **drop** `passengers.status`, `checked_in_at`, `boarded_at`. `down()` puts them back from each passenger's first coupon. |

**Stays on `passengers`** (same for the whole journey): names, type, date of birth, `accompanying_adult_id`,
`cabin_class` (booked class), `extra_bags`, `extra_kg`, travel documents, special services.
**Moves to `coupons`**: status, `checked_in_at`, `boarded_at`; and through `coupon_id`: seat, boarding passes,
gate override, audit rows.

**`reservations.flight_id` stays**, kept equal to the first outbound flight by `JourneyService`. It keeps
`$reservation->flight` working (27 uses in app code, 15 in views) for one-flight reservations. New code reads
`$reservation->segments`. It is documented as "the first flight" in CLAUDE.md.

## b) Migrating existing data (migration 5)

In one transaction, with `DB::table()` (no models, so later model changes cannot break it):

1. Every reservation → one segment: `sequence 1`, `direction Outbound`, `flight_id = reservations.flight_id`.
2. Every passenger → one coupon on that segment, copying `status`, `checked_in_at`, `boarded_at`;
   `coupons.flight_id = reservations.flight_id`.
3. `seat_assignments.coupon_id`, `boarding_passes.coupon_id`, `gate_overrides.coupon_id`, `audit_log.coupon_id`
   (rows with a passenger) = that passenger's coupon.
4. `bags.direction = Outbound`, `bags.destination = flights.destination` of the reservation's flight.
5. Check at the end: counts of passengers = coupons, reservations = segments, no NULL `coupon_id` where a passenger
   exists; otherwise throw (the migration rolls back). Nothing is deleted.

`down()` drops the coupon/segment data after migration 6 is rolled back (statuses restored there).

## c) Journey rules: one service

`App\Services\JourneyService` (new). Controllers and `ReservationService` call it; no rule lives in a controller.

- `validate(Journey $journey): void` throws `InvalidJourney` (message shown as a red flash). `Journey` is a small
  value object: `outbound: list<Flight>`, `return: list<Flight>` (empty for one-way).
  - at least one outbound flight; at most **3 flights per direction**; no flight twice;
  - **connect**: each flight's `origin` = the previous flight's `destination`
    ("ME216 departs from IST, ME264 arrives at DXB");
  - **minimum connection**: next `departure_at` − previous `arrives_at` ≥ the connecting airport's
    `min_connection_minutes` ("Connection at IST is 30 min; at least 60 min needed");
    a previous flight without `arrives_at` is refused;
  - **return after outbound**: the first return flight departs after the last outbound flight arrives (same
    minimum-connection rule at the turnaround airport);
  - **round trip shape**: the return starts where the outbound ends and ends where it started (no open jaw);
  - no cancelled or departed flight.
- `addSegments(Reservation $reservation, Journey $journey): void` creates the segments in order and a coupon per
  passenger per segment, and sets `reservations.flight_id` to the first outbound flight.
- `directionFor(Passenger $passenger, User $agent): SegmentDirection` — the direction whose **first** flight
  departs from the agent's station; else Outbound (old behaviour).
- `couponsOf(Passenger $passenger, SegmentDirection $direction): Collection<Coupon>` in sequence order;
  `finalDestination(Reservation, SegmentDirection): Airport`.

Safety net for old code and tests: `Reservation::created` makes segment 1 from `flight_id` when the reservation
has none, and `Passenger::created` makes one coupon per existing segment. So `Reservation::factory()` and
`Passenger::factory()` keep producing a valid one-flight journey.

## d) Service changes (old signature → new)

Optional parameters keep every existing call valid. `Coupon` is the new model.

**StatusService** (still the only place that changes a status; now a *coupon's* status)
- `checkIn(Passenger $p, User $by): void` → `checkIn(Passenger $p, User $by, ?SegmentDirection $direction = null): void`
  — null = `JourneyService::directionFor()`. All coupons of the direction move Booked → CheckedIn in **one
  transaction** (all or none). Window and `CheckinOpen` are checked on the **first** flight of the direction;
  seat required on **every** flight (infant excepted); documents valid on every flight's departure date.
- `lateCheckIn(Passenger, User $gate, User $manager, string $reason)` → same + `?SegmentDirection $direction = null`;
  one `gate_overrides` row per coupon of the direction.
- `cancel(Passenger $p, User $by)` — unchanged signature: only when **every** coupon is Booked; all coupons →
  Cancelled, all seats released (cancelling one direction only would be a partial refund: out of scope).
- `board(Passenger $p, User $by): void` → `board(Passenger $p, User $by, ?Flight $flight = null): void`
  — boards only the coupon on `$flight` (null = the reservation's first flight, old behaviour). Gate controllers
  pass the manifest's flight.
- `closeBoarding(Flight $flight, User $by)` — unchanged signature: CheckedIn coupons **on this flight** → NoShow;
  later coupons of the same direction → NoShow as well (the passenger is not travelling on; their bags were
  checked through and come off here). Returns the bags to offload.
- `setFlightStatus`, `ensureBagsOffloaded`, `allowedFlightMoves` — unchanged signatures, queries on coupons.
- `changePassengerStatus(...)` (private) → `changeCouponStatus(Coupon $c, PassengerStatus $new, User $by, array $extra = [])`;
  the audit row gets `passenger_id`, `flight_id`, `coupon_id`.

**SeatService**
- `seatMap(Flight)` — unchanged, reads `seat_assignments.flight_id` (as now).
- `assign(Passenger, Seat, User)`, `assignForUpgrade(...)`, `change(...)` → each gets `?Flight $flight = null`
  (null = reservation's first flight). The seat's aircraft no longer tells the flight (one aircraft flies many
  flights), so the coupon is found by passenger + flight.

**BagService**
- `add(Passenger, float $kg, BagType, User)` → `+ ?SegmentDirection $direction = null`; sets `direction` and
  `destination` = final destination of that direction (bag checked through). Tag airline from the first flight.
- `excess(Passenger)`, `excessPaidCents`, `excessDueCents`, `recalculate` → `+ ?SegmentDirection $direction = null`:
  the allowance applies once per direction (outbound bags and return bags are counted separately).
- `remove(Bag, User)`, `confirmOffload(Bag, User)` — unchanged.

**BoardingPassService**
- `completeCheckIn(Passenger, User, ?string $entryReason = null): BoardingPass` → `+ ?SegmentDirection $direction = null`;
  issues **one pass per coupon** of the direction; returns the first flight's pass (as now). New
  `passesFor(Passenger, SegmentDirection): Collection<BoardingPass>`.
- `completeLateCheckIn(...)` → same `+ ?SegmentDirection $direction = null`.
- `issue(Passenger, User): BoardingPass` → `issue(Passenger, User, ?Flight $flight = null)`; barcode format
  unchanged (`PNR-passengerId-n`, n counts all the passenger's passes, so it stays unique).
- `printData(Passenger, BoardingPass)` — unchanged signature; flight, seat and boarding time come from the pass's
  coupon; the pass also shows the final destination of the direction.
- `boardingTime(Flight)`, `barcodeSvg(BoardingPass)` — unchanged.

**ManifestService** — signatures unchanged (`counts`, `seatStates`, `passengers`, `bagsToOffload`,
`passengersForPnr` all take the `Flight`); queries go through `coupons.flight_id` instead of
`reservations.flight_id`, statuses from coupons. `passengersForPnr` accepts a PNR with a coupon on this flight.
Fixed query counts are kept (eager load `coupons` filtered to the flight).

**AllowanceService** — `for(Passenger)` → `for(Passenger, ?SegmentDirection $direction = null)`.
Decision needed (see §Questions 3): today the class is the **seat's** class, else the booked class. Proposal:
one-flight direction = unchanged (seat first); multi-flight direction = **booked class** (seats may be in
different cabins on each flight).

**EntryRequirementChecker** — `check(Passenger)` → `check(Passenger, ?SegmentDirection $direction = null)`:
destination country = **final destination** of the direction only (BEY→IST→CDG checks France, not Turkey);
document validity on the first flight's departure date. Transit rules stay out of scope.

**FareService** — `quote(array $passengers, array $extras = [])` → `+ int $flights = 1`. Decision needed
(§Questions 2): proposal = the class/type fare **per flight** (the `fares` table has no route), extras once per
booking.

**ReservationService** — `create(Flight $flight, …)` → `create(Flight|Journey $journey, …)`; a `Flight` is a
one-way journey (old behaviour). Seats chosen at booking apply to the flight they were picked for.

**Station middleware** (`EnsureStationFlight`) — a passenger, document, bag or service belongs to the agent's
station when **any** of the passenger's coupons departs from it (a CDG agent checks in the return of a BEY
booking). A `Flight` parameter is unchanged.

## e) Check-in, bags, boarding

- Check-in at the origin of a direction checks in **every coupon of that direction** (BEY→IST→CDG at BEY: both
  coupons CheckedIn, two boarding passes). The check-in page shows each flight of the direction with its seat.
- Bags are tagged to the **final destination of the direction** (`bags.destination` = CDG) and shown as
  "BEY → IST → CDG".
- **Boarding is per flight**: boarding ME264 at BEY boards only the ME264 coupon; the ME216 coupon stays CheckedIn
  until boarded at IST.
- A **round trip's return** stays Booked when the outbound is checked in and boarded; it is checked in at its own
  origin (CDG), by CDG staff.
- `<x-todays-flights>`, check-in search and gate pages find passengers through coupons on flights departing from
  the station (an IST agent sees the ME216 coupon).

## f) Entry check and allowance

- Entry check: final destination of the direction only. BEY→IST→CDG on a Lebanese passport checks France
  (visa required); Turkey is not checked.
- Allowance: from the booked class (see §Questions 3 for the one-flight case).

## g) Seeder

All current data kept (6 flights, Beirut and Paris staff, same passengers). `arrives_at` set on every seeded
flight (real block times: BEY–CDG 4h45, CDG–BEY 4h15, BEY–LHR 5h05, BEY–DXB 3h, BEY–FCO 3h20, IST–CDG 3h45). Added:

- **Connection BEY→IST→CDG**: new ME264 BEY→IST (departs in 2 h, 2 h flight), connecting to the existing ME216
  IST→CDG (departs in 6 h: a 2 h connection at IST, minimum 60). 3 passengers Booked, Lebanese passports with a
  French visa.
- **Round trip BEY→CDG→BEY**: outbound on ME201 (existing), return on a new ME204 CDG→BEY in 3 days. 2 passengers
  Booked on both directions.

## h) Existing tests I expect to change (each needs your approval)

With the compatibility layer most tests should not change. Expected:

| File | Why |
|---|---|
| `B8SeedersTest` (`flight_statuses`, `flight_origin_and_destination`) | Two new seeded flights (ME204, ME264). |
| `B8SeedersTest` lines 126, 138, 190, 200, 206 | Query `passengers.status`, a column that is dropped: change to the coupon status (`whereHas('coupons', …)`). Same assertions. |
| `Manager/SalesTest` lines 342–343 | Same: `where('passengers.status', …)`. |
| `B3ModelsTest::test_mass_assigning_passenger_status_is_ignored` | Probably unchanged (reads go through the accessor); listed in case the column drop changes `Passenger::create` with unknown attributes. |
| `B13aStationsTimeTest::test_seeded_paris_demo…` | Only if passenger counts per station change with the new seed data. |

Tests that create `SeatAssignment`/`GateOverride` rows directly (B7, B12, C3, C13: 9 places) are expected to stay
unchanged: a `creating` hook fills `coupon_id` from passenger + flight. If any of them still fail, I will list the
exact change and ask before touching it. This list is a forecast; the real list comes from running the suite in
Phase 2.

## i) Rules files

Done in Part 1 (no code): `.cursor/rules/arcs-core.mdc` and `arcs-blade-ui.mdc` say "stored in UTC, shown in
the airport's local time with `<x-local-time>`".

---

## Acceptance tests for Phase 2 (written first, `B13bSegmentsTest`)

- `test_SR60_a_30_minute_connection_at_IST_is_refused` / `..._90_minutes_is_accepted`
- `test_SR60_segments_must_connect` / `test_SR60_return_departs_after_outbound_arrives`
- `test_SR61_check_in_at_BEY_checks_in_both_outbound_coupons`
- `test_SR10_boarding_at_BEY_boards_only_that_flights_coupon`
- `test_SR61_round_trip_return_stays_booked_after_outbound_is_boarded`
- `test_SR63_entry_check_for_BEY_IST_CDG_checks_France_only`
- `test_SR62_bags_are_tagged_to_the_final_destination`
- `test_existing_data_becomes_one_segment_and_one_coupon_per_passenger` (migration test)
- `test_old_single_flight_reservations_work_as_before` (plus the whole existing suite)

## Questions for you

1. **Scope**: confirm connecting flights move into scope (CLAUDE.md + client), and the SR numbers 60–63.
2. **Fares**: fare per flight (proposal) or one fare per journey?
3. **Allowance**: keep "seat's class first" for one-flight directions and use the booked class for multi-flight
   (proposal), or switch everything to the booked class (changes C13 upgrade tests)?
4. **No-show on a connection**: also mark the later coupons of that direction NoShow (proposal)?
5. **Booking screen**: B13b delivers the data model, services, seeder and the pages that must change to keep
   working. Choosing a connection or a return on the reservation screen is a separate card. OK?
