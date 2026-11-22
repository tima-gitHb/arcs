<?php

namespace Database\Seeders;

use App\Enums\CabinClass;
use App\Enums\PassengerType;
use App\Models\Fare;
use Illuminate\Database\Seeder;

/**
 * Route fares from Beirut and IST–CDG (B13b, SR-69). Both directions, every cabin and passenger type.
 * Premium ×1.6 and Business ×3.2 on the Economy adult base for that route.
 */
class RouteFareSeeder extends Seeder
{
    /**
     * Economy adult one-way price in cents (USD), origin → destination.
     *
     * @var array<string, int>
     */
    private const ROUTES = [
        'BEY-AMM' => 14000,
        'BEY-CDG' => 34000,
        'BEY-LHR' => 38000,
        'BEY-IST' => 19000,
        'BEY-ATH' => 22000,
        'BEY-DXB' => 26000,
        'BEY-DOH' => 25000,
        'BEY-JED' => 27000,
        'BEY-FCO' => 29000,
        'BEY-FRA' => 32000,
        'BEY-CAI' => 18000,
        'IST-CDG' => 28000,
    ];

    public function run(): void
    {
        foreach (self::ROUTES as $key => $economyAdult) {
            [$origin, $destination] = explode('-', $key);

            $this->seedRoutePair($origin, $destination, $economyAdult);
        }
    }

    private function seedRoutePair(string $origin, string $destination, int $economyAdult): void
    {
        foreach ([[$origin, $destination], [$destination, $origin]] as [$from, $to]) {
            foreach (CabinClass::cases() as $cabin) {
                foreach (PassengerType::cases() as $type) {
                    Fare::query()->updateOrCreate(
                        [
                            'origin_airport_code' => $from,
                            'destination_airport_code' => $to,
                            'cabin_class' => $cabin,
                            'passenger_type' => $type,
                        ],
                        [
                            'amount_cents' => $this->amountCents($economyAdult, $cabin, $type),
                            'currency' => 'USD',
                        ],
                    );
                }
            }
        }
    }

    private function amountCents(int $economyAdult, CabinClass $cabin, PassengerType $type): int
    {
        $base = match ($cabin) {
            CabinClass::Economy => $economyAdult,
            CabinClass::Premium => (int) round($economyAdult * 1.6),
            CabinClass::Business => (int) round($economyAdult * 3.2),
        };

        return match ($type) {
            PassengerType::Adult => $base,
            PassengerType::Child => (int) round($base * 0.75),
            PassengerType::Infant => (int) round($base * 0.10),
        };
    }
}
