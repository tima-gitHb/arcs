<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class C2TravelDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private Flight $flight;

    protected function setUp(): void
    {
        parent::setUp();

        // flightWithStatus(): check-in window 09:00–11:15, departure 2026-11-10 12:00.
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->agent = $this->actingAsRole('checkin');
        $this->flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
    }

    private function passenger(?string $passportExpiry = null, string $number = 'LR1234567'): Passenger
    {
        $passenger = Passenger::factory()
            ->for(Reservation::factory()->for($this->flight))
            ->create(['first_name' => 'Rami', 'last_name' => 'Haddad']);

        if ($passportExpiry) {
            TravelDocument::factory()->for($passenger)->create([
                'type' => DocumentType::Passport,
                'number' => $number,
                'expiry_date' => $passportExpiry,
            ]);
        }

        return $passenger;
    }

    private function confirm(Passenger $passenger)
    {
        return $this->post(route('checkin.passengers.confirm', $passenger));
    }

    private function addDocument(Passenger $passenger, array $overrides = [])
    {
        return $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.documents.store', $passenger), $overrides + [
                'type' => 'Passport',
                'number' => 'LR7654321',
                'issuing_country' => 'lb',
                'expiry_date' => '2031-05-01',
            ]);
    }

    // --- SR-23 / SR-31: validity ---------------------------------------------------------

    public function test_SR23_expired_passport_is_refused_then_a_valid_one_added_on_the_screen_succeeds(): void
    {
        $passenger = $this->passenger(passportExpiry: '2026-11-09');

        $this->confirm($passenger)->assertSessionHas('error', 'Document expired');
        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);

        $this->addDocument($passenger)
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('travel_documents', [
            'passenger_id' => $passenger->id,
            'type' => 'Passport',
            'number' => 'LR7654321',
            'issuing_country' => 'LB',
        ]);

        $this->confirm($passenger)->assertSessionHas('success');
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
    }

    public function test_SR23_refused_confirm_shows_the_message_in_red_on_the_screen(): void
    {
        $passenger = $this->passenger(passportExpiry: '2026-11-09');

        $this->followingRedirects()->post(route('checkin.passengers.confirm', $passenger))
            ->assertOk()
            ->assertSee('Travel documents')
            ->assertSee('role="alert"', false)
            ->assertSee('Document expired');
    }

    public function test_SR31_six_month_minimum_refuses_3_months_and_accepts_8_months(): void
    {
        Setting::updateOrCreate(['key' => 'passport_min_months'], ['value' => '6']);

        $tooSoon = $this->passenger(passportExpiry: '2027-02-10'); // 3 months after departure
        $this->confirm($tooSoon)->assertSessionHas('error', 'Document expired');
        $this->assertSame(PassengerStatus::Booked, $tooSoon->fresh()->status);

        $enough = $this->passenger();
        $this->addDocument($enough, ['expiry_date' => '2027-07-10'])->assertSessionHasNoErrors(); // 8 months
        $this->confirm($enough)->assertSessionHas('success');
        $this->assertSame(PassengerStatus::CheckedIn, $enough->fresh()->status);
    }

    // --- SR-34: masking ------------------------------------------------------------------

    public function test_SR34_gate_user_sees_masked_number_and_checkin_user_sees_full_number(): void
    {
        $document = $this->passenger(passportExpiry: '2030-01-01', number: 'LR1234567')->travelDocuments()->first();

        $this->assertSame('••••4567', $document->maskedNumberFor(User::factory()->make(['role' => Role::Gate])));
        $this->assertSame('••••4567', $document->maskedNumberFor(User::factory()->make(['role' => Role::Reservation])));
        $this->assertSame('LR1234567', $document->maskedNumberFor(User::factory()->make(['role' => Role::Checkin])));
        $this->assertSame('LR1234567', $document->maskedNumberFor(User::factory()->make(['role' => Role::Manager])));

        $this->get(route('checkin.passengers.show', $document->passenger))
            ->assertOk()
            ->assertSee('LR1234567')
            ->assertDontSee('••••4567');
    }

    // --- Verify ----------------------------------------------------------------------------

    public function test_verifying_a_document_sets_verified_by_and_verified_at(): void
    {
        $document = $this->passenger(passportExpiry: '2030-01-01')->travelDocuments()->first();
        $this->assertNull($document->verified_at);

        $this->from(route('checkin.passengers.show', $document->passenger))
            ->post(route('checkin.documents.verify', $document))
            ->assertRedirect(route('checkin.passengers.show', $document->passenger))
            ->assertSessionHas('success');

        $document->refresh();
        $this->assertSame($this->agent->id, $document->verified_by);
        $this->assertTrue($document->verified_at->equalTo(now()));

        $this->get(route('checkin.passengers.show', $document->passenger))
            ->assertSee($this->agent->name)
            ->assertSee('10:00');
    }

    // --- Correct ---------------------------------------------------------------------------

    public function test_correcting_a_document_number_writes_an_audit_row(): void
    {
        $document = $this->passenger(passportExpiry: '2030-01-01', number: 'LR1234567')->travelDocuments()->first();
        $document->forceFill(['verified_by' => $this->agent->id, 'verified_at' => now()])->save();

        $this->from(route('checkin.passengers.show', $document->passenger))
            ->put(route('checkin.documents.update', $document), [
                'type' => 'Passport',
                'number' => 'LR1234576',
                'issuing_country' => 'LB',
                'expiry_date' => '2030-01-01',
            ])
            ->assertRedirect(route('checkin.passengers.show', $document->passenger))
            ->assertSessionHasNoErrors();

        $document->refresh();
        $this->assertSame('LR1234576', $document->number);

        $entry = AuditEntry::sole();
        $this->assertSame($document->passenger_id, $entry->passenger_id);
        $this->assertSame('document_number', $entry->field);
        $this->assertSame('LR1234567', $entry->old_value);
        $this->assertSame('LR1234576', $entry->new_value);
        $this->assertSame($this->agent->id, $entry->user_id);
        $this->assertTrue($entry->changed_at->equalTo(now()));

        // A corrected number must be checked again.
        $this->assertNull($document->verified_at);
        $this->assertNull($document->verified_by);
    }

    public function test_saving_an_unchanged_document_writes_no_audit_row(): void
    {
        $document = $this->passenger(passportExpiry: '2030-01-01', number: 'LR1234567')->travelDocuments()->first();

        $this->put(route('checkin.documents.update', $document), [
            'type' => 'Passport',
            'number' => 'LR1234567',
            'issuing_country' => $document->issuing_country,
            'expiry_date' => '2030-01-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, AuditEntry::count());
    }

    // --- Validation --------------------------------------------------------------------------

    public function test_document_form_is_validated(): void
    {
        $passenger = $this->passenger();

        $this->addDocument($passenger, ['type' => 'DriverLicence', 'expiry_date' => 'next year'])
            ->assertSessionHasErrorsIn('addDocument', ['type', 'expiry_date']);

        $this->addDocument($passenger, ['number' => '', 'issuing_country' => 'LBN'])
            ->assertSessionHasErrorsIn('addDocument', ['number', 'issuing_country']);

        $this->assertSame(0, $passenger->travelDocuments()->count());
    }

    public function test_screen_lists_documents_and_offers_add_edit_and_verify(): void
    {
        $passenger = $this->passenger(passportExpiry: '2030-01-01');
        TravelDocument::factory()->for($passenger)->create([
            'type' => DocumentType::Visa, 'number' => 'V998877', 'issuing_country' => 'FR', 'expiry_date' => '2027-01-31',
        ]);
        $visa = $passenger->travelDocuments()->where('type', DocumentType::Visa)->first();

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSeeInOrder(['Passport', 'LR1234567'])
            ->assertSeeInOrder(['Visa', 'V998877', 'FR', '31 Jan 2027'])
            ->assertSee('Add document')
            ->assertSee(route('checkin.documents.verify', $visa), false)
            ->assertSee(route('checkin.passengers.show', [$passenger, 'edit' => $visa->id]), false);

        $this->get(route('checkin.passengers.show', [$passenger, 'edit' => $visa->id]))
            ->assertOk()
            ->assertSee(route('checkin.documents.update', $visa), false)
            ->assertSee('value="V998877"', false);
    }

    // --- Access ----------------------------------------------------------------------------

    public function test_gate_and_reservation_users_get_403_on_document_actions(): void
    {
        $passenger = $this->passenger(passportExpiry: '2030-01-01');
        $document = $passenger->travelDocuments()->first();
        $data = ['type' => 'Passport', 'number' => 'LR0000001', 'issuing_country' => 'LB', 'expiry_date' => '2031-01-01'];

        foreach (['gate', 'reservation'] as $role) {
            $this->actingAsRole($role);

            $this->post(route('checkin.documents.store', $passenger), $data)->assertForbidden();
            $this->put(route('checkin.documents.update', $document), $data)->assertForbidden();
            $this->post(route('checkin.documents.verify', $document))->assertForbidden();
        }

        $this->assertSame(1, $passenger->travelDocuments()->count());
        $this->assertSame('LR1234567', $document->fresh()->number);
        $this->assertNull($document->fresh()->verified_at);
    }

    public function test_document_actions_do_not_change_the_passenger_status(): void
    {
        $passenger = $this->passenger(passportExpiry: '2030-01-01');
        $document = $passenger->travelDocuments()->first();

        $this->addDocument($passenger);
        $this->post(route('checkin.documents.verify', $document));

        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
    }

    // --- Audit on add ------------------------------------------------------------------------

    public function test_adding_a_document_writes_an_audit_row(): void
    {
        $passenger = $this->passenger();

        $this->addDocument($passenger, ['type' => 'Visa'])->assertSessionHasNoErrors();

        $entry = AuditEntry::sole();
        $this->assertSame($passenger->id, $entry->passenger_id);
        $this->assertSame('document_added', $entry->field);
        $this->assertNull($entry->old_value);
        $this->assertSame('Visa', $entry->new_value);
        $this->assertSame($this->agent->id, $entry->user_id);
        $this->assertTrue($entry->changed_at->equalTo(now()));
    }

    // --- Only Booked and CheckedIn passengers ------------------------------------------------

    /**
     * A Cancelled passenger and a Boarded passenger, each with one passport.
     *
     * @return array<string, Passenger>
     */
    private function lockedPassengers(): array
    {
        $statuses = app(StatusService::class);

        $cancelled = $this->passenger(passportExpiry: '2030-01-01', number: 'LR1111111');
        $statuses->cancel($cancelled, User::factory()->create(['role' => Role::Reservation]));

        $boarded = $this->passenger(passportExpiry: '2030-01-01', number: 'LR2222222');
        $statuses->checkIn($boarded, $this->agent);
        $statuses->setFlightStatus($this->flight, FlightStatus::Boarding, $this->agent);
        $statuses->board($boarded, User::factory()->create(['role' => Role::Gate]));

        return ['Cancelled' => $cancelled, 'Boarded' => $boarded];
    }

    public function test_document_actions_are_refused_for_other_statuses(): void
    {
        $message = 'Documents can only be changed for a booked or checked-in passenger.';
        $passengers = $this->lockedPassengers();
        $auditRows = AuditEntry::count(); // after the status changes made by the setup

        foreach ($passengers as $status => $passenger) {
            $document = $passenger->travelDocuments()->first();
            $screen = route('checkin.passengers.show', $passenger);

            $this->addDocument($passenger)
                ->assertRedirect($screen)
                ->assertSessionHas('error', $message);

            $this->put(route('checkin.documents.update', $document), [
                'type' => 'Passport',
                'number' => 'LR9999999',
                'issuing_country' => 'LB',
                'expiry_date' => '2030-01-01',
            ])->assertRedirect($screen)->assertSessionHas('error', $message);

            $this->post(route('checkin.documents.verify', $document))
                ->assertRedirect($screen)
                ->assertSessionHas('error', $message);

            $this->assertSame(1, $passenger->travelDocuments()->count(), $status);
            $this->assertNotSame('LR9999999', $document->fresh()->number, $status);
            $this->assertNull($document->fresh()->verified_at, $status);
        }

        $this->assertSame($auditRows, AuditEntry::count());
    }

    public function test_forms_are_hidden_for_other_statuses(): void
    {
        foreach ($this->lockedPassengers() as $status => $passenger) {
            $document = $passenger->travelDocuments()->first();

            $this->get(route('checkin.passengers.show', [$passenger, 'edit' => $document->id]))
                ->assertOk()
                ->assertSee($document->number)                       // still listed
                ->assertDontSee('Add document')
                ->assertDontSee(route('checkin.documents.store', $passenger), false)
                ->assertDontSee(route('checkin.documents.verify', $document), false)
                ->assertDontSee(route('checkin.documents.update', $document), false);
        }
    }

    public function test_checked_in_passenger_can_still_add_edit_and_verify(): void
    {
        $passenger = $this->passenger(passportExpiry: '2030-01-01');
        app(StatusService::class)->checkIn($passenger, $this->agent);
        $document = $passenger->travelDocuments()->first();

        $this->get(route('checkin.passengers.show', $passenger))->assertSee('Add document');

        $this->addDocument($passenger, ['type' => 'Visa'])->assertSessionHas('success');
        $this->post(route('checkin.documents.verify', $document))->assertSessionHas('success');
        $this->put(route('checkin.documents.update', $document), [
            'type' => 'Passport',
            'number' => 'LR7777777',
            'issuing_country' => 'LB',
            'expiry_date' => '2030-01-01',
        ])->assertSessionHas('success');

        $this->assertSame(2, $passenger->travelDocuments()->count());
        $this->assertSame('LR7777777', $document->fresh()->number);
    }
}
