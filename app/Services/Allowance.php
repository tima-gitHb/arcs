<?php

namespace App\Services;

use App\Enums\CabinClass;

/**
 * A passenger's baggage allowance, from AllowanceService.
 */
final class Allowance
{
    public function __construct(
        public readonly CabinClass $cabinClass,
        public readonly int $pieces,
        public readonly float $kgPerBag,
        public readonly float $totalKg,
        public readonly float $hardMaxKg,
        public readonly bool $isSet, // false when baggage_allowances has no row for the class
    ) {}
}
