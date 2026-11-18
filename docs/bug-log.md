# ARCS bug log — every error or crash, see the Whole team card

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

## BUG-001 · Fares page crashes when a route fare exists and no flight uses it
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

## BUG-002 · Saving MXP makes find('mxp') return that airport on MySQL
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

## BUG-003 · Confirmation page crashes when no fare exists
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

## BUG-004 · A failed flight-change email is not retried
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

## BUG-005 · E-ticket email shows raw HTML for the payment line
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

## BUG-006 · Today's flights hides a flight after midnight while its check-in is open
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
