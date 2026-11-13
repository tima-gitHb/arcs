<?php

namespace App\Support;

use App\Enums\SegmentDirection;
use App\Models\Flight;

/**
 * The flights of a journey before it is booked (B13b): outbound flights in order, then the return
 * flights in order (empty for one-way). JourneyService checks the rules (SR-65).
 *     new Journey([$beyIst, $istCdg]);        // connection
 *     new Journey([$beyCdg], [$cdgBey]);      // round trip
 */
final class Journey
{
    /**
     * @param  list<Flight>  $outbound
     * @param  list<Flight>  $return
     */
    public function __construct(
        public readonly array $outbound,
        public readonly array $return = [],
    ) {}

    public static function oneWay(Flight $flight): self
    {
        return new self([$flight]);
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
