<?php

namespace App\Services;

/**
 * A price from FareService. Every amount is in integer cents.
 */
final class Quote
{
    /**
     * @param  list<array{label: string, quantity: int, unit_cents: int, amount_cents: int}>  $lines
     */
    public function __construct(
        public readonly array $lines,
        public readonly int $totalCents,
        public readonly string $currency,
    ) {}
}
