<?php

namespace App\Services;

use App\Enums\CabinClass;
use App\Models\BaggageAllowance;
use App\Models\Passenger;
use App\Models\Setting;

/**
 * The only place to read a passenger's baggage allowance.
 */
class AllowanceService
{
    /**
     * Safety limit when the hard_max_kg_per_bag setting is missing: no bag above it, ever.
     */
    private const DEFAULT_HARD_MAX_KG = 32.0;

    /**
     * Pieces = the class's pieces + extra_bags; total kg = pieces x kg per bag + extra_kg.
     */
    public function for(Passenger $passenger): Allowance
    {
        $cabin = $this->cabinClassOf($passenger);
        $row = BaggageAllowance::query()->where('cabin_class', $cabin)->first();

        $pieces = (int) $row?->pieces + $passenger->extra_bags;
        $kgPerBag = (float) $row?->max_kg_per_bag;

        return new Allowance(
            cabinClass: $cabin,
            pieces: $pieces,
            kgPerBag: $kgPerBag,
            totalKg: $pieces * $kgPerBag + $passenger->extra_kg,
            hardMaxKg: (float) (Setting::find('hard_max_kg_per_bag')?->value ?? self::DEFAULT_HARD_MAX_KG),
            isSet: $row !== null,
        );
    }

    /**
     * The cabin of the passenger's seat, else the booked class, else Economy.
     * An infant travels in the cabin of the accompanying adult.
     */
    private function cabinClassOf(Passenger $passenger): CabinClass
    {
        $traveller = $passenger->accompanyingAdult ?? $passenger;

        return $traveller->seatAssignment?->seat?->cabin_class
            ?? $traveller->cabin_class
            ?? CabinClass::Economy;
    }
}
