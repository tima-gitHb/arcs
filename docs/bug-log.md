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
