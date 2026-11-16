<?php

namespace App\Http\Controllers\Manager;

use App\Enums\CabinClass;
use App\Enums\FeeCode;
use App\Enums\PassengerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\UpdateFaresRequest;
use App\Models\Airport;
use App\Models\AuditEntry;
use App\Models\Fare;
use App\Models\Fee;
use App\Models\Flight;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class FareController extends Controller
{
    public function index(Request $request): View
    {
        // Prices are stored in cents; the form shows plain dollars ("187.50") so it can be posted back as typed.
        // No route: the defaults, as M7. A route shows only the prices that route has of its own.
        $defaults = $this->dollarsByKey(Fare::query()->defaults()->get());
        $route = $this->selectedRoute($request->query('route'));
        $fares = $defaults;

        if ($route !== null) {
            [$origin, $destination] = explode('-', $route);
            $fares = $this->dollarsByKey(Fare::query()->forRoute($origin, $destination)->get());
        }

        $fees = Fee::all()->mapWithKeys(fn (Fee $fee) => [$fee->code->value => $this->dollars($fee->amount_cents)]);

        return view('manager.fares', [
            'cabins' => CabinClass::cases(),
            'types' => PassengerType::cases(),
            'feeCodes' => FeeCode::cases(),
            'fares' => $fares,
            'defaults' => $defaults,
            'fees' => $fees,
            'route' => $route ?? '',
            'routes' => $this->routes(),
        ]);
    }

    /**
     * Saves the defaults, or one route. Defaults are only edited, never created or deleted.
     * On a route an empty cell leaves the default in force; a typed price creates or updates
     * that route's fare; clearing an existing one deletes it. Fees are the same list either way.
     * One transaction: every change and its audit row is saved, or none.
     */
    public function update(UpdateFaresRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $route = $data['route'] ?? '';
        $changed = 0;

        DB::transaction(function () use ($data, $route, &$changed) {
            $changed += $route === '' ? $this->saveDefaults($data) : $this->saveRoute($data, $route);

            foreach (FeeCode::cases() as $code) {
                $fee = Fee::where('code', $code)->firstOrFail();

                $changed += $this->save($fee, $data['fees'][$code->value], "fee {$code->value}");
            }
        });

        return redirect()
            ->route('manager.fares', $route === '' ? [] : ['route' => $route])
            ->with('status', $changed === 0 ? 'No price changed.' : "Prices saved ({$changed} changed).");
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveDefaults(array $data): int
    {
        $changed = 0;

        foreach (CabinClass::cases() as $cabin) {
            foreach (PassengerType::cases() as $type) {
                $fare = Fare::query()->defaults()->where('cabin_class', $cabin)->where('passenger_type', $type)->firstOrFail();
                $typed = $data['fares'][strtolower($cabin->value)][strtolower($type->value)];

                $changed += $this->save($fare, $typed, "fare {$cabin->label()} {$type->label()}");
            }
        }

        return $changed;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveRoute(array $data, string $route): int
    {
        [$origin, $destination] = explode('-', $route);
        $changed = 0;

        foreach (CabinClass::cases() as $cabin) {
            foreach (PassengerType::cases() as $type) {
                $typed = $data['fares'][strtolower($cabin->value)][strtolower($type->value)] ?? null;
                $field = "fare {$origin}-{$destination} {$cabin->label()} {$type->label()}";
                $existing = Fare::query()->forRoute($origin, $destination)
                    ->where('cabin_class', $cabin)
                    ->where('passenger_type', $type)
                    ->first();

                if ($typed === null || $typed === '') {
                    if ($existing !== null) {
                        $this->audit($field, Money::format($existing->amount_cents), 'default');
                        $existing->delete();
                        $changed++;
                    }

                    continue;
                }

                if ($existing === null) {
                    $cents = Money::toCents($typed);
                    $this->audit($field, 'default', Money::format($cents));
                    Fare::create([
                        'origin_airport_code' => $origin,
                        'destination_airport_code' => $destination,
                        'cabin_class' => $cabin,
                        'passenger_type' => $type,
                        'amount_cents' => $cents,
                        'currency' => 'USD',
                    ]);
                    $changed++;

                    continue;
                }

                $changed += $this->save($existing, $typed, $field);
            }
        }

        return $changed;
    }

    /**
     * Saves one price if it differs, with its audit row. Returns 1 when it changed, else 0.
     * "250" and "250.00" are the same 25000 cents, so they are not a change.
     */
    private function save(Fare|Fee $price, string $typedDollars, string $field): int
    {
        $newCents = Money::toCents($typedDollars);

        if ($newCents === $price->amount_cents) {
            return 0;
        }

        $this->audit($field, Money::format($price->amount_cents), Money::format($newCents));
        $price->update(['amount_cents' => $newCents]);

        return 1;
    }

    /**
     * A price belongs to no passenger and no flight, so those columns stay null.
     */
    private function audit(string $field, string $old, string $new): void
    {
        AuditEntry::create([
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'user_id' => auth()->id(),
            'changed_at' => now(),
        ]);
    }

    /**
     * Directed routes that have a flight, or that already have a fare of their own. No duplicates.
     * Ordered by origin, then destination: BEY-CDG before BEY-DXB before CDG-BEY.
     *
     * @return list<array{value: string, label: string}>
     */
    private function routes(): array
    {
        $cities = Airport::query()->pluck('city', 'code');

        $fromFlights = Flight::query()
            ->select('origin', 'destination')
            ->distinct()
            ->get()
            ->map(fn (Flight $flight) => $flight->origin.'-'.$flight->destination);

        $fromFares = Fare::query()
            ->whereNotNull('origin_airport_code')
            ->whereNotNull('destination_airport_code')
            ->select('origin_airport_code', 'destination_airport_code')
            ->distinct()
            ->get()
            ->map(fn (Fare $fare) => $fare->origin_airport_code.'-'.$fare->destination_airport_code);

        // An empty flight list is still an Eloquent collection. Its merge() expects models,
        // so the two lists are plain collections before they are joined.
        return collect($fromFlights->all())
            ->merge($fromFares->all())
            ->unique()
            ->sort()
            ->values()
            ->map(function (string $route) use ($cities) {
                [$origin, $destination] = explode('-', $route);

                return [
                    'value' => $route,
                    'label' => $origin.' → '.$destination.' ('.$cities[$origin].' → '.$cities[$destination].')',
                ];
            })
            ->all();
    }

    /**
     * The route in the query, or null when it is missing or not a real pair of airports.
     */
    private function selectedRoute(mixed $route): ?string
    {
        if (! is_string($route)) {
            return null;
        }

        $route = strtoupper(trim($route));

        if (! preg_match('/^[A-Z]{3}-[A-Z]{3}$/D', $route)) {
            return null;
        }

        [$origin, $destination] = explode('-', $route);

        if ($origin === $destination || Airport::query()->whereIn('code', [$origin, $destination])->count() !== 2) {
            return null;
        }

        return $route;
    }

    /**
     * @param  Collection<int, Fare>  $fares
     * @return array<string, string>
     */
    private function dollarsByKey(Collection $fares): array
    {
        return $fares->mapWithKeys(fn (Fare $fare) => [
            strtolower($fare->cabin_class->value).'.'.strtolower($fare->passenger_type->value) => $this->dollars($fare->amount_cents),
        ])->all();
    }

    /**
     * 18750 → "187.50", with integer math only (no float, no thousands comma).
     */
    private function dollars(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
