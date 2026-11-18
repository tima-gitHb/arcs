<?php

namespace App\Support;

use App\Models\Airport;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The manager's station filter (M17), remembered while moving between manager pages.
 *
 * Session key manager_station. Nothing stored means All stations, not the manager's own airport.
 *   ?station=CDG  checks the code against the airports already loaded, then saves it
 *   ?station=     clears it
 *   the key absent reads what was saved
 * The airport list is loaded once per request and kept on the request, so a filtered
 * page runs the same queries as an unfiltered one. exists:airports would add a query
 * only when a station is sent.
 */
class ManagerStation
{
    public const SESSION_KEY = 'manager_station';

    /**
     * The station in force for this request, or null for All stations.
     */
    public static function apply(Request $request): ?string
    {
        $codes = self::airports($request)->pluck('code');

        // The key is present for both ?station=CDG and ?station= (All stations). A missing key
        // is a later page, which keeps the saved choice.
        if ($request->exists('station')) {
            $chosen = $request->query('station');

            if (! is_string($chosen) || $chosen === '') {
                $request->session()->forget(self::SESSION_KEY);

                return null;
            }

            self::check($chosen, $codes);
            $request->session()->put(self::SESSION_KEY, $chosen);

            return $chosen;
        }

        $saved = $request->session()->get(self::SESSION_KEY);

        if (! is_string($saved) || $saved === '' || ! $codes->contains($saved)) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }

        return $saved;
    }

    /**
     * Every airport of the network, ordered by code. The first call queries; the rest of
     * this request reuse that collection.
     *
     * @return Collection<int, Airport>
     */
    public static function airports(Request $request): Collection
    {
        if (! $request->attributes->has('manager.airports')) {
            $request->attributes->set(
                'manager.airports',
                Airport::query()->orderBy('code')->get(),
            );
        }

        return $request->attributes->get('manager.airports');
    }

    /**
     * @param  Collection<int, string>  $codes
     */
    private static function check(string $chosen, Collection $codes): void
    {
        $validator = validator(
            ['station' => $chosen],
            ['station' => ['required', 'string', Rule::in($codes->all())]],
        );

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
