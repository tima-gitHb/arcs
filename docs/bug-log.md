# ARCS bug log — every error or crash, see the Whole team card

Entries are numbered in date order (oldest first). Renumbered 18 Nov 2026 so BUG-001…013 match chronology.

## BUG-### · short title
- Date / who:
- Card / branch:
- Where:
- Steps:
- Expected:
- Actual:
- Status:
- Fix:
- Retested:

## BUG-001 · Extra bag charged twice (as a piece and as kilograms)
- Date / who: 9 Nov 2026 · Tima
- Card / branch: C10 · found in the C10 pull-request review
- Where: BagService excess calculation, check-in bags
- Steps: 1. Check in a passenger 2. Add bags within the allowance, then one extra bag
- Expected: the extra bag is charged once (piece fee)
- Actual: the extra bag was charged as a piece AND its weight was counted again as excess kilograms
- Screenshot: none (found in code review)
- Status: Fixed
- Fix: pool by pieces first, then weight; covered by test_SR59_extra_bag_does_not_double_charge_weight
- Retested: after merge into dev · Tima

## BUG-002 · A missing fee gave a server error (500)
- Date / who: 9 Nov 2026 · Tima
- Card / branch: C10 · found in the C10 pull-request review
- Where: check-in excess payment, FareService fee lookup
- Steps: 1. Delete a fee on the manager Fees page 2. Add an excess bag at check-in
- Expected: a clear message, and confirm stays blocked
- Actual: 500 server error, because FareService returned nothing for a fee the Manager had not set
- Screenshot: none (found in code review)
- Status: Fixed
- Fix: show "Price not set" and keep confirm blocked; covered by test_SR59_missing_fee_shows_price_not_set_and_still_blocks_confirm
- Retested: after merge into dev · Tima

## BUG-003 · CI (SQLite) red on the C2 travel-document test after times moved to UTC
- Date / who: 13 Nov 2026 · Tima
- Card / branch: B13a (Sprint 3 base) · CI on the pull request
- Where: tests/Feature/C2TravelDocumentsTest, GitHub Actions SQLite job
- Steps: 1. Store all times in UTC (B13a) 2. Run the suite in CI
- Expected: green
- Actual: C2 test failed, because it used the real clock while dates were now compared in UTC
- Screenshot: none (CI log)
- Status: Fixed
- Fix: frozen clock in Asia/Beirut in C2TravelDocumentsTest setUp
- Retested: 13 Nov 2026 · CI green

## BUG-004 · Paris demo test failed when run after 20:00 UTC
- Date / who: 13 Nov 2026 · Tima
- Card / branch: B13a (Sprint 3 base) · evening test run
- Where: test_seeded_paris_demo_has_return_flights_with_passengers
- Steps: 1. Run the suite after 20:00 UTC
- Expected: green at any hour
- Actual: failed, because with the real clock "4 hours later" is already tomorrow in Paris
- Screenshot: none (terminal)
- Status: Fixed
- Fix: travelTo in Europe/Paris (commit 76a936a)
- Retested: 13 Nov 2026 · evening run green

## BUG-005 · Audit date filter missed a row between 22:00 and 24:00 UTC
- Date / who: 13 Nov 2026 · Tima
- Card / branch: B12 (Sprint 2 base) / M5 audit · evening test run
- Where: B12Sprint2BaseTest, M5 audit date filter test
- Steps: 1. Run the suite between 22:00 and 24:00 UTC
- Expected: the row from "today" is found
- Actual: the row was missed, because with the real clock it is already the next day in Beirut
- Screenshot: none (terminal)
- Status: Fixed
- Fix: travelTo in Asia/Beirut in the test
- Retested: 13 Nov 2026 · evening run green

## BUG-006 · MySQL: "Data too long for column seat_no" in 8 tests
- Date / who: 15 Nov 2026 · Tima
- Card / branch: C13 + C14 · CI MySQL 8 job
- Where: C14ThroughCheckinTest, B13bSegmentsTest (test helper)
- Steps: 1. Run the full suite on MySQL 8
- Expected: green on MySQL like on SQLite
- Actual: 8 tests failed, because the test helper built the seat number from the database id, and MySQL ids keep growing across the suite
- Screenshot: none (CI log)
- Status: Fixed
- Fix: the helper picks a short free seat (commit 2cba5eb)
- Retested: 15 Nov 2026 · CI MySQL green

## BUG-007 · A connecting passenger's upgrade was audited on the wrong flight
- Date / who: 15 Nov 2026 · Tima
- Card / branch: C16 · security tests
- Where: PaymentService::charge, audit_log
- Steps: 1. Check in a connecting passenger 2. Pay a cabin upgrade on the second flight
- Expected: the audit row names the flight that was paid for
- Actual: the audit row named the first flight, because PaymentService did not know which flight was paid for
- Screenshot: none (found by a test)
- Status: Fixed
- Fix: PaymentService::charge(…, ?Flight); covered by C16CheckinPaymentSecurityTest
- Retested: 15 Nov 2026 · Tima

## BUG-008 · Fares page crashes when a route fare exists and no flight uses it
- Date / who: 16 Nov 2026 · Yasmina
- Card / branch: M14 · feature/M14-fares-per-route
- Where: tests/Feature/B13bRouteFaresTest (the GET manager.fares page test), during M14 development
- Steps: 1. Seed a route fare (BEY→IST) with no flight on that route 2. Open the Fares page with the M14 route selector
- Expected: the page lists the routes
- Actual: Expected response status code [200] but received 500. Failed asserting that 500 is identical to 200. The following exception occurred during the last request: Error: Call to a member function getKey() on string in C:\softwareProject\vendor\laravel\framework\src\Illuminate\Database\Eloquent\Collection.php:401 Stack trace: #0 C:\softwareProject\app\Http\Controllers\Manager\FareController.php(203): Illuminate\Database\Eloquent\Collection->merge(Object(Illuminate\Support\Collection))
- Screenshot: none — found by an automated test (B13bRouteFaresTest), not in the browser; full error message above.
- Status: Fixed
- Fix: route lists turned into plain collections before merging, commit on feature/M14-fares-per-route; covered by B13bRouteFaresTest and FareRouteTest
- Retested: 16 Nov 2026 · Yasmina — Fares page with BEY→IST loads on dev

## BUG-009 · Saving MXP makes find('mxp') return that airport on MySQL
- Date / who: 16 Nov 2026 · Yasmina
- Card / branch: M13 · feature/M13-airports-stations
- Where: tests/Feature/Manager/AirportTest (adding an airport uppercases the code), composer test:mysql
- Steps: 1. Post a new airport with code mxp 2. The row is stored as MXP 3. Call Airport::find('mxp')
- Expected: the lowercase lookup misses, because the saved code is MXP
- Actual: Failed asserting that an Airport with code MXP is null. SQLite's lookup is case-sensitive, so the same assertion passed there. MySQL compares airports.code without case, so find('mxp') returns the MXP row.
- Screenshot: none — found by an automated test (AirportTest on MySQL), not in the browser; full error message above.
- Status: Fixed
- Fix: the test asserts the stored code is MXP and that the network grew by one row, instead of a case-sensitive miss. The page already uppercases the code before saving.
- Retested: 16 Nov 2026 · Yasmina — adding MXP stores code MXP; the test passes on MySQL

## BUG-010 · Confirmation page crashes when no fare exists
- Date / who: 18 Nov 2026 · Serge (found on dev); Yasmina (test fix)
- Card / branch: R3 · fix/BUG-003-r3-test-fares
- Where: tests/Feature/R3CancelPassengerTest::test_SR5_cancelling_a_booked_passenger_frees_the_seat, the reservation confirmation page
- Steps: 1. Create a reservation and an Economy adult with no fares seeded 2. Open the confirmation page
- Expected: the page loads (200) and shows Cancel
- Actual: Expected response status code [200] but received 500. App\Exceptions\FareNotFound: No published fare for Economy Adult. Ask a manager to set prices (card M7), then try again. The page calls FareService::journeyQuote() and the test had created no fares.
- Screenshot: none — found by an automated test, not in the browser; full error message above.
- Status: Fixed
- Fix: R3 test now seeds fares (fix/BUG-003-r3-test-fares). Note: the confirmation page still returns 500 when no fare exists at all; follow-up for Serge (show 'price unavailable' instead).
- Retested: 18 Nov 2026 · Yasmina — R3CancelPassengerTest green on dev after the fix

## BUG-011 · A failed flight-change email is not retried
- Date / who: 18 Nov 2026 · Yasmina
- Card / branch: M16 · feature/M16-flight-notifications
- Where: M16 manual Mailtrap check, the delay email for ME203
- Steps: 1. Change ME203's gate to B7, which sends one email 2. Immediately set ME203 to Delayed with a new departure, which sends a second email
- Expected: both emails are delivered. A temporary refusal is tried again.
- Actual: Mailtrap rejected a flight-change email with 550 5.7.0 Too many emails per second during the M16 manual check (delay email for ME203); a queued job that fails is not retried, so the passenger would never get it.
- Screenshot: none (found in the terminal during the manual check).
- Status: Fixed
- Fix: FlightChangeMail retries ($tries = 3, backoff [10, 30]) on feature/M16-flight-notifications, plus a test that the mailable defines tries and backoff.
- Retested: 18 Nov 2026 · Yasmina — FlightNotificationTest asserts tries is 3 and backoff is 10 then 30

## BUG-012 · E-ticket email shows raw HTML for the payment line
- Date / who: 18 Nov 2026 · Tima
- Card / branch: R15 · found during Sprint 3 manual test on feature/C17-G7-checkin-email-gate
- Where: Mailtrap, "ARCS e-ticket JBGNWJ" email, Amount paid section
- Steps: 1. Book BEY→IST→CDG (ME264 + ME216) and pay with Visa 4242 2. Open the e-ticket email in Mailtrap
- Expected: "Visa ••••4242 · reference fake_… · 18 Nov 2026 22:49" shown as normal text
- Actual: raw code is shown in a grey code block: <p class="mt-3 text-sm font-medium text-gray-800"> Visa ••••4242 · reference <span class="font-mono">fake_…</span> · 18 Nov 2026 22:49 </p></div>
- Screenshot: Mailtrap screenshot taken 18 Nov 2026 22:52 (manual test)
- Status: Open
- Fix:
- Retested:

## BUG-013 · Today's flights hides a flight after midnight while its check-in is open
- Date / who: 18 Nov 2026 · Tima
- Card / branch: Check-in page "Today's flights" (TodaysFlights component) · found during Sprint 3 manual test
- Where: /checkin, Today's flights panel, logged in as checkin@arcs.test (BEY)
- Steps: 1. At 22:56 local, open the check-in page 2. ME264 BEY→IST departs 19 Nov 00:28, check-in window 21:28–23:43 3. Find PNR JBGNWJ works and shows "Check-in open"
- Expected: ME264 is listed in Today's flights because its check-in window is open now
- Actual: only ME110 (Closed) and ME305 (Boarding) are listed; ME264 is missing because it departs after midnight
- Screenshot: check-in page screenshot taken 18 Nov 2026 22:59 (manual test)
- Status: Open
- Fix:
- Retested:

## BUG-014 · Multi-city leg split hid SR-65 connect and return errors
- Date / who: 21 Nov 2026 · Tima
- Card / branch: B14 · feature/B14-sprint4-base
- Where: JourneyService::partitionIntoLegs / validate, B13bSegmentsTest
- Steps: 1. Add overnight-leg partitioning for CR-16 2. Run B13bSegmentsTest
- Expected: non-connecting flights still throw "departs from … but … lands at …"; return-before-arrival still throws the SR-65 return message
- Actual: a non-connecting pair became a new leg and passed; return-before-arrival threw "Leg N starts before the previous leg ends" instead of the round-trip message
- Screenshot: none — found by B13bSegmentsTest while building B14
- Status: Fixed
- Fix: only split a leg when the gap at the same city is over max_connection_hours; run ensureRoundTrip before ensureLegOrder so SR-65 messages stay
- Retested: 21 Nov 2026 · Tima — B13bSegmentsTest and B14MultiCityLegsTest green

## BUG-015 · Check-in shell dropped the role label and Profile link
- Date / who: 21 Nov 2026 · Tima
- Card / branch: B14 · feature/B14-sprint4-base
- Where: checkin/home via <x-app-shell>, B4RolesTest / B5LayoutTest
- Steps: 1. Put the find-passenger page in the new shell with module="Check-in" 2. Open /checkin as check-in staff
- Expected: page shows "Check-in staff" and a Profile link (same as the old layout)
- Actual: badge said "Check-in" and there was no Profile link
- Screenshot: none — found by B4RolesTest and B5LayoutTest
- Status: Fixed
- Fix: shell defaults to Role::label() and includes Profile; check-in home no longer overrides the module name
- Retested: 21 Nov 2026 · Tima — B4RolesTest and B5LayoutTest green

## BUG-016 · B13bMigrationTest rollback count missed the four B14 migrations
- Date / who: 21 Nov 2026 · Tima
- Card / branch: B14 · feature/B14-sprint4-base
- Where: tests/Feature/B13bMigrationTest (B13B_STEPS)
- Steps: 1. Add four B14 migrations 2. Run B13bMigrationTest
- Expected: rolling back B13B_STEPS reaches the pre-coupon schema
- Actual: coupons table still existed after rollback, because the step count still assumed six migrations on top of B13b
- Screenshot: none — found by B13bMigrationTest
- Status: Fixed
- Fix: setup only — B13B_STEPS raised from 6 to 10 (list in the test report)
- Retested: 21 Nov 2026 · Tima — B13bMigrationTest green

## BUG-017 · Find reservation crashed: undefined $q
- Date / who: 21 Nov 2026 · Auto (UI1)
- Card / branch: UI1 · feature/UI1-global-layout
- Where: reservation/search.blade.php · R2FindReservationTest / B6RoutesTest
- Steps: 1. Restyle find-reservation with a `q` search field 2. Open /reservation/search
- Expected: page uses the controller's `pnr` / `error` / `reservation` vars and returns 200
- Actual: ErrorException Undefined variable $q (500)
- Screenshot: none — found by R2FindReservationTest
- Status: Fixed
- Fix: restored the PNR form (`name="pnr"`) and the original flight-browse behaviour; restyle only with panels/buttons
- Retested: 21 Nov 2026 — R2FindReservationTest and B6RoutesTest green
