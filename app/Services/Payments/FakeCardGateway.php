<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Test mode: no real money moves. 4242 4242 4242 4242 is accepted; every other number,
 * including the test decline 4000 0000 0000 0002, is declined.
 */
class FakeCardGateway implements PaymentGateway
{
    private const APPROVED_NUMBER = '4242424242424242';

    public function charge(CardDetails $card, int $amountCents, string $currency): GatewayResult
    {
        if (! $this->expiryIsValid($card->expiry())) {
            return $this->refuse($card, 'Invalid expiry date');
        }
        if (! preg_match('/^\d{3,4}$/', $card->cvc())) {
            return $this->refuse($card, 'Invalid CVC');
        }
        if ($card->number() !== self::APPROVED_NUMBER) {
            return $this->refuse($card, 'Card declined');
        }

        return new GatewayResult(true, $this->brand($card), $card->last4(), 'fake_'.Str::random(16), null);
    }

    /**
     * MM/YY, and not past the end of that month.
     */
    private function expiryIsValid(string $expiry): bool
    {
        if (! preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $expiry)) {
            return false;
        }

        return Carbon::createFromFormat('!m/y', $expiry)->endOfMonth()->gte(now());
    }

    private function refuse(CardDetails $card, string $message): GatewayResult
    {
        return new GatewayResult(false, $this->brand($card), $card->last4(), null, $message);
    }

    private function brand(CardDetails $card): string
    {
        return match ($card->number()[0] ?? '') {
            '4' => 'Visa',
            '5' => 'Mastercard',
            default => 'Card',
        };
    }
}
