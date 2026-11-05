<?php

namespace App\Services\Payments;

/**
 * What a payment gateway answered. Holds nothing that may not be stored.
 */
final class GatewayResult
{
    public function __construct(
        public readonly bool $approved,
        public readonly ?string $brand,
        public readonly ?string $last4,
        public readonly ?string $reference,
        public readonly ?string $message,
    ) {}
}
