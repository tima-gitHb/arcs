<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PassengerType;
use App\Enums\Role;
use App\Enums\SpecialServiceCode;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\SpecialService;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class C7SpecialServicesTest extends TestCase
{
    use RefreshDatabase;

    private const UMNR_REFUSED = 'UMNR is only for a child with no adult on the reservation.';

    private const LOCKED = 'Special services can only be changed for a booked or checked-in passenger.';

    private User $agent;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
    }

    private function passenger(PassengerType $type = PassengerType::Adult, ?Reservation $reservation = null, array $attributes = []): Passenger
    {
        $passenger = Passenger::factory()
            ->for($reservation ?? Reservation::factory()->for($this->flight))
            ->create(['type' => $type] + $attributes);
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger);

        return $passenger;
    }

    private function add(Passenger $passenger, SpecialServiceCode $code, ?string $details = null)
    {
        return $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.services.store', $passenger), ['code' => $code->value, 'details' => $details]);
    }

    public function test_SR44_a_code_that_requires_details_is_refused_without_and_accepted_with_them(): void
    {
        $passenger = $this->passenger();

        foreach ([null, '', '   '] as $empty) {
            $this->add($passenger, SpecialServiceCode::PETC, $empty)
                ->assertRedirect(route('checkin.passengers.show', $passenger))
                ->assertSessionHas('error', 'Details are required for PETC.');
        }
        $this->assertSame(0, $passenger->specialServices()->count());

        $this->add($passenger, SpecialServiceCode::PETC, 'Cat, 6 kg with carrier')
            ->assertSessionHas('success', 'PETC added.');

        $service = $passenger->specialServices()->sole();
        $this->assertSame(SpecialServiceCode::PETC, $service->type);
        $this->assertSame('Cat, 6 kg with carrier', $service->details);
    }

    public function test_SR44_a_code_without_the_rule_needs_no_details(): void
    {
        $passenger = $this->passenger();

        $this->add($passenger, SpecialServiceCode::WCHR)->assertSessionHas('success');

        $this->assertNull($passenger->specialServices()->sole()->details);
    }

    public function test_SR44_UMNR_is_refused_for_an_adult(): void
    {
        $adult = $this->passenger();

        $this->add($adult, SpecialServiceCode::UMNR, 'Met by aunt in Paris')
            ->assertSessionHas('error', self::UMNR_REFUSED);

        $this->assertSame(0, $adult->specialServices()->count());
    }

    public function test_SR44_UMNR_is_refused_for_a_child_travelling_with_an_adult(): void
    {
        $adult = $this->passenger();
        $child = $this->passenger(PassengerType::Child, $adult->reservation);

        $this->add($child, SpecialServiceCode::UMNR, 'Met by aunt in Paris')
            ->assertSessionHas('error', self::UMNR_REFUSED);

        $this->assertSame(0, $child->specialServices()->count());
    }

    public function test_SR44_UMNR_is_accepted_for_a_lone_child(): void
    {
        $child = $this->passenger(PassengerType::Child);

        $this->add($child, SpecialServiceCode::UMNR, 'Met by aunt in Paris')->assertSessionHas('success');

        $this->assertSame(SpecialServiceCode::UMNR, $child->specialServices()->sole()->type);
    }

    public function test_SR44_a_cancelled_adult_no_longer_counts_as_travelling_with_the_child(): void
    {
        $adult = $this->passenger();
        $child = $this->passenger(PassengerType::Child, $adult->reservation);
        app(StatusService::class)->cancel($adult, User::factory()->create(['role' => Role::Reservation]));

        $this->add($child, SpecialServiceCode::UMNR, 'Met by aunt in Paris')->assertSessionHas('success');
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        $passenger = $this->passenger();
        $this->add($passenger, SpecialServiceCode::WCHR)->assertSessionHas('success');

        $this->add($passenger, SpecialServiceCode::WCHR)
            ->assertSessionHas('error', 'WCHR is already on this passenger.');
        $this->assertSame(1, $passenger->specialServices()->count());

        // Another code, or the same code for someone else, is fine.
        $this->add($passenger, SpecialServiceCode::VGML)->assertSessionHas('success');
        $this->add($this->passenger(), SpecialServiceCode::WCHR)->assertSessionHas('success');
    }

    public function test_SR2_a_service_added_here_appears_in_the_M3_list_and_on_the_G1_manifest(): void
    {
        $passenger = $this->passenger(attributes: ['first_name' => 'Lina', 'last_name' => 'Karam']);
        $this->add($passenger, SpecialServiceCode::PETC, 'Cat, 6 kg with carrier')->assertSessionHas('success');

        $this->actingAsRole('manager');
        $this->get(route('manager.home', ['flight' => $this->flight->id, 'tab' => 'services']))
            ->assertOk()
            ->assertSeeInOrder(['Lina Karam', 'PETC', 'Cat, 6 kg with carrier']);

        $this->actingAsRole('gate');
        $this->get(route('gate.flights.show', $this->flight))
            ->assertOk()
            ->assertSeeInOrder(['Lina Karam', 'PETC']);
    }

    public function test_add_and_remove_are_audited(): void
    {
        $passenger = $this->passenger();
        $this->add($passenger, SpecialServiceCode::BLND);
        $service = $passenger->specialServices()->sole();

        $added = AuditEntry::where('field', 'special_service_added')->sole();
        $this->assertSame($passenger->id, $added->passenger_id);
        $this->assertNull($added->old_value);
        $this->assertSame('BLND', $added->new_value);
        $this->assertSame($this->agent->id, $added->user_id);

        $this->from(route('checkin.passengers.show', $passenger))
            ->delete(route('checkin.services.destroy', $service))
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHas('success', 'BLND removed.');

        $this->assertDatabaseMissing('special_services', ['id' => $service->id]);
        $removed = AuditEntry::where('field', 'special_service_removed')->sole();
        $this->assertSame('BLND', $removed->old_value);
        $this->assertSame('removed', $removed->new_value);
        $this->assertSame($this->agent->id, $removed->user_id);
    }

    public function test_refused_for_cancelled_boarded_and_no_show_passengers(): void
    {
        $statuses = app(StatusService::class);
        $gate = User::factory()->create(['role' => Role::Gate]);

        $cancelled = $this->passenger();
        $boarded = $this->passenger();
        $noShow = $this->passenger();
        $locked = [$cancelled, $boarded, $noShow];

        foreach ($locked as $passenger) {
            SpecialService::create(['passenger_id' => $passenger->id, 'type' => SpecialServiceCode::VGML]);
        }

        $statuses->cancel($cancelled, User::factory()->create(['role' => Role::Reservation]));
        $statuses->checkIn($boarded, $this->agent);
        $statuses->checkIn($noShow, $this->agent);
        $statuses->setFlightStatus($this->flight, FlightStatus::Boarding, $gate);
        $statuses->board($boarded, $gate);
        $statuses->closeBoarding($this->flight, $gate);

        $auditRows = AuditEntry::count();

        foreach ($locked as $passenger) {
            $service = $passenger->specialServices()->sole();

            $this->add($passenger, SpecialServiceCode::WCHR)->assertSessionHas('error', self::LOCKED);
            $this->delete(route('checkin.services.destroy', $service))->assertSessionHas('error', self::LOCKED);

            $this->assertSame(1, $passenger->specialServices()->count());

            // Still listed, but no forms.
            $this->get(route('checkin.passengers.show', $passenger))
                ->assertOk()
                ->assertSee('VGML')
                ->assertDontSee(route('checkin.services.store', $passenger), false)
                ->assertDontSee(route('checkin.services.destroy', $service), false);
        }

        $this->assertSame($auditRows, AuditEntry::count());
    }

    public function test_a_checked_in_passenger_can_still_add_and_remove(): void
    {
        $passenger = $this->passenger();
        app(StatusService::class)->checkIn($passenger, $this->agent);

        $this->add($passenger, SpecialServiceCode::DEAF)->assertSessionHas('success');
        $this->delete(route('checkin.services.destroy', $passenger->specialServices()->sole()))->assertSessionHas('success');

        $this->assertSame(0, $passenger->specialServices()->count());
    }

    public function test_the_panel_lists_code_label_and_details_and_offers_every_code(): void
    {
        $passenger = $this->passenger();
        $this->add($passenger, SpecialServiceCode::PETC, 'Cat, 6 kg with carrier');
        $service = $passenger->specialServices()->sole();

        $response = $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Special services')
            ->assertSeeInOrder(['PETC', SpecialServiceCode::PETC->label(), 'Cat, 6 kg with carrier'])
            ->assertSee(route('checkin.services.destroy', $service), false)
            ->assertSee(route('checkin.services.store', $passenger), false);

        foreach (SpecialServiceCode::cases() as $code) {
            $response->assertSee('value="'.$code->value.'"', false);
        }
    }

    public function test_the_form_is_validated(): void
    {
        $passenger = $this->passenger();

        $this->post(route('checkin.services.store', $passenger), ['code' => 'XXXX'])
            ->assertSessionHasErrorsIn('addService', ['code']);
        $this->post(route('checkin.services.store', $passenger), ['code' => 'PETC', 'details' => str_repeat('x', 501)])
            ->assertSessionHasErrorsIn('addService', ['details']);

        $this->assertSame(0, $passenger->specialServices()->count());
    }

    public function test_gate_and_reservation_users_get_403(): void
    {
        $passenger = $this->passenger();
        $this->add($passenger, SpecialServiceCode::WCHR);
        $service = $passenger->specialServices()->sole();

        foreach (['gate', 'reservation'] as $role) {
            $this->actingAsRole($role);
            $this->post(route('checkin.services.store', $passenger), ['code' => 'BLND'])->assertForbidden();
            $this->delete(route('checkin.services.destroy', $service))->assertForbidden();
        }

        $this->assertSame(1, $passenger->specialServices()->count());
    }
}
