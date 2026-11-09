<?php

namespace App\Services;

/**
 * Pooled baggage excess for a passenger (C10 / SR-59).
 * $quote is null when ExtraBag / ExcessKg fees are missing ("Price not set").
 */
final class BaggageExcess
{
    public function __construct(
        public readonly int $piecesUsed,
        public readonly float $kgUsed,
        public readonly int $extraBags,
        public readonly float $excessKg,
        public readonly ?Quote $quote,
    ) {}

    public function hasExcess(): bool
    {
        return $this->extraBags > 0 || $this->excessKg > 0;
    }

    public function priceIsSet(): bool
    {
        return $this->quote !== null;
    }
}
