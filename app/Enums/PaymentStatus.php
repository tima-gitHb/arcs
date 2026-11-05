<?php

namespace App\Enums;

/**
 * There is no Refunded status: refunds are out of scope.
 */
enum PaymentStatus: string
{
    case Succeeded = 'Succeeded';
    case Failed = 'Failed';

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => 'Paid',
            self::Failed => 'Failed',
        };
    }
}
