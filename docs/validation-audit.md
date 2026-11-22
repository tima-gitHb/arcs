# ARCS validation audit

Comparison of ARCS validations vs typical airline checks (items 1–48).  
Statuses: **OK** · **CLIENT ONLY** · **NO TEST** · **MISSING** · **WRONG** · **OUT OF SCOPE** · **DEFERRED**.

Updated 22 Nov 2026 after the validation-fix pass (`feature/validation-seat-map`).

---

## RESERVATION

| # | Check | Status | Where (file:line) | Test (file) | Notes / suggested fix |
|---|-------|--------|-------------------|-------------|------------------------|
| 1 | Passenger type by age on travel date (adult 12+, child 2–11, infant &lt;2) | OK | `PassengerType::fromDateOfBirthOn` · `StoreReservationRequest` after() | `Validation/ReservationRulesTest::test_passenger_type_must_match_age_on_travel_date` | — |
| 2 | Max 1 infant per adult; infant linked; infant no seat | OK | `StoreReservationRequest` + `ReservationService::guardInfants` + `Limits::maxInfantsPerAdult` | `Validation/ReservationRulesTest::test_max_one_infant_per_adult_and_under_16_needs_adult` | — |
| 3 | Under 16 cannot travel without an adult | OK | `StoreReservationRequest` + `Limits::childAloneMinAge` | same as #2 | — |
| 4 | Cabin free seats + safety max (was “max 9”) | OK | Cabin free-seat check + `max:Limits::maxPassengersPerBooking()` (default **60**); Group badge `Reservation::isGroup()` (≥10) | `ReservationRulesTest` (group badge); cabin checked in FormRequest | Old fixed **max 9** is **OUT OF SCOPE** for ARCS (not required). |
| 5 | Names: letters, spaces, hyphen, apostrophe; length | DEFERRED | `max:255` only | — | Not in the validation-fix scope; needs regex + i18n review. |
| 6 | Passport not expired on travel date | OK | `StoreReservationRequest` after() vs last flight `departureDate()` | `ReservationRulesTest::test_passport_must_not_be_expired_on_travel_date` | Check-in still uses `passport_min_months` (stricter). |
| 7 | Cannot book departed / closed / boarding | OK | `FlightStatusGate` + bookable statuses in request/controller/JourneyService | `ReservationRulesTest::test_boarding_flight_cannot_be_booked`; `R1CreateReservationTest` | See #41. |
| 8 | Seats sold ≤ cabin capacity; concurrent agents | OK | Seat lock + UNIQUE + cabin free count | `R1SeatMapTest`; `C3SeatTest` | — |
| 9 | Same passenger not twice on same flight | DEFERRED | Duplicate seat only | — | Needs passport/name uniqueness; not in fix scope. |
| 10 | Multi-city: date order; MCT | OK | `JourneyService` | `B14MultiCityLegsTest`, `R11BookingBuilderTest` | — |
| 11 | Round trip: return after outbound | OK | `JourneyService::ensureRoundTrip` | `R11BookingBuilderTest` | — |
| 12 | Price stored at booking | OK | `payments.amount_cents` | `R9PaidBookingTest`, `R12JourneyPriceTest` | Receipt lines may re-quote. |
| 13 | Card: Luhn, expiry, CVC; no double charge | OK | `Luhn` + expiry/CVC in `StoreReservationRequest`; `booking_token` cache idempotency in `ReservationController::store` | `ReservationRulesTest::test_luhn_and_booking_token_block_double_submit` | — |
| 14 | Cancel releases seats and cancels coupons | OK | `StatusService::cancel` | `R3CancelPassengerTest` | — |

---

## CHECK-IN

| # | Check | Status | Where | Test | Notes |
|---|-------|--------|-------|------|-------|
| 15 | Check-in only inside window | OK | `StatusService` + `FlightStatusGate::CheckIn` | `C1CheckinSearchTest` | — |
| 16 | Leg order; no-show/cancelled cannot check in | OK | `ensureBooked` | `C1CheckinSearchTest` | — |
| 17 | Passport valid; visa/entry | OK / product | Passport blocks; entry advisory | `C2*`, `C8*` | Entry remains advisory (CLAUDE.md). |
| 18 | Exit rows: adults only; no UMNR/WCHR/… | OK | `ExitRows` + `SeatService::assertExitAllows` | `CheckinGateRulesTest::test_exit_row_only_for_adults_without_mobility_or_umnr` | Rows 14–15 (mirrors JS). |
| 19 | Seat in passenger cabin unless paid upgrade | OK | `SeatService::assign` + `assertCabinAllows`; upgrade path skips cabin | `CheckinGateRulesTest::test_assign_enforces_passenger_cabin` | — |
| 20 | Max 32 kg; heavy 23; excess before BP | OK | `BagService` / `BagTagService` / `BoardingPassService` | `C10*`, `C11*` | — |
| 21 | BP after docs + payment | OK | Check-in stamps `verified_at`; excess paid | `C5*`, `C10*` | — |

---

## GATE

| # | Check | Status | Where | Test | Notes |
|---|-------|--------|-------|------|-------|
| 22 | Scanning only when Boarding | OK | `FlightStatusGate::Board` | `G2*`, `G6*` | — |
| 23 | Reject wrong flight / not CI / already boarded | OK | `StatusService::board` | `G2*`, `G6*` | — |
| 24 | No-show bags offload | OK | `closeBoarding` | `G3*`, `G4*` | — |
| 25 | Boarded + no-show = checked-in accounting | OK | `ManifestService` | `G3*` | — |

---

## MANAGER

| # | Check | Status | Where | Test | Notes |
|---|-------|--------|-------|------|-------|
| 26 | Flight OD / arrival / flight_no | OK | `StoreFlightRequest` | `Manager/FlightTest` | — |
| 27 | Aircraft no overlap + turnaround | OK | `FlightScheduleService::assertNoOverlap` via `StoreFlightRequest` | `ManagerRulesTest::test_aircraft_cannot_fly_overlapping_flights` | Default 45 min (`Limits`). |
| 28 | Cannot move to smaller aircraft than sold | OK | `Flight` updating + `FlightScheduleService::assertAircraftFitsSold` | `ManagerRulesTest::test_cannot_move_flight_to_aircraft_with_fewer_cabin_seats_than_sold` | — |
| 29 | Allowed status moves | OK | `StatusService::FLIGHT_MOVES` | `Manager/FlightTest` | — |
| 30 | Aircraft unique; seats ≤ max; biz+prem ≤ rows | OK | `StoreAircraftRequest` (server max seats) + Alpine UI | `ManagerRulesTest::test_aircraft_total_seats_cannot_exceed_limit` | Was CLIENT ONLY for max seats. |
| 31 | Airport IATA / TZ / no delete | OK | `StoreAirportRequest`; no destroy | `Manager/AirportTest` | — |
| 32 | Fares &gt; 0; route fare required | DEFERRED | Zero + defaults allowed by design | — | Product uses default fares; changing would break R12. |
| 33 | Staff email / station / self-deactivate / last manager | DEFERRED | Station nullable; no last-manager rule | — | Needs product decision. |

---

## WHOLE SYSTEM

| # | Check | Status | Where | Test | Notes |
|---|-------|--------|-------|------|-------|
| 34 | Role × route matrix | OK | Middleware `role:` | `Validation/RoleMatrixTest::test_every_role_route_is_allowed_or_403` | — |
| 35 | Client-only also on server | OK | Max seats, Limits, cabin assign, exit rows, booking_token | Validation suite | Wizard step UX remains client-only (POST still validated). |
| 36 | Times UTC / local | OK | `StoresTimesInUtc`, local-time components | `B13aStationsTimeTest` | DST transition still light. |
| 37 | Lockout / password / session | OK | Auth stack | `B4*`, `B11*` | — |
| 38 | Audit every change | DEFERRED | Account create/unlock still unaudited | — | Small follow-up. |
| 39 | Styled 403/404/419/500 | DEFERRED | No `resources/views/errors` | — | Needs design pass. |
| 40 | Bookings/payments never hard-deleted | OK | No destroy APIs | — | — |

---

## AIRLINE RESTRICTIONS

| # | Check | Status | Where | Test | Notes |
|---|-------|--------|-------|------|-------|
| 41 | Flight status × action matrix | OK | `App\Support\FlightStatusGate` used by book / seat / check-in / board | `ReservationRulesTest`, `CheckinGateRulesTest` | Scheduled/Delayed = book+change; CheckinOpen = +check-in; Boarding = board only (+ late-CI seat assign for Booked). |
| 42 | Max 1 infant per seat block (ABC/DEF) | OUT OF SCOPE | — | — | Not required for ARCS. |
| 43 | Max infants per flight (10% seats) | OUT OF SCOPE | — | — | Not required for ARCS. |
| 44 | UMNR age 5–11 / max 4 / flight | OUT OF SCOPE | — | — | Not required for ARCS. |
| 45 | PETC max 2 / flight | OUT OF SCOPE | — | — | Not required for ARCS. |
| 46 | Booking opens max 355 days before | OUT OF SCOPE | — | — | Not required for ARCS. |
| 47 | Name change ≤ 3 letters after ticketing | OUT OF SCOPE | — | — | Not required for ARCS. |
| 48 | Gate refuses unpaid excess / unverified docs | OK | `StatusService::assertReadyToBoard`; check-in stamps `verified_at` | `CheckinGateRulesTest::test_gate_refuses_unverified_docs_or_unpaid_excess` | — |

---

## Totals (after fix)

| Status | Count |
|--------|------:|
| OK | 33 |
| OUT OF SCOPE | 7 |
| DEFERRED | 6 |
| CLIENT ONLY | 0 |
| NO TEST | 0 |
| MISSING | 0 |
| WRONG | 0 |
| **Total** | **46 checklist rows** (4 was rewritten; old max-9 noted as OOS) |

OUT OF SCOPE items: **42–47** and the old **fixed max-9** party size (replaced by cabin capacity + safety max 60 + Group badge).

---

## Fix first (historical — completed this pass)

1. #41 / #7 FlightStatusGate  
2. #48 Gate docs + excess  
3. #4 Cabin capacity + max 60 + Group  
4. #2 Infants per adult  
5. #3 Under 16 needs adult  
6. #1 Type vs age  
7. #13 Luhn + booking_token  
8. #19 Cabin on assign  
9. #18 Exit rows  
10. #27 Overlap / turnaround  
11. #28 Aircraft downsize  
12. #34 RoleMatrixTest  
13. #30 Server max aircraft seats  
14. #6 Passport on travel date  
15. #35 Client-only → server  
