<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Mail\ETicketMail;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Seat;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class R9PaidBookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBookingPrices();
    }

    public function test_booking_form_shows_allowance_extras_price_and_card(): void
    {
        $this->actingAsRole('reservation');

        $this->get(route('reservation.create'))
            ->assertOk()
            ->assertSee('Prepaid baggage extras')
            ->assertSee('Extra bags')
            ->assertSee('Extra kg')
            ->assertSee('Fare quote')
            ->assertSee('Card (read from caller)')
            ->assertSee('4242 4242 4242 4242')
            ->assertSee('Charge')
            ->assertSee('and save PNR');
    }

    public function test_booking_page_renders_the_charge_and_save_submit_button(): void
    {
        $this->actingAsRole('reservation');

        $this->get(route('reservation.create'))
            ->assertOk()
            ->assertSee('type="submit"', false)
            ->assertSee('id="pay-button-amount"', false)
            ->assertSee('Charge')
            ->assertSee('and save PNR');
    }

    public function test_successful_card_creates_booking_with_extras_and_shows_eticket(): void
    {
        Mail::fake();

        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create([
            'flight_no' => $this->flightNo('900'),
            'origin' => 'BEY',
            'destination' => 'CDG',
        ]);
        $seatId = $this->economySeat($flight);

        $response = $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($seatId, extraBags: 1, extraKg: 5),
            ],
            ...$this->paidBookingFields([
                'contact_email' => 'rami@example.com',
                'contact_phone' => '+96170123456',
            ]),
        ]);

        $reservation = Reservation::query()->with(['passengers', 'payments'])->sole();
        $passenger = $reservation->passengers->sole();
        $payment = $reservation->payments->sole();

        $response->assertRedirect(route('reservation.confirmation', $reservation));

        $this->assertSame(1, $passenger->extra_bags);
        $this->assertSame(5, $passenger->extra_kg);
        $this->assertSame('rami@example.com', $reservation->contact_email);
        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        // Economy adult 25000 + extra bag 5000 + 5 kg × 1000 = 35000
        $this->assertSame(35000, $payment->amount_cents);
        $this->assertSame('4242', $payment->card_last4);
        $this->assertNotNull($payment->gateway_reference);

        $page = $this->followRedirects($response);
        $page->assertOk()
            ->assertSee($reservation->pnr)
            ->assertSee($this->flightNo('900'))
            ->assertSee('BEY')
            ->assertSee('CDG')
            ->assertSee('Economy')
            ->assertSee(Money::format(35000))
            ->assertSee($payment->gateway_reference)
            ->assertSee('••••4242')
            ->assertSee('Print receipt')
            ->assertSee(config('arcs.airline.name').' e-ticket');

        Mail::assertSent(ETicketMail::class, function (ETicketMail $mail) use ($reservation): bool {
            return $mail->hasTo('rami@example.com')
                && $mail->reservation->is($reservation);
        });
    }

    public function test_declined_card_does_not_create_a_booking(): void
    {
        Mail::fake();

        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatId = $this->economySeat($flight);

        $this->from(route('reservation.create'))
            ->post(route('reservation.store'), [
                'flight_id' => $flight->id,
                'passengers' => [
                    $this->passenger($seatId),
                ],
                ...$this->paidBookingFields([
                    'card_number' => '4000 0000 0000 0002',
                    'contact_email' => 'rami@example.com',
                ]),
            ])
            ->assertRedirect(route('reservation.create'))
            ->assertSessionHasErrors('card_number');

        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, Passenger::count());
        $this->assertSame(0, Payment::count());
        Mail::assertNothingSent();
    }

    public function test_check_in_sees_extras_bought_at_booking(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatId = $this->economySeat($flight);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($seatId, extraBags: 2, extraKg: 3),
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        $passenger = Passenger::query()->sole();

        $this->actingAsRole('checkin');

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Allowance')
            ->assertSee('2 extra bags')
            ->assertSee('3 extra kg')
            ->assertSee('paid at booking');
    }

    public function test_no_email_is_sent_without_a_contact_address(): void
    {
        Mail::fake();

        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatId = $this->economySeat($flight);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($seatId),
            ],
            ...$this->paidBookingFields(),
        ])->assertRedirect();

        Mail::assertNothingSent();
        $this->assertSame(1, Reservation::count());
    }

    public function test_past_card_expiry_is_refused_before_charging(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00', config('app.timezone')));
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatId = $this->economySeat($flight);

        $this->from(route('reservation.create'))
            ->post(route('reservation.store'), [
                'flight_id' => $flight->id,
                'passengers' => [
                    $this->passenger($seatId),
                ],
                ...$this->paidBookingFields([
                    'card_expiry' => '10/26',
                ]),
            ])
            ->assertRedirect(route('reservation.create'))
            ->assertSessionHasErrors('card_expiry');

        $this->assertSame(0, Reservation::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_digits_only_expiry_is_normalised_and_accepted(): void
    {
        Mail::fake();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', config('app.timezone')));
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatId = $this->economySeat($flight);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($seatId),
            ],
            ...$this->paidBookingFields([
                'card_expiry' => '1230',
            ]),
        ])->assertRedirect();

        $this->assertSame(1, Reservation::count());
        $this->assertSame(PaymentStatus::Succeeded, Payment::query()->sole()->status);
    }

    public function test_eticket_email_renders_for_adult_child_and_infant(): void
    {
        Mail::fake();
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $adultSeat = $this->economySeat($flight);
        $childSeat = Seat::factory()->create([
            'aircraft_id' => $flight->aircraft_id,
            'seat_no' => '12B',
            'cabin_class' => 'Economy',
        ])->id;

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($adultSeat),
                [
                    'first_name' => 'Nour',
                    'last_name' => 'Haddad',
                    'type' => 'Child',
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
                    'type' => 'Infant',
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

        Mail::assertSent(ETicketMail::class, function (ETicketMail $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('family@example.com')
                && str_contains($html, 'Rami Haddad')
                && str_contains($html, 'Nour Haddad')
                && str_contains($html, 'Lea Haddad')
                && str_contains($html, 'Infant');
        });
    }

    public function test_bad_card_fields_are_validated(): void
    {
        $this->actingAsRole('reservation');
        $flight = Flight::factory()->create();
        $seatId = $this->economySeat($flight);

        $this->post(route('reservation.store'), [
            'flight_id' => $flight->id,
            'passengers' => [
                $this->passenger($seatId),
            ],
            ...$this->paidBookingFields([
                'card_number' => '1234',
                'card_expiry' => '13/30',
                'card_cvc' => '12',
            ]),
        ])->assertSessionHasErrors([
            'card_number',
            'card_expiry',
            'card_cvc',
        ]);

        $this->assertSame(0, Reservation::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function passenger(int $seatId, int $extraBags = 0, int $extraKg = 0): array
    {
        return [
            'first_name' => 'Rami',
            'last_name' => 'Haddad',
            'type' => 'Adult',
            'cabin_class' => 'Economy',
            'date_of_birth' => '1985-04-12',
            'seat_id' => $seatId,
            'extra_bags' => $extraBags,
            'extra_kg' => $extraKg,
            'passport' => [
                'number' => 'LR1234567',
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
}
