<?php

namespace App\Http\Controllers\Manager;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manager\StoreTripFareRequest;
use App\Http\Requests\Manager\UpdateTripFareRequest;
use App\Models\Airport;
use App\Models\AuditEntry;
use App\Models\TripFare;
use App\Support\ManagerStation;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TripFareController extends Controller
{
    public function create(): View
    {
        ManagerStation::apply(request());

        return view('manager.trip-fares-form', [
            'tripFare' => null,
            'cabins' => CabinClass::cases(),
            'types' => PassengerType::cases(),
            'airports' => Airport::query()->orderBy('code')->get(),
        ]);
    }

    public function edit(TripFare $tripFare): View
    {
        ManagerStation::apply(request());

        return view('manager.trip-fares-form', [
            'tripFare' => $tripFare,
            'cabins' => CabinClass::cases(),
            'types' => PassengerType::cases(),
            'airports' => Airport::query()->orderBy('code')->get(),
        ]);
    }

    public function store(StoreTripFareRequest $request): RedirectResponse
    {
        $data = $this->payload($request->validated());
        $this->assertUnique($data);

        DB::transaction(function () use ($data, $request) {
            TripFare::create($data);
            $this->audit(
                $request,
                $this->fieldLabel($data),
                null,
                Money::format($data['one_way_cents']),
            );
        });

        return redirect()->route('manager.fares', ['tab' => 'trips'])
            ->with('status', 'Trip fare added.');
    }

    public function update(UpdateTripFareRequest $request, TripFare $tripFare): RedirectResponse
    {
        $data = $this->payload($request->validated());
        $this->assertUnique($data, $tripFare->id);

        DB::transaction(function () use ($tripFare, $data, $request) {
            $oldAmount = Money::format($tripFare->one_way_cents);
            $tripFare->update($data);
            $this->audit($request, $this->fieldLabel($data), $oldAmount, Money::format($tripFare->one_way_cents));
        });

        return redirect()->route('manager.fares', ['tab' => 'trips'])
            ->with('status', 'Trip fare saved.');
    }

    public function destroy(Request $request, TripFare $tripFare): RedirectResponse
    {
        DB::transaction(function () use ($tripFare, $request) {
            $label = $this->fieldLabel([
                'origin_code' => $tripFare->origin_code,
                'destination_code' => $tripFare->destination_code,
                'cabin_class' => $tripFare->cabin_class,
                'passenger_type' => $tripFare->passenger_type,
            ]);
            // Same ≤30-char field as create/update; new_value "—" marks the delete (audit_log.field is varchar(30)).
            $this->audit($request, $label, Money::format($tripFare->one_way_cents), '—');
            $tripFare->delete();
        });

        return redirect()->route('manager.fares', ['tab' => 'trips'])
            ->with('status', 'Trip fare deleted.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        return [
            'origin_code' => $validated['origin_code'],
            'destination_code' => $validated['destination_code'],
            'cabin_class' => $validated['cabin_class'] instanceof CabinClass
                ? $validated['cabin_class']
                : CabinClass::from($validated['cabin_class']),
            'passenger_type' => $validated['passenger_type'] instanceof PassengerType
                ? $validated['passenger_type']
                : PassengerType::from($validated['passenger_type']),
            'one_way_cents' => Money::toCents((string) $validated['one_way']),
            'round_trip_cents' => filled($validated['round_trip'] ?? null)
                ? Money::toCents((string) $validated['round_trip'])
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertUnique(array $data, ?int $ignoreId = null): void
    {
        $exists = TripFare::query()
            ->where('origin_code', $data['origin_code'])
            ->where('destination_code', $data['destination_code'])
            ->where('cabin_class', $data['cabin_class'])
            ->where('passenger_type', $data['passenger_type'])
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'passenger_type' => 'A trip fare already exists for this route, cabin and passenger type.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fieldLabel(array $data): string
    {
        $cabin = $data['cabin_class'] instanceof CabinClass
            ? $data['cabin_class']->label()
            : CabinClass::from((string) $data['cabin_class'])->label();
        $type = $data['passenger_type'] instanceof PassengerType
            ? $data['passenger_type']->label()
            : PassengerType::from((string) $data['passenger_type'])->label();

        // audit_log.field is varchar(30); "trip fare …" overflows MySQL (BUG-021).
        return "trip {$data['origin_code']}-{$data['destination_code']} {$cabin} {$type}";
    }

    private function audit(Request $request, string $field, ?string $old, string $new): void
    {
        AuditEntry::create([
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'user_id' => $request->user()->id,
            'changed_at' => now(),
        ]);
    }
}
