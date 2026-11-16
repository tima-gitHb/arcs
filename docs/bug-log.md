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
- Status: Fixed
- Fix: route lists turned into plain collections before merging, commit on feature/M14-fares-per-route; covered by B13bRouteFaresTest and FareRouteTest
- Retested: after merge into dev — to fill in
