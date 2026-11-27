<?php

namespace App\Enums;

/**
 * Succeeded = charge kept. Refunded = money returned (PaymentKind::Refund rows).
 */
enum PaymentStatus: string
{
    case Succeeded = 'Succeeded';
    case Failed = 'Failed';
    case Refunded = 'Refunded';

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => 'Paid',
            self::Failed => 'Failed',
            self::Refunded => 'Refunded',
        };
    }
}
