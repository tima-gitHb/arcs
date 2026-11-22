<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\PassengerType;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class R6PassportAtBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
    }

    public function test_the_booking_form_asks_for_a_passport(): void
    {
        $this->actingAsRole('reservation');

        $this->get(route('reservation.create'))
            ->assertOk()
            ->assertSee('Passport')
            ->assertSee('Issuing country')
            ->assertSee('Expiry date')
            ->assertSee('Choose a country')
            ->assertSee('Lebanon');
    }

    public function test_booking_saves_the_passport_for_check_in_and_writes_an_audit_row(): void
    {
        $agent = $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatId = $this->seatsFor($flight, 1)[0];

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger('Rami', 'Haddad', 'Adult', '1985-04-12', seatId: $seatId, passport: [
                    'number' => 'lr1234567',
                    'issuing_country' => 'lb',
                    'expiry_date' => '2030-05-01',
                ]),
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $passenger = Passenger::query()->sole();
        $document = TravelDocument::query()->sole();

        $this->assertSame(DocumentType::Passport, $document->type);
        $this->assertSame($passenger->id, $document->passenger_id);
        $this->assertSame('LR1234567', $document->number);
        $this->assertSame('LB', $document->issuing_country);
        $this->assertSame('2030-05-01', $document->expiry_date->toDateString());

        $this->assertDatabaseHas('audit_log', [
            'passenger_id' => $passenger->id,
            'field' => 'document_added',
            'old_value' => null,
            'new_value' => DocumentType::Passport->value,
            'user_id' => $agent->id,
        ]);

        $this->actingAsRole('checkin');

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Travel documents')
            ->assertSee('Passport')
            ->assertSee('LR1234567')
            ->assertSee('Lebanon')
            ->assertSee('01 May 2030')
            ->assertSee('Edit')
            ->assertSee('Add document');
    }

    public function test_an_infant_also_needs_a_passport_and_gets_no_seat(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatId = $this->seatsFor($flight, 1)[0];

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger('Rami', 'Haddad', 'Adult', '1985-04-12', seatId: $seatId, passport: [
                    'number' => 'LR1111111',
                    'issuing_country' => 'LB',
                    'expiry_date' => '2031-01-01',
                ]),
                $this->passenger('Lea', 'Haddad', 'Infant', '2025-06-01', adultIndex: 0, passport: [
                    'number' => 'LR2222222',
                    'issuing_country' => 'LB',
                    'expiry_date' => '2031-01-01',
                ]),
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $reservation = Reservation::query()->with('passengers.travelDocuments')->first();
        $infant = $reservation->passengers->firstWhere('type', PassengerType::Infant);

        $this->assertNull($infant->seatAssignment);
        $this->assertSame(1, $infant->travelDocuments()->count());
        $this->assertSame('LR2222222', $infant->travelDocuments->first()->number);
        $this->assertSame(2, TravelDocument::count());
        $this->assertSame(2, AuditEntry::where('field', 'document_added')->count());
    }

    public function test_a_bad_passport_is_refused_with_the_same_rules_as_check_in(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatId = $this->seatsFor($flight, 1)[0];

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger('Rami', 'Haddad', 'Adult', '1985-04-12', seatId: $seatId, passport: [
                    'number' => 'LR-123',
                    'issuing_country' => 'LBN',
                    'expiry_date' => 'not-a-date',
                ]),
            ],
            ...$this->paidBookingFields(),
        ])->assertSessionHasErrors([
            'passengers.0.passport.number',
            'passengers.0.passport.issuing_country',
            'passengers.0.passport.expiry_date',
        ]);

        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, TravelDocument::count());
    }

    /**
     * @param  array{number: string, issuing_country: string, expiry_date: string}|null  $passport
     * @return array<string, mixed>
     */
    private function passenger(
        string $first,
        string $last,
        string $type,
        string $dob,
        ?int $adultIndex = null,
        ?int $seatId = null,
        ?array $passport = null,
    ): array {
        $row = [
            'first_name' => $first,
            'last_name' => $last,
            'type' => $type,
            'cabin_class' => $type === 'Infant' ? null : 'Economy',
            'date_of_birth' => $dob,
            'passport' => $passport ?? [
                'number' => 'LR9999999',
                'issuing_country' => 'LB',
                'expiry_date' => '2030-01-01',
            ],
        ];

        if ($adultIndex !== null) {
            $row['accompanying_adult'] = $adultIndex;
        }

        if ($seatId !== null) {
            $row['seat_id'] = $seatId;
        }

        return $row;
    }
}
