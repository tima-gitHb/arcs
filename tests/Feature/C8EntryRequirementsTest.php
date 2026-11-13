<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\EntryStatus;
use App\Enums\FlightStatus;
use App\Enums\PassengerStatus;
use App\Enums\Role;
use App\Models\AuditEntry;
use App\Models\EntryRule;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\EntryRequirementChecker;
use Database\Seeders\EntryRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class C8EntryRequirementsTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Visa checked on paper';

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        // flightWithStatus(): check-in 09:00–11:15, departure 2026-11-10 12:00.
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $this->agent = $this->actingAsRole('checkin');

        // The small fixture, not the full CSV (LB → IT is left out on purpose).
        $this->seed(EntryRuleSeeder::class);
    }

    private function flightTo(string $airport, FlightStatus $status = FlightStatus::CheckinOpen): Flight
    {
        return $this->flightWithStatus($status, ['destination' => $airport]);
    }

    private function passengerOn(Flight $flight, string $passportCountry = 'LB'): Passenger
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create();
        TravelDocument::factory()->for($passenger)->create([
            'type' => DocumentType::Passport,
            'issuing_country' => $passportCountry,
            'expiry_date' => '2030-01-01',
        ]);
        $this->seatPassenger($passenger);

        return $passenger;
    }

    private function document(Passenger $passenger, DocumentType $type, string $country, string $expiry = '2028-01-01'): void
    {
        TravelDocument::factory()->for($passenger)->create([
            'type' => $type, 'issuing_country' => $country, 'expiry_date' => $expiry,
        ]);
    }

    private function check(Passenger $passenger)
    {
        return app(EntryRequirementChecker::class)->check($passenger->fresh());
    }

    private function confirm(Passenger $passenger, ?string $reason = null)
    {
        return $this->from(route('checkin.passengers.show', $passenger))
            ->post(route('checkin.passengers.confirm', $passenger), array_filter(['entry_reason' => $reason]));
    }

    // --- The check ---------------------------------------------------------------------------

    public function test_SR49_lebanese_passport_to_france_without_a_visa_shows_visa_required(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));

        $result = $this->check($passenger);

        $this->assertSame(EntryStatus::Missing, $result->status);
        $this->assertSame('Visa required for France', $result->message);

        $this->get(route('checkin.passengers.show', [$passenger, 'check_entry' => 1]))
            ->assertOk()
            ->assertSee('Visa required for France')
            ->assertSee('Rules as of 12 January 2025');
    }

    public function test_SR49_a_citizen_is_ok(): void
    {
        $result = $this->check($this->passengerOn($this->flightTo('CDG'), 'FR'));

        $this->assertSame(EntryStatus::Ok, $result->status);
        $this->assertStringContainsString('France', $result->message);
    }

    public function test_SR49_visa_free_days_and_visa_on_arrival_are_ok_without_a_visa(): void
    {
        $this->assertSame(EntryStatus::Ok, $this->check($this->passengerOn($this->flightTo('FCO'), 'FR'))->status);
        $this->assertSame(EntryStatus::Ok, $this->check($this->passengerOn($this->flightTo('IST')))->status);
        $this->assertSame(EntryStatus::Ok, $this->check($this->passengerOn($this->flightTo('CDG'), 'US'))->status);
    }

    public function test_SR49_e_visa_and_eta_need_a_document_like_a_visa(): void
    {
        $dubai = $this->passengerOn($this->flightTo('DXB'));
        $london = $this->passengerOn($this->flightTo('LHR'));

        $this->assertSame('Visa required for United Arab Emirates', $this->check($dubai)->message);
        $this->assertSame(EntryStatus::Missing, $this->check($london)->status);

        $this->document($dubai, DocumentType::Visa, 'AE');
        $this->assertSame(EntryStatus::Ok, $this->check($dubai)->status);
    }

    public function test_SR49_a_residence_permit_issued_by_the_destination_counts_like_a_visa(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));
        $this->document($passenger, DocumentType::ResidencePermit, 'FR');

        $this->assertSame(EntryStatus::Ok, $this->check($passenger)->status);
    }

    public function test_SR49_an_expired_visa_or_one_from_another_country_does_not_count(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));
        $this->document($passenger, DocumentType::Visa, 'FR', '2026-11-09');
        $this->document($passenger, DocumentType::Visa, 'GB');

        $this->assertSame(EntryStatus::Missing, $this->check($passenger)->status);
    }

    // --- Schengen ------------------------------------------------------------------------------

    public function test_SR49_the_schengen_list_has_29_members_without_cyprus_or_ireland(): void
    {
        $members = config('schengen');

        $this->assertCount(29, $members);
        $this->assertContains('FR', $members);
        $this->assertContains('CH', $members);
        $this->assertNotContains('CY', $members);
        $this->assertNotContains('IE', $members);
    }

    public function test_SR49_a_spain_residence_permit_covers_france(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));
        $this->document($passenger, DocumentType::ResidencePermit, 'ES');

        $result = $this->check($passenger);

        $this->assertSame(EntryStatus::Ok, $result->status);
        $this->assertSame('OK: Spain residence permit (Schengen)', $result->message);
        $this->assertSame('The 90/180-day Schengen stay limit is not checked.', $result->note);

        $this->get(route('checkin.passengers.show', [$passenger, 'check_entry' => 1]))
            ->assertSee('OK: Spain residence permit (Schengen)')
            ->assertSee('The 90/180-day Schengen stay limit is not checked.');

        // Covered: Confirm check-in asks for no reason.
        $this->confirm($passenger)->assertSessionHas('success');
        $this->assertSame(0, AuditEntry::where('field', 'entry_override')->count());
    }

    public function test_SR49_an_italy_visa_covers_france(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));
        $this->document($passenger, DocumentType::Visa, 'IT');

        $result = $this->check($passenger);

        $this->assertSame(EntryStatus::Ok, $result->status);
        $this->assertSame('OK: Italy visa (Schengen)', $result->message);
    }

    public function test_SR49_a_document_from_the_destination_itself_keeps_its_message(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));
        $this->document($passenger, DocumentType::Visa, 'ES');
        $this->document($passenger, DocumentType::Visa, 'FR');

        $result = $this->check($passenger);

        $this->assertSame('Visa or residence permit for France found', $result->message);
        $this->assertNull($result->note);
    }

    public function test_SR49_a_schengen_document_does_not_cover_the_united_kingdom(): void
    {
        $passenger = $this->passengerOn($this->flightTo('LHR'));
        $this->document($passenger, DocumentType::ResidencePermit, 'ES');

        $result = $this->check($passenger);

        $this->assertSame(EntryStatus::Missing, $result->status);
        $this->assertSame('Visa required for United Kingdom', $result->message);
    }

    public function test_SR49_a_cyprus_visa_does_not_cover_france(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));
        $this->document($passenger, DocumentType::Visa, 'CY');

        $this->assertSame('Visa required for France', $this->check($passenger)->message);
    }

    public function test_SR49_an_expired_spain_permit_does_not_cover_france(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));
        $this->document($passenger, DocumentType::ResidencePermit, 'ES', '2026-11-09');

        $this->assertSame(EntryStatus::Missing, $this->check($passenger)->status);
    }

    public function test_SR49_a_schengen_destination_with_a_visa_free_pair_is_unchanged(): void
    {
        $result = $this->check($this->passengerOn($this->flightTo('FCO'), 'FR'));

        $this->assertSame(EntryStatus::Ok, $result->status);
        $this->assertSame('No visa needed for Italy', $result->message);
        $this->assertNull($result->note);
    }

    public function test_SR49_no_admission_is_refused(): void
    {
        $result = $this->check($this->passengerOn($this->flightTo('BEY'), 'IL'));

        $this->assertSame(EntryStatus::Refused, $result->status);
        $this->assertSame('No admission to Lebanon on a passport from Israel', $result->message);
        $this->assertTrue($result->needsAcknowledgement());
    }

    public function test_SR49_a_pair_missing_from_the_table_is_unknown_and_needs_no_reason(): void
    {
        $passenger = $this->passengerOn($this->flightTo('FCO'));

        $result = $this->check($passenger);

        $this->assertSame(EntryStatus::Unknown, $result->status);
        $this->assertSame('No entry rule for Lebanon → Italy, check manually', $result->message);

        $this->confirm($passenger)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
        $this->assertSame(0, AuditEntry::where('field', 'entry_override')->count());
    }

    public function test_SR49_without_a_valid_passport_the_result_is_unknown(): void
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($this->flightTo('CDG')))->create();
        $this->document($passenger, DocumentType::IdCard, 'LB');

        $this->assertSame(EntryStatus::Unknown, $this->check($passenger)->status);
    }

    // --- Check requirements button -------------------------------------------------------------

    public function test_SR49_check_requirements_changes_nothing(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertSee('Check requirements')
            ->assertSee(route('checkin.passengers.show', [$passenger, 'check_entry' => 1]), false)
            ->assertDontSee('Rules as of');

        $this->get(route('checkin.passengers.show', [$passenger, 'check_entry' => 1]))->assertOk();

        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
        $this->assertSame(0, AuditEntry::whereNotNull('passenger_id')->count());
    }

    // --- Acknowledgement at Confirm check-in ---------------------------------------------------

    public function test_SR49_confirming_without_a_reason_is_refused_with_a_validation_error(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertSee('Visa required for France')
            ->assertSee('name="entry_reason"', false);

        $this->confirm($passenger)
            ->assertRedirect(route('checkin.passengers.show', $passenger))
            ->assertSessionHasErrors('entry_reason');

        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
        $this->assertSame(0, $passenger->boardingPasses()->count());
        $this->assertSame(0, AuditEntry::whereNotNull('passenger_id')->count());
    }

    public function test_SR49_with_a_reason_check_in_succeeds_and_the_audit_row_has_reason_and_result(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));

        $this->confirm($passenger, self::REASON)->assertSessionHas('success');

        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
        $this->assertNotNull($passenger->fresh()->activeBoardingPass);

        $entry = AuditEntry::where('field', 'entry_override')->sole();
        $this->assertSame($passenger->id, $entry->passenger_id);
        $this->assertSame('Visa required for France', $entry->old_value);
        $this->assertSame(self::REASON, $entry->new_value);
        $this->assertSame($this->agent->id, $entry->user_id);
        $this->assertTrue($entry->changed_at->equalTo(now()));
    }

    public function test_SR49_no_admission_also_needs_a_reason_but_does_not_block(): void
    {
        $passenger = $this->passengerOn($this->flightTo('BEY'), 'IL');

        $this->confirm($passenger)->assertSessionHasErrors('entry_reason');
        $this->confirm($passenger, self::REASON)->assertSessionHas('success');

        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
    }

    public function test_SR49_with_a_french_visa_added_on_the_panel_no_reason_is_asked(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));

        $this->post(route('checkin.documents.store', $passenger), [
            'type' => 'Visa', 'number' => 'V123456', 'issuing_country' => 'FR', 'expiry_date' => '2028-01-01',
        ])->assertSessionHasNoErrors();

        $this->get(route('checkin.passengers.show', $passenger))
            ->assertOk()
            ->assertDontSee('name="entry_reason"', false);

        $this->confirm($passenger)->assertSessionHas('success');

        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
        $this->assertSame(0, AuditEntry::where('field', 'entry_override')->count());
    }

    public function test_SR49_existing_refusals_keep_their_order_and_messages(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::Scheduled, ['destination' => 'CDG']);
        $passenger = $this->passengerOn($flight);

        $this->confirm($passenger)
            ->assertSessionHas('error', 'Check-in closed')
            ->assertSessionDoesntHaveErrors('entry_reason');
    }

    public function test_SR49_a_reason_too_long_for_the_audit_log_is_refused(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));

        $this->confirm($passenger, str_repeat('x', 256))->assertSessionHasErrors('entry_reason');

        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
    }

    // --- Late check-in at the gate -------------------------------------------------------------

    public function test_SR49_the_late_check_in_at_the_gate_follows_the_same_rule(): void
    {
        $flight = $this->flightTo('CDG', FlightStatus::Boarding);
        $passenger = $this->passengerOn($flight);
        $manager = User::factory()->create(['role' => Role::Manager, 'email' => 'boss@arcs.test']);
        $gate = $this->actingAsRole('gate');
        $form = route('gate.passengers.late-checkin', $passenger);
        $data = ['reason' => 'Coach delayed', 'manager_email' => $manager->email, 'password' => 'password'];

        $this->get($form)
            ->assertOk()
            ->assertSee('Visa required for France')
            ->assertSee('name="entry_reason"', false);

        $this->from($form)->post(route('gate.passengers.late-checkin.store', $passenger), $data)
            ->assertRedirect($form)
            ->assertSessionHasErrors('entry_reason');
        $this->assertSame(PassengerStatus::Booked, $passenger->fresh()->status);
        $this->assertNull($passenger->fresh()->gateOverride);

        $this->post(route('gate.passengers.late-checkin.store', $passenger), $data + ['entry_reason' => self::REASON])
            ->assertSessionHas('success');

        $this->assertSame(PassengerStatus::CheckedIn, $passenger->fresh()->status);
        $entry = AuditEntry::where('field', 'entry_override')->sole();
        $this->assertSame('Visa required for France', $entry->old_value);
        $this->assertSame(self::REASON, $entry->new_value);
        $this->assertSame($gate->id, $entry->user_id);
    }

    // --- Import --------------------------------------------------------------------------------

    public function test_SR49_the_import_command_loads_the_csv_and_can_run_twice(): void
    {
        EntryRule::query()->delete();

        $this->artisan('arcs:import-entry-rules')->assertSuccessful();
        $rows = EntryRule::count();

        $this->assertSame(39601, $rows); // every line of the CSV except the header
        $this->assertSame('visa required', EntryRule::where(['passport_country' => 'LB', 'destination_country' => 'FR'])->value('requirement'));

        $this->artisan('arcs:import-entry-rules')->assertSuccessful();
        $this->assertSame($rows, EntryRule::count());
    }

    // --- Countries for staff -------------------------------------------------------------------

    public function test_SR49_the_document_form_shows_country_names_and_saves_the_iso_code(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));
        $document = $passenger->travelDocuments()->first();

        $this->get(route('checkin.passengers.show', [$passenger, 'edit' => $document->id]))
            ->assertOk()
            ->assertSeeInOrder(['value="FR"', 'France'], false)
            ->assertSee('value="LB" selected', false) // the edit form keeps the country
            ->assertSee('Lebanon')
            ->assertDontSee('name="issuing_country" type="text"', false);

        $this->post(route('checkin.documents.store', $passenger), [
            'type' => 'Visa', 'number' => 'V123456', 'issuing_country' => 'FR', 'expiry_date' => '2028-01-01',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('travel_documents', ['number' => 'V123456', 'issuing_country' => 'FR']);

        // The panel lists the name, not the code.
        $response = $this->get(route('checkin.passengers.show', $passenger))->assertOk();
        preg_match('/<tbody>[\s\S]*?<\/tbody>/', $response->getContent(), $panel);
        $this->assertStringContainsString('France', $panel[0]);
        $this->assertStringNotContainsString('>FR<', $panel[0]);
    }

    public function test_SR49_a_corrected_country_shows_as_a_name_in_the_audit_log(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));
        $document = $passenger->travelDocuments()->first();

        $this->put(route('checkin.documents.update', $document), [
            'type' => 'Passport',
            'number' => $document->number,
            'issuing_country' => 'FR',
            'expiry_date' => '2030-01-01',
        ])->assertSessionHasNoErrors();

        $this->assertSame('FR', $document->fresh()->issuing_country);

        $entry = AuditEntry::where('field', 'document_country')->sole();
        $this->assertSame('Lebanon', $entry->old_value);
        $this->assertSame('France', $entry->new_value);
    }

    public function test_SR49_the_country_must_be_a_real_code(): void
    {
        $passenger = $this->passengerOn($this->flightTo('CDG'));

        $this->post(route('checkin.documents.store', $passenger), [
            'type' => 'Visa', 'number' => 'V123456', 'issuing_country' => 'QQ', 'expiry_date' => '2028-01-01',
        ])->assertSessionHasErrorsIn('addDocument', ['issuing_country']);

        $this->assertDatabaseMissing('travel_documents', ['number' => 'V123456']);
    }
}
