# ARCS — Airline Reservation & Check-in System

Team project, Software Development course (Antonine University).
Team: Tima Karanouh (lead), Yasmina Souki, Serge Kahwaji. Client (role-play): Kamal's team.
Requirements: `docs/ARCS_BRD_v1.0.docx` (BRD). Every feature traces to an SR-ID in the BRD.

## What ARCS is
- A **staff-only** web app (no passenger side). Payments: card only, test mode, fake gateway.
- A reservation is a **journey** of one or more flights: one-way, connection (BEY→IST→CDG) or round trip (B13b,
  agreed client change request).
- Covers reservation → check-in → baggage → seat → boarding, and **enforces the order**.
- Four roles, one per account: `reservation`, `checkin`, `gate`, `manager`.
- One airline's system, used by staff at every airport of its network; each staff member works at a station (airport).

## Stack
- Laravel 12, PHP 8.2, MySQL (managed with MySQL Workbench), Blade + Breeze, Tailwind, Vite.
- Tests: PHPUnit. Run `php artisan test` before every commit.
- Desktop browsers only (Chrome, Edge).

## Domain rules (never break these)
- **Passenger status** (enum `PassengerStatus`): `Booked`, `CheckedIn`, `Boarded`, `Cancelled`, `NoShow`, `Exchanged`.
  - Allowed moves only:
    - Booked → CheckedIn (check-in staff; flight status `CheckinOpen`; inside the check-in window; valid travel document) — SR-6, SR-23, SR-30
    - Booked → CheckedIn at the gate, after check-in has closed and before boarding closes: late check-in,
      approved on the spot by a Manager with a written reason (`gate_overrides` row). Skips only the window and
      flight-status rule; documents and seat still apply — `StatusService::lateCheckIn()`, SR-29
    - Booked → Cancelled (reservation staff; seat released) — SR-5
    - Booked → Exchanged (reservation staff, ticket exchange / CR-19): `StatusService::exchangeCoupons()` when a
      leg is replaced; old seats released; new coupons stay Booked on the new flights. Exchanged coupons are
      inactive on check-in lists and manifests (`isInactiveOnFlight()`).
    - CheckedIn → Boarded (gate staff; flight status `Boarding`) — SR-10
    - CheckedIn → NoShow (automatically when gate staff close boarding) — SR-12
  - `Boarded`, `Cancelled`, `NoShow`, `Exchanged` are final.
- **Only `App\Services\StatusService` may change a passenger's status.** It checks the rule,
  saves the change and writes an `audit_log` row (SR-26). Never set a status anywhere else.
- **Segments and coupons** (B13b, design: `docs/design/B13b-segments.md`):
  - `reservation_segments`: the flights of a reservation in order (`sequence`), each `Outbound` or `Return`
    (enum `SegmentDirection`). `reservations.flight_id` = the first outbound flight (`$reservation->flight`);
    for every flight use `$reservation->segments`.
  - `coupons`: one per passenger per segment. **The passenger status lives on the coupon** (with `checked_in_at`,
    `boarded_at`); seat, boarding pass, gate override and audit row point at it (`coupon_id`).
    `passengers` has no status column any more: `$passenger->status` / `checked_in_at` / `boarded_at` read the
    passenger's current coupon (the first one, or the one a page loaded for its flight), so a one-flight
    reservation works exactly as before. In SQL, query `coupons.status`, never a passenger status.
  - The first segment is created with the reservation and a coupon per segment with each passenger (model events:
    do not use `WithoutModelEvents` in seeders). Later segments only through `JourneyService::addSegments()`.
  - **Journey rules (SR-65), only in `App\Services\JourneyService`**: each flight departs where the previous one
    lands; the gap is at least the connecting airport's `min_connection_minutes`; a flight followed by another needs
    `flights.arrives_at`; a return starts where the outbound ends, ends where it started, and leaves after the
    outbound lands. Max 3 flights per direction.
  - **Check-in covers a whole direction (SR-66)**: every coupon of the direction, all or none, at the direction's
    origin (the return is checked in at its own origin by that station). Window and `CheckinOpen` on the first
    flight; documents and a seat on every flight. One boarding pass per flight. **Boarding is per flight**
    (`board($passenger, $by, $flight)`); a round trip's return stays Booked until its own check-in.
  - Bags go to the **final destination of their direction** (`bags.direction`, `bags.destination`, SR-67). Each
    direction has its own allowance and bags. Allowance: one-flight direction = seat cabin first, then booked
    class; connection = booked class.
  - Entry check on the **final destination of the direction only** (SR-68). Fares are **per flight**: journey
    price = sum of each flight's fare, `FareService::quote(..., flights: n)` (SR-69).
  - Services take an optional direction or flight; null = the direction whose first flight departs from the
    agent's station (`JourneyService::directionFor()` / `firstFlightFor()`), else the first flight.
  - `StatusService::cancelLaterCoupons()` exists for card G7 (no-show on a connection) and is not called yet;
    `closeBoarding()` only touches coupons of its own flight.
- **Flight status** (enum `FlightStatus`): `Scheduled`, `CheckinOpen`, `Boarding`, `Closed`, `Departed`, `Delayed`, `Cancelled` — SR-25.
  - A flight cannot go to `Departed` until every no-show bag is offloaded (enforced in `setFlightStatus()`) — SR-28.
- **No double seat:** `seat_assignments` has UNIQUE (`flight_id`, `seat_id`). Assign seats inside a
  DB transaction and catch the unique-constraint error → show "Seat taken" (SR-7).
- An **Infant** has no seat and must have `accompanying_adult_id` (SR-24).
- PNR: 6 characters, A–Z and 2–9, unique, generated by `App\Services\PnrGenerator` (SR-1).
- Passport/ID numbers are shown in full only to `checkin` and `manager`; others see `••••1234` (SR-34).
- **Bags** (enums `BagType`, `BagHandling`): a wheelchair or stroller does **not** count toward the baggage
  allowance — use `BagType::countsTowardAllowance()` (SR-43).
- **Special services** (enum `SpecialServiceCode`, IATA codes): codes where `requiresDetails()` is true
  (UMNR, PETC, AVIH, DPNA, MEDA) must have `details` (SR-44).
  **UMNR only for a Child with no Adult on the same PNR.** (Both validated in task C7.)
- **Airports and stations** (B13a): one airline, staff at every airport of its network. The `airports` table
  (`code` IATA primary key, name, city, ISO-2 `country`, IANA `timezone`, `min_connection_minutes`) is filled by its
  migration; `flights.origin`/`destination` and `users.airport_code` (the staff member's **station**, nullable) are
  foreign keys to it. `config/airports.php` no longer exists: the entry check reads the country from `airports`.
  - Check-in and gate staff work **only on flights departing from their station**: their pages and
    `<x-todays-flights>` list only those, and any action on another station's flight is 403 (middleware `station`).
    Without a station they see "No station set: ask your manager".
  - Reservation staff book any flight (their today's flights default to their station). The Manager sees every station.
  - Query scopes: `Flight::departingFrom($code)` (a station's departures) and `Flight::departingToday($user)`
    ("today" = the calendar day at the user's station, UTC without one). `$user->worksAt($flight)`.
- **Times are stored in UTC, shown in local time** (B13a). `config/app.php` timezone is `UTC` and the MySQL connection
  is `+00:00` (not env settings). Models with `StoresTimesInUtc` convert any date-time given to them to UTC.
  Compare instants (`departure_at`, check-in window, boarding) directly; never compare formatted local times.
  - **Never format a stored time directly** (`->format()` on `departure_at`, `changed_at`, ...). Use:
    - Flight times, in the airport's time (departure and check-in at the origin):
      `<x-local-time :at="$flight->departure_at" :airport="$flight->origin" />`, `$flight->departureLocal()`,
      `$flight->atOrigin($time)`, or `App\Support\LocalTime::at($time, $code)` in PHP (mail, services).
      Arrival in the destination's time: `$flight->arrivalLocal()` or
      `<x-local-time :at="$flight->arrives_at" :airport="$flight->destination" />` (B13b; null when not set).
    - When something happened (audit log, payments, logins, check-in by...): `<x-station-time :at="..." />`, in the
      viewer's station time; a viewer without a station sees UTC, labelled "UTC".
  - Date filters ("payments on 14 Nov") are a day at the viewer's station: `$user->stationDay($date)`.
  - Document validity is checked against `$flight->departureDate()`, the departure date at the origin airport.
  - The Manager's flight form takes times in the origin airport's local time.
- **Money is always integer cents** (`amount_cents`), currency USD. Show amounts with `App\Support\Money::format()`.
- **Never store a card.** The full card number and the CVC are never stored, logged, flashed or returned;
  only the brand, last 4 digits and gateway reference are kept.
- **AllowanceService is the only place to read an allowance. FareService is the only place to compute a price.
  PaymentService is the only way to take a payment.**
- **A password reset never unlocks an account.** Locked or deactivated accounts get no reset email;
  only the Manager unlocks (SR-35, SR-37). New passwords: at least 8 characters, letters and numbers (SR-38).
- **Entry check** (SR-49, pending client approval): `App\Services\EntryRequirementChecker` is advisory. It reads the
  read-only `entry_rules` table (Passport Index snapshot, rules as of 12 January 2025) and **never blocks check-in**:
  `StatusService` knows nothing about it. On a missing or refused result, `BoardingPassService` requires a written
  reason and writes it with the result to `audit_log` (field `entry_override`). Nobody edits the rules in the app.
  Schengen: for a destination in `config/schengen.php`, a valid visa or residence permit from any member counts, not
  only one from the destination. The 90/180-day stay limit is not checked. Transit rules are not covered.
- Staff never type or read country codes: the database stores ISO-2 codes, `App\Services\CountryList` gives the names.
- Out of scope (never build): online/passenger check-in, blocking automatic visa checks and live/external visa
  or APIS checks, external systems (GDS, APIS), transit visa rules, payroll.
  (Refunds CR-18, exchanges CR-19 and weekly schedules CR-17 are built under change control.)

## Database (24 tables)
users, aircraft, seats, flights, reservations, passengers, seat_assignments, bags,
boarding_passes, special_services, travel_documents, audit_log, baggage_allowances,
gate_overrides, settings, entry_rules, fares, fees, payments, airports, reservation_segments, coupons,
flight_schedules, trip_fares.
- **Never edit an existing migration** once it is on `dev` or `main`. Schema change = new migration,
  agreed with the team first.
- Foreign keys always declared (`constrained()`), with `cascadeOnDelete()` only for seats → aircraft.

## Code organisation
- One folder per role:
  - Controllers: `app/Http/Controllers/{Reservation,Checkin,Gate,Manager}/`
  - Views: `resources/views/{reservation,checkin,gate,manager}/`
  - Routes: grouped by role in `routes/web.php`, each group behind `middleware(['auth', 'role:<role>'])`.
- Business rules live in `app/Services/`, not in controllers or views.
- Enums in `app/Enums/`.
- Shared layout: `resources/views/layouts/app.blade.php` (header with role badge + role menu).
- **Shared building blocks — use them, do not rebuild them:**
  - Journeys (B13b): `JourneyService` (`validate`, `addSegments`, `directionFor`, `firstFlightFor`, `couponsOf`,
    `flightsOf`, `finalDestination`, `isConnection`, `statusOf`), value object `App\Support\Journey`.
    On a page about one flight, eager-load that flight's coupon and seat:
    `with(['coupon' => fn ($q) => $q->where('coupons.flight_id', $id), 'seatAssignment' => fn ($q) => $q->where('seat_assignments.flight_id', $id)])`
    (see `ManifestService`). `Flight::passengers()` goes through coupons; `Bag::noShowOn($flight)`.
  - Who did it (SR-45): `$passenger->checkedInBy`, `boardedBy`, `cancelledBy`, `markedNoShowBy` give the
    `audit_log` row (`->user`, `->changed_at`) or null. In lists, eager-load: `Passenger::with('checkedInBy.user')`.
  - Today's flights (SR-41): `<x-todays-flights :role="auth()->user()->role" />`.
  - Document numbers (SR-34): any page that shows a travel document number must use
    `$document->maskedNumberFor(auth()->user())` — never print `$document->number` directly.
  - Allowance: `AllowanceService::for($passenger)` gives pieces, kg per bag, total kg and the hard max. The class is
    the seat's, else the booked `passengers.cabin_class`; an infant uses the adult's. Extras: `extra_bags`, `extra_kg`.
  - Prices: `FareService::quote($passengers, $extras)` gives lines and a total in cents, from the `fares` and `fees`
    tables. Enum `FeeCode`. Show amounts with `Money::format()`.
    Cancellation / Change fees (Sprint 4): `FeeCode::Cancellation` / `FeeCode::Change`;
    `FareService::cancellationFee($passengers, $legs)` and `changeFee($passengers, $legs)` (per passenger × per leg).
  - **Route fares** (B13b, SR-69): a `fares` row with `origin_airport_code` + `destination_airport_code` is the price on
    that route; a row with neither is the **default**. `quote(..., origin: 'BEY', destination: 'IST')` uses the route
    fare when it exists, else the default; without a route it uses the default, as before. One fare per
    (route, class, type), defaults included (unique key on generated `route_origin` / `route_destination`).
    `FareService::journeyQuote($reservation)` = each flight's route fare per passenger, added up (no UI yet).
    Pages that show "the" fares read `Fare::query()->defaults()`; the M7 page edits defaults only.
    The Fares page with a route selector is card M14; the booking screen using route fares is card R11/R12.
  - Payments: `PaymentService::charge(...)` through `App\Contracts\PaymentGateway` (bound to `FakeCardGateway`: 4242 4242
    4242 4242 accepted, anything else declined). Card data travels in `CardDetails`. Enums `PaymentKind`, `PaymentStatus`.
  - **Refunds** (CR-18): `RefundService` + `PaymentService::refund`. Kind `PaymentKind::Refund`, status
    `PaymentStatus::Refunded`, linked with `refund_of_payment_id`; amount is positive cents; optional `breakdown` JSON
    (same structure as the original fare charge, with a fallback when old bookings have no snapshot). Net revenue
    on Sales / dashboard subtracts refunds. Cancelling one leg of a multi-city journey is supported.
  - **Exchanges** (CR-19): `ExchangeService` changes one leg (same origin→destination) for selected passengers.
    Pricing: new leg fare − paid for that leg; higher → difference + change fee; lower/equal → fee only (no fare
    refund). Charge is `PaymentKind::Exchange`. Old coupons → Exchanged via StatusService; new segments/coupons/seats;
    same PNR; e-ticket resent with a "Changed on …" line.
  - **Weekly schedules** (CR-17): `FlightSchedule` + `GenerateFlightsFromSchedule` (Manager UI and
    `php artisan arcs:generate-flights`). Local departure clock; aircraft clash skips a date; edits touch future
    unbooked flights only.
  - **FlightStatusGate** / `FlightAction`: shared allow/deny for book, check-in, refund, exchange, etc. Prefer the
    gate over ad-hoc status lists in controllers.
  - **ConnectionTime**: labels and "tight" connection chips between two flights (check-in / itinerary).
  - **Check-in per leg** (C23 / CR-16): `reservation_segments.leg`; `JourneyService::legFor` / `couponsOfLeg` /
    `flightsOfLeg`. Check-in moves a whole leg; boarding stays per flight.
  - **Passenger email** (CR-12): `passengers.email` optional; check-in / boarding-pass mail uses passenger email,
    else `reservations.contact_email`. Desk edits go through `PassengerService::updateEmail` (audit field `email`).
  - Reservation contact: `reservations.contact_email`, `contact_phone`; rules in `ReservationContactRules::rules()`.
  - Cabins: enum `CabinClass` is Business, Premium, Economy. `AircraftService` places `premium_rows` after the Business rows.
  - Flight audit rows: `AuditEntry::forFlight(...)` (`audit_log.flight_id`; `passenger_id` can be null).
  - **Email** (B13c): every email uses the ARCS layout, i.e. a markdown mailable (`<x-mail::message>`) or a notification
    `MailMessage`. Both render through `resources/views/vendor/mail/{html,text}/message.blade.php` (ARCS header and
    footer); never build an email with its own layout. Tests use `Mail::fake()` / `Notification::fake()`
    (phpunit.xml: `MAIL_MAILER=array`). Development: Mailtrap sandbox or `MAIL_MAILER=log`.
    **Never put real mail credentials in the repo**: they go in `.env` only; `.env.example` keeps them empty.
  - **PDF** (B13c): `App\Support\Pdf::fromView($view, $data, $filename)` returns the download response;
    `Pdf::raw($view, $data)` returns the PDF bytes for an email attachment (`Attachment::fromData()`). Never call
    dompdf directly. PDF views are full HTML pages with plain CSS (no Tailwind, no JavaScript).
- Names: models singular (`Passenger`), tables plural snake_case (`seat_assignments`),
  routes `role.resource.action` (e.g. `checkin.seats.assign`).

## How we work
- **Branches:** `main` = milestone releases, `dev` = integration, one `feature/<task-id>-<short-name>` per task.
  - One task = one branch = one Claude conversation (e.g. `feature/C3-seat-map`).
  - Create every feature branch from an up-to-date `dev`.
  - Merge the feature branch into `dev` through a pull request, only when CI is green.
  - Only the team lead merges `dev` into `main`, at milestones.
  - **Never push or commit directly to `main` or `dev`.**
- **Test first:** write the test from the SR's acceptance criterion, see it fail, then write the code.
  Each Must SR has at least one feature test named after it (e.g. `test_SR7_two_agents_cannot_take_same_seat`).
- Commit messages start with the task ID: `C3: seat map with taken seats blocked (SR-7)`.
- Before a pull request: `php artisan test` passes, `./vendor/bin/pint` run, page tested in the browser.
- pint.json keeps the php_unit_method_casing rule off so test names like test_SR7_... are not renamed.
- After every git pull: composer install, npm install, npm run build, php artisan migrate.
- If a request conflicts with a rule above or with the BRD scope, **stop and say so** instead of building it.
- Every member must be able to explain the code in their branch.

## Testing
- **Test first:** write the feature test from the SR's acceptance criterion, run it and see it fail, then write the code.
- Name tests after the SR: `test_SR<n>_<what_it_checks>` (e.g. `test_SR7_two_agents_cannot_take_same_seat`).
- Use the helpers in `tests/TestCase.php`:
  - `actingAsRole('checkin')` — creates a user with that role and logs in.
  - `flightWithStatus(FlightStatus::Boarding)` — a flight in that status, reached through `StatusService`,
    with the check-in window open around `now()`. Use `$this->travelTo()` for fixed times.
  - Never set a status directly in a test; reach it through `StatusService`.
  - A multi-flight journey in a test: `Reservation::factory()` for the first flight, then
    `JourneyService::addSegments($reservation, new Journey([...], [...]))`, then the passengers (see `B13bSegmentsTest`).
  - Never use the real clock with seeded data or "today": the seeder works relative to `now()`, so set a fixed
    morning with `travelTo()` first.
- Factory users work at BEY (`airport_code`); pass another station or `null` when the test needs it.
- Test clocks are UTC: `travelTo('2026-11-10 10:00')` is 12:00 in Beirut. When a page shows a time, set the clock in
  the staff member's zone: `travelTo(Carbon::parse('2026-11-10 10:00', 'Asia/Beirut'))`.
- `php artisan test` (SQLite in memory, fast) before every commit.
  `composer test:mysql` runs the same suite on the local MySQL database `arcs_test` — use it when a change
  depends on MySQL behaviour (unique keys, row locks, transactions). See `.env.testing.example`.
- GitHub Actions (`.github/workflows/tests.yml`) runs tests on SQLite and MySQL 8, plus Pint, on every push and
  pull request. **CI must be green before merging.**
