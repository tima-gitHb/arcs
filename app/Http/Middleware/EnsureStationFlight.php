<?php

namespace App\Http\Middleware;

use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\SpecialService;
use App\Models\TravelDocument;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Check-in and gate staff work at one station (B13a), on the flights departing from it.
 * - No station set: every page of the role shows "No station set: ask your manager".
 * - A flight, passenger, reservation, document, bag or service in the URL with no flight departing
 *   from the agent's station: 403. A passenger counts at every station one of their flights leaves from.
 * Runs after the route's models are loaded, so it sees the real records. Usage: ->middleware('station').
 */
class EnsureStationFlight
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->airport_code === null) {
            return response()->view('station-missing', [], Response::HTTP_FORBIDDEN);
        }

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            $flights = $this->flightsOf($parameter);

            // A passenger belongs to every station one of their flights departs from (B13b):
            // a CDG agent checks in the return of a BEY→CDG→BEY trip.
            if ($flights !== null && ! $flights->contains(fn (Flight $flight) => $user->worksAt($flight))) {
                abort(Response::HTTP_FORBIDDEN, "This flight does not depart from {$user->airport_code}.");
            }
        }

        return $next($request);
    }

    /**
     * The flights a route parameter is about, or null when it is not about a flight.
     *
     * @return Collection<int, Flight>|null
     */
    private function flightsOf(mixed $parameter): ?Collection
    {
        $passenger = match (true) {
            $parameter instanceof Flight => null,
            $parameter instanceof Reservation => null,
            $parameter instanceof Passenger => $parameter,
            $parameter instanceof TravelDocument,
            $parameter instanceof Bag,
            $parameter instanceof SpecialService => $parameter->passenger,
            default => false,
        };

        return match (true) {
            $parameter instanceof Flight => collect([$parameter]),
            $parameter instanceof Reservation => $parameter->segments()->with('flight')->get()->pluck('flight'),
            $passenger instanceof Passenger => $passenger->coupons()->with('flight')->get()->pluck('flight'),
            default => null,
        };
    }
}
