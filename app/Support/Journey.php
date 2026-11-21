<?php

namespace App\Support;

use App\Enums\SegmentDirection;
use App\Models\Flight;

/**
 * The flights of a journey before it is booked (B13b): outbound flights in order, then the return
 * flights in order (empty for one-way). JourneyService checks the rules (SR-65).
 *     new Journey([$beyIst, $istCdg]);        // connection
 *     new Journey([$beyCdg], [$cdgBey]);      // round trip
 *     Journey::multiCity([[$a], [$b], [$c]]); // multi-city legs (B14, CR-16)
 */
final class Journey
{
    /**
     * Explicit multi-city legs (B14). When set, JourneyService validates these instead of the
     * outbound/return split. Each inner list is one leg's flights in order.
     *
     * @param  list<Flight>  $outbound
     * @param  list<Flight>  $return
     * @param  list<list<Flight>>|null  $cityLegs
     */
    public function __construct(
        public readonly array $outbound,
        public readonly array $return = [],
        public readonly ?array $cityLegs = null,
    ) {}

    public static function oneWay(Flight $flight): self
    {
        return new self([$flight]);
    }

    /**
     * A multi-city journey of explicit legs (B14, CR-16).
     *
     * @param  list<list<Flight>>  $legs
     */
    public static function multiCity(array $legs): self
    {
        $outbound = [];
        foreach ($legs as $leg) {
            foreach ($leg as $flight) {
                $outbound[] = $flight;
            }
        }

        return new self($outbound, [], $legs);
    }

    /**
     * @return list<Flight>
     */
    public function flights(SegmentDirection $direction): array
    {
        return $direction === SegmentDirection::Outbound ? $this->outbound : $this->return;
    }

    /**
     * Every flight with its direction, in journey order.
     *
     * @return list<array{0: Flight, 1: SegmentDirection}>
     */
    public function legs(): array
    {
        $legs = [];
        foreach ($this->outbound as $flight) {
            $legs[] = [$flight, SegmentDirection::Outbound];
        }
        foreach ($this->return as $flight) {
            $legs[] = [$flight, SegmentDirection::Return];
        }

        return $legs;
    }
}
