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
use Symfony\Component\HttpFoundation\Response;

/**
 * Check-in and gate staff work at one station (B13a), on the flights departing from it.
 * - No station set: every page of the role shows "No station set: ask your manager".
 * - A flight, passenger, reservation, document, bag or service in the URL that belongs to a flight
 *   departing from another airport: 403.
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
            $flight = $this->flightOf($parameter);

            if ($flight !== null && ! $user->worksAt($flight)) {
                abort(Response::HTTP_FORBIDDEN, "This flight does not depart from {$user->airport_code}.");
            }
        }

        return $next($request);
    }

    private function flightOf(mixed $parameter): ?Flight
    {
        return match (true) {
            $parameter instanceof Flight => $parameter,
            $parameter instanceof Reservation => $parameter->flight,
            $parameter instanceof Passenger => $parameter->reservation->flight,
            $parameter instanceof TravelDocument,
            $parameter instanceof Bag,
            $parameter instanceof SpecialService => $parameter->passenger->reservation->flight,
            default => null,
        };
    }
}
