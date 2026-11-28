<?php

namespace App\Enums;

enum PaymentKind: string
{
    case Fare = 'Fare';
    case ExtraBag = 'ExtraBag';
    case ExcessBaggage = 'ExcessBaggage';
    case Fee = 'Fee';
    case Upgrade = 'Upgrade';
    case Refund = 'Refund';
    case Exchange = 'Exchange';

    public function label(): string
    {
        return match ($this) {
            self::Fare => 'Fare',
            self::ExtraBag => 'Extra bag',
            self::ExcessBaggage => 'Excess baggage',
            self::Fee => 'Fee',
            self::Upgrade => 'Cabin upgrade',
            self::Refund => 'Refund',
            self::Exchange => 'Ticket exchange',
        };
    }
}
