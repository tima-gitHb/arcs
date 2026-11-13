<?php

namespace App\Services;

use App\Enums\CabinClass;
use App\Enums\SegmentDirection;
use App\Models\BaggageAllowance;
use App\Models\Passenger;
use App\Models\SeatAssignment;
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
    public function for(Passenger $passenger, ?SegmentDirection $direction = null): Allowance
    {
        $cabin = $this->cabinClassOf($passenger, $direction ?? app(JourneyService::class)->directionOf($passenger));
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
     * One flight in the direction: the cabin of the passenger's seat on it, else the booked class.
     * A connection (several flights, maybe in different cabins): the booked class (SR-67).
     * Else Economy. An infant travels in the cabin of the accompanying adult.
     */
    private function cabinClassOf(Passenger $passenger, SegmentDirection $direction): CabinClass
    {
        $traveller = $passenger->accompanyingAdult ?? $passenger;
        $journeys = app(JourneyService::class);

        if ($journeys->isConnection($traveller->reservation, $direction)) {
            return $traveller->cabin_class ?? CabinClass::Economy;
        }

        $coupon = $journeys->couponsOf($traveller, $direction)->first();
        $seat = $coupon === null || $traveller->seatAssignment?->coupon_id === $coupon->id
            ? $traveller->seatAssignment?->seat
            : SeatAssignment::query()->where('coupon_id', $coupon->id)->first()?->seat;

        return $seat?->cabin_class
            ?? $traveller->cabin_class
            ?? CabinClass::Economy;
    }
}
