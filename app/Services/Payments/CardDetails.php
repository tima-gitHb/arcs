<?php

namespace App\Services\Payments;

use RuntimeException;
use SensitiveParameter;

/**
 * Card data on its way to the gateway. It lives only for the request: it cannot be
 * serialized, dumped or logged, and nothing may store it.
 */
final class CardDetails
{
    public function __construct(
        #[SensitiveParameter] private readonly string $number,
        #[SensitiveParameter] private readonly string $expiry,
        #[SensitiveParameter] private readonly string $cvc,
    ) {}

    /**
     * The card number without spaces.
     */
    public function number(): string
    {
        return preg_replace('/\D/', '', $this->number);
    }

    /**
     * MM/YY.
     */
    public function expiry(): string
    {
        return $this->expiry;
    }

    public function cvc(): string
    {
        return $this->cvc;
    }

    public function last4(): string
    {
        return substr($this->number(), -4);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['card' => '••••'.$this->last4()];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new RuntimeException('Card details must never be serialized.');
    }
}
