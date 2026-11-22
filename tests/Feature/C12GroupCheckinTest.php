<?php

namespace Tests\Feature;

use App\Enums\CabinClass;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\PassengerType;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Seat;
use App\Models\TravelDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class C12GroupCheckinTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Flight $flight;

    private Reservation $reservation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => $this->flightNo('401')]);
        $this->seedBookingPrices();
        $this->reservation = Reservation::factory()->for($this->flight)->create(['pnr' => 'FAM123']);
    }

    /**
     * @return list<Passenger>
     */
    private function familyOfThreePlusInfant(): array
    {
        $adult1 = Passenger::factory()->for($this->reservation)->create([
            'first_name' => 'Rami', 'last_name' => 'Haddad', 'type' => PassengerType::Adult,
            'cabin_class' => CabinClass::Economy,
        ]);
        $adult2 = Passenger::factory()->for($this->reservation)->create([
            'first_name' => 'Lara', 'last_name' => 'Haddad', 'type' => PassengerType::Adult,
            'cabin_class' => CabinClass::Economy,
        ]);
        $child = Passenger::factory()->for($this->reservation)->create([
            'first_name' => 'Omar', 'last_name' => 'Haddad', 'type' => PassengerType::Child,
            'cabin_class' => CabinClass::Economy,
        ]);
        $infant = Passenger::factory()->for($this->reservation)->create([
            'first_name' => 'Noor', 'last_name' => 'Haddad', 'type' => PassengerType::Infant,
            'accompanying_adult_id' => $adult1->id,
            'cabin_class' => CabinClass::Economy,
        ]);

        foreach ([$adult1, $adult2, $child, $infant] as $passenger) {
            TravelDocument::factory()->for($passenger)->create();
        }

        return [$adult1, $adult2, $child, $infant];
    }

    private function seatFor(string $seatNo, CabinClass $cabin = CabinClass::Economy): Seat
    {
        return Seat::factory()->create([
            'aircraft_id' => $this->flight->aircraft_id,
            'seat_no' => $seatNo,
            'cabin_class' => $cabin,
        ]);
    }

    private function assignGroupSeats(array $seatByPassengerId)
    {
        return $this->from(route('checkin.reservations.group', $this->reservation))
            ->post(route('checkin.reservations.seats', $this->reservation), [
                'seats' => $seatByPassengerId,
            ]);
    }

    private function confirmAll(array $entryReasons = [])
    {
        return $this->from(route('checkin.reservations.group', $this->reservation))
            ->post(route('checkin.reservations.confirm-all', $this->reservation), [
                'entry_reasons' => $entryReasons,
            ]);
    }

    public function test_SR61_family_of_three_plus_infant_checked_in_from_one_screen(): void
    {
        [$adult1, $adult2, $child, $infant] = $this->familyOfThreePlusInfant();

        $this->get(route('checkin.home', ['q' => 'FAM123']))
            ->assertOk()
            ->assertSee('Check in all', false)
            ->assertSee(route('checkin.reservations.group', $this->reservation), false);

        $this->get(route('checkin.reservations.group', $this->reservation))
            ->assertOk()
            ->assertSee('Rami Haddad')
            ->assertSee('Lara Haddad')
            ->assertSee('Omar Haddad')
            ->assertSee('Noor Haddad')
            ->assertSee('id="seat-map"', false)
            ->assertSee('Confirm all', false);

        $s1 = $this->seatFor('14A');
        $s2 = $this->seatFor('14B');
        $s3 = $this->seatFor('14C');

        $this->assignGroupSeats([
            $adult1->id => $s1->id,
            $adult2->id => $s2->id,
            $child->id => $s3->id,
        ])->assertSessionHas('success');

        $this->confirmAll()
            ->assertRedirect(route('checkin.reservations.group', $this->reservation))
            ->assertSessionHas('group_results');

        foreach ([$adult1, $adult2, $child, $infant] as $passenger) {
            $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
        }

        $results = session('group_results');
        $this->assertCount(4, $results);
        foreach ($results as $row) {
            $this->assertTrue($row['ok']);
        }
    }

    public function test_SR61_expired_passport_stays_booked_while_others_succeed(): void
    {
        [$adult1, $adult2, $child, $infant] = $this->familyOfThreePlusInfant();

        $adult2->travelDocuments()->delete();
        TravelDocument::factory()->for($adult2)->create([
            'expiry_date' => now()->subDay()->toDateString(),
        ]);

        $this->assignGroupSeats([
            $adult1->id => $this->seatFor('15A')->id,
            $adult2->id => $this->seatFor('15B')->id,
            $child->id => $this->seatFor('15C')->id,
        ]);

        $this->confirmAll()->assertSessionHas('group_results');

        $this->assertSame(PassengerStatus::CheckedIn, $adult1->fresh()->status);
        $this->assertSame(PassengerStatus::Booked, $adult2->fresh()->status);
        $this->assertSame(PassengerStatus::CheckedIn, $child->fresh()->status);
        $this->assertSame(PassengerStatus::CheckedIn, $infant->fresh()->status);

        $results = collect(session('group_results'))->keyBy('passenger_id');
        $this->assertFalse($results[$adult2->id]['ok']);
        $this->assertSame('Document expired', $results[$adult2->id]['message']);
        $this->assertTrue($results[$adult1->id]['ok']);
    }

    public function test_SR61_no_duplicate_seats_on_the_group_map(): void
    {
        [$adult1, $adult2, $child] = array_slice($this->familyOfThreePlusInfant(), 0, 3);
        $seat = $this->seatFor('16A');

        $this->assignGroupSeats([
            $adult1->id => $seat->id,
            $adult2->id => $seat->id,
            $child->id => $this->seatFor('16B')->id,
        ])->assertSessionHasErrors('seats');

        $this->assertNull($adult1->fresh()->seatAssignment);
        $this->assertNull($adult2->fresh()->seatAssignment);
    }

    public function test_SR61_print_all_lists_every_active_pass(): void
    {
        [$adult1, $adult2, $child, $infant] = $this->familyOfThreePlusInfant();

        $this->assignGroupSeats([
            $adult1->id => $this->seatFor('17A')->id,
            $adult2->id => $this->seatFor('17B')->id,
            $child->id => $this->seatFor('17C')->id,
        ]);
        $this->confirmAll();

        $response = $this->get(route('checkin.reservations.boarding-passes', $this->reservation))
            ->assertOk()
            ->assertSee('RAMI')
            ->assertSee('LARA')
            ->assertSee('OMAR')
            ->assertSee('NOOR');

        foreach ([$adult1, $adult2, $child, $infant] as $passenger) {
            $pass = $passenger->fresh()->activeBoardingPass;
            $this->assertNotNull($pass);
            $response->assertSee($pass->barcode, false);
        }
    }

    public function test_SR61_gate_and_reservation_users_get_403(): void
    {
        $this->familyOfThreePlusInfant();

        foreach (['gate', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->get(route('checkin.reservations.group', $this->reservation))->assertForbidden();
            $this->post(route('checkin.reservations.seats', $this->reservation), ['seats' => []])->assertForbidden();
            $this->post(route('checkin.reservations.confirm-all', $this->reservation))->assertForbidden();
            $this->get(route('checkin.reservations.boarding-passes', $this->reservation))->assertForbidden();
        }
    }
}
