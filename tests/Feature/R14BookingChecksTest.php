<?php

namespace Tests\Feature;

use App\Enums\PassengerType;
use App\Enums\Role;
use App\Mail\ETicketMail;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\SeatAssignment;
use App\Services\AllowanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * R14 — booking payment and e-ticket checks (R6–R10; R11 journey posts when supported).
 */
class R14BookingChecksTest extends TestCase
{
    use RefreshDatabase;

    private const FULL_CARD = '4242 4242 4242 4242';

    private const FULL_DIGITS = '4242424242424242';

    private const DECLINED = '4000 0000 0000 0002';

    private const DECLINED_DIGITS = '4000000000000002';

    private const CVC = '987';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
    }

    public function test_card_number_and_cvc_are_never_stored_logged_in_session_or_on_a_page(): void
    {
        Log::spy();
        Mail::fake();

        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create(['flight_no' => $this->flightNo('910')]);
        $seatId = $this->economySeat($flight);

        $response = $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [$this->adult($seatId)],
            ...$this->paidBookingFields([
                'card_number' => self::FULL_CARD,
                'card_cvc' => self::CVC,
                'contact_email' => 'rami@example.com',
            ]),
        ]);

        $reservation = Reservation::query()->sole();
        $response->assertRedirect(route('reservation.confirmation', $reservation));

        $payment = Payment::query()->sole();
        $this->assertSame('4242', $payment->card_last4);
        $this->assertNotEmpty($payment->card_brand);
        $this->assertNotEmpty($payment->gateway_reference);

        $this->assertNoCardSecretsAnywhere($response);

        $page = $this->get(route('reservation.confirmation', $reservation))->assertOk();
        $page->assertSee('••••4242', false);
        $page->assertDontSee(self::FULL_CARD);
        $page->assertDontSee(self::FULL_DIGITS);
        $page->assertDontSee(self::CVC);
        $this->assertNoCardSecretsAnywhere($page);

        Mail::assertSent(ETicketMail::class, function (ETicketMail $mail) use ($reservation): bool {
            $html = $mail->render();

            return $mail->hasTo('rami@example.com')
                && $mail->reservation->is($reservation)
                && str_contains($html, '••••4242')
                && ! str_contains($html, self::FULL_CARD)
                && ! str_contains($html, self::FULL_DIGITS)
                && ! str_contains($html, self::CVC);
        });

        foreach (['info', 'debug', 'error', 'warning'] as $level) {
            Log::shouldNotHaveReceived($level, fn (...$args) => $this->blobHasCardSecret(json_encode($args) ?: ''));
        }
    }

    public function test_declined_card_books_nothing_and_takes_no_seats(): void
    {
        Mail::fake();

        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatId = $this->economySeat($flight);

        $response = $this->from(route('reservation.create'))
            ->post(route('reservation.store'), [
                'flight_id' => $flight->id,
                'passengers' => [$this->adult($seatId)],
                ...$this->paidBookingFields([
                    'card_number' => self::DECLINED,
                    'card_cvc' => self::CVC,
                    'contact_email' => 'rami@example.com',
                ]),
            ]);

        $response->assertRedirect(route('reservation.create'))
            ->assertSessionHasErrors('card_number');

        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, Passenger::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, SeatAssignment::count());
        Mail::assertNothingSent();
        $this->assertNoCardSecretsAnywhere($response);
    }

    public function test_infants_have_no_own_baggage_allowance(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatId = $this->economySeat($flight);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                array_merge($this->adult($seatId), [
                    'cabin_class' => 'Business',
                    'extra_bags' => 1,
                    'extra_kg' => 2,
                    'seat_id' => Seat::factory()->create([
                        'aircraft_id' => $flight->aircraft_id,
                        'seat_no' => '1A',
                        'cabin_class' => 'Business',
                    ])->id,
                ]),
                [
                    'first_name' => 'Lea',
                    'last_name' => 'Haddad',
                    'type' => PassengerType::Infant->value,
                    'date_of_birth' => '2025-06-01',
                    'accompanying_adult' => 0,
                    // Attempted extras must be ignored for infants.
                    'extra_bags' => 3,
                    'extra_kg' => 5,
                    'passport' => [
                        'number' => 'LR2222333',
                        'issuing_country' => 'LB',
                        'expiry_date' => '2031-01-01',
                    ],
                ],
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $adult = Passenger::query()->where('type', PassengerType::Adult)->sole();
        $infant = Passenger::query()->where('type', PassengerType::Infant)->sole();
        $allowances = app(AllowanceService::class);

        $adultAllowance = $allowances->for($adult);
        $infantAllowance = $allowances->for($infant);

        // Infant shares the adult's class; they get no extras of their own at booking.
        $this->assertSame($adult->cabin_class, $infant->cabin_class);
        $this->assertSame($adultAllowance->cabinClass, $infantAllowance->cabinClass);
        $this->assertSame(1, $adult->extra_bags);
        $this->assertSame(2, $adult->extra_kg);
        $this->assertSame(0, $infant->extra_bags);
        $this->assertSame(0, $infant->extra_kg);

        $this->get(route('reservation.confirmation', $adult->reservation))
            ->assertOk()
            ->assertSee('Lea Haddad');
    }

    public function test_other_roles_get_403_on_booking_routes(): void
    {
        $flight = Flight::factory()->create();
        $reservation = Reservation::factory()->for($flight)->create();
        Passenger::factory()->for($reservation)->create();
        $seatId = $this->economySeat($flight);

        $payload = [
            'flight_id' => $flight->id,
            'passengers' => [$this->adult($seatId)],
            ...$this->paidBookingFields(),
        ];

        foreach ([Role::Checkin, Role::Gate, Role::Manager] as $role) {
            $this->actingAsRole($role);

            $this->get(route('reservation.create'))->assertForbidden();
            $this->get(route('reservation.search'))->assertForbidden();
            $this->get(route('reservation.confirmation', $reservation))->assertForbidden();
            $this->get(route('reservation.seats', $flight))->assertForbidden();
            $this->post(route('reservation.store'), $payload)->assertForbidden();
        }

        $this->assertSame(1, Reservation::count());
    }

    public function test_eticket_passenger_list_matches_the_booking(): void
    {
        Mail::fake();

        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create(['flight_no' => $this->flightNo('920')]);
        $adultSeat = $this->economySeat($flight);
        $childSeat = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '12B',
            'cabin_class' => 'Economy',
        ])->id;

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->adult($adultSeat, 'Rami'),
                [
                    'first_name' => 'Nour',
                    'last_name' => 'Haddad',
                    'type' => PassengerType::Child->value,
                    'cabin_class' => 'Economy',
                    'date_of_birth' => '2016-06-21',
                    'seat_id' => $childSeat,
                    'extra_bags' => 0,
                    'extra_kg' => 0,
                    'passport' => [
                        'number' => 'LR7654321',
                        'issuing_country' => 'LB',
                        'expiry_date' => '2030-01-01',
                    ],
                ],
                [
                    'first_name' => 'Lea',
                    'last_name' => 'Haddad',
                    'type' => PassengerType::Infant->value,
                    'date_of_birth' => '2025-06-01',
                    'accompanying_adult' => 0,
                    'passport' => [
                        'number' => 'LR1111222',
                        'issuing_country' => 'LB',
                        'expiry_date' => '2031-01-01',
                    ],
                ],
            ],
            ...$this->paidBookingFields([
                'contact_email' => 'family@example.com',
            ]),
        ])->assertRedirect();

        $reservation = Reservation::query()->with('passengers')->sole();
        $names = $reservation->passengers->map(fn (Passenger $p) => $p->first_name.' '.$p->last_name)->all();
        $this->assertSame(['Rami Haddad', 'Nour Haddad', 'Lea Haddad'], $names);

        $page = $this->get(route('reservation.confirmation', $reservation))->assertOk();
        foreach ($names as $name) {
            $page->assertSee($name);
        }
        $page->assertSee('Adult')
            ->assertSee('Child')
            ->assertSee('Infant')
            ->assertSee($this->flightNo('920'));

        Mail::assertSent(ETicketMail::class, function (ETicketMail $mail) use ($names): bool {
            $html = $mail->render();

            foreach ($names as $name) {
                if (! str_contains($html, $name)) {
                    return false;
                }
            }

            return str_contains($html, 'Infant')
                && str_contains($html, '••••4242');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function adult(int $seatId, string $first = 'Rami'): array
    {
        return [
            'first_name' => $first,
            'last_name' => 'Haddad',
            'type' => PassengerType::Adult->value,
            'cabin_class' => 'Economy',
            'date_of_birth' => '1985-04-12',
            'seat_id' => $seatId,
            'extra_bags' => 0,
            'extra_kg' => 0,
            'passport' => [
                'number' => 'LR'.str_pad((string) abs(crc32($first.$seatId)), 7, '0', STR_PAD_LEFT),
                'issuing_country' => 'LB',
                'expiry_date' => '2030-01-01',
            ],
        ];
    }

    private function economySeat(Flight $flight): int
    {
        return Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '12A',
            'cabin_class' => 'Economy',
        ])->id;
    }

    private function assertNoCardSecretsAnywhere(TestResponse $response): void
    {
        $cardNumbers = [self::FULL_CARD, self::FULL_DIGITS, self::DECLINED, self::DECLINED_DIGITS];
        $body = $response->getContent() ?: '';
        foreach ($cardNumbers as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }

        $session = json_encode(session()->all()) ?: '';
        foreach ($cardNumbers as $secret) {
            $this->assertStringNotContainsString($secret, $session);
        }
        $this->assertArrayNotHasKey('card_number', session()->getOldInput());
        $this->assertArrayNotHasKey('card_cvc', session()->getOldInput());
        $this->assertArrayNotHasKey('card_expiry', session()->getOldInput());

        foreach (DB::select('SELECT * FROM payments') as $row) {
            $blob = json_encode($row) ?: '';
            foreach ($cardNumbers as $secret) {
                $this->assertStringNotContainsString($secret, $blob);
            }
            $this->assertObjectNotHasProperty('card_number', $row);
            $this->assertObjectNotHasProperty('card_cvc', $row);
            $this->assertObjectNotHasProperty('cvc', $row);
            if ($row->card_last4 !== null) {
                $this->assertSame(4, strlen((string) $row->card_last4));
            }
        }

        foreach (DB::select('SELECT * FROM audit_log') as $row) {
            $blob = json_encode($row) ?: '';
            foreach ([...$cardNumbers, self::CVC] as $secret) {
                $this->assertStringNotContainsString($secret, $blob);
            }
        }

        $logPath = storage_path('logs/laravel.log');
        if (File::exists($logPath)) {
            $log = File::get($logPath);
            foreach ($cardNumbers as $secret) {
                $this->assertStringNotContainsString($secret, $log);
            }
        }
    }

    private function blobHasCardSecret(string $blob): bool
    {
        foreach ([self::FULL_CARD, self::FULL_DIGITS, self::DECLINED, self::DECLINED_DIGITS, self::CVC] as $secret) {
            if (str_contains($blob, $secret)) {
                return true;
            }
        }

        return false;
    }
}
