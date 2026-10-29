<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\Passenger;
use App\Models\TravelDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TravelDocument>
 */
class TravelDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'passenger_id' => Passenger::factory(),
            'type' => DocumentType::Passport,
            'number' => fake()->unique()->regexify('[A-Z]{2}[0-9]{7}'),
            'issuing_country' => 'LB',
            'expiry_date' => now()->addYears(5)->toDateString(),
        ];
    }
}
