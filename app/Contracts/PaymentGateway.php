<?php

namespace App\Contracts;

use App\Services\Payments\CardDetails;
use App\Services\Payments\GatewayResult;

/**
 * Takes a card payment. Bound to FakeCardGateway (test mode) in AppServiceProvider.
 */
interface PaymentGateway
{
    public function charge(CardDetails $card, int $amountCents, string $currency): GatewayResult;
}
