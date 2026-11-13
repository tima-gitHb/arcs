<?php

namespace Tests\Feature;

use App\Enums\EntryStatus;
use App\Enums\FlightStatus;
use App\Enums\PaymentKind;
use App\Enums\Role;
use App\Exceptions\InvalidStatusChange;
use App\Mail\ETicketMail;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\AccountService;
use App\Services\BoardingPassService;
use App\Services\EntryRequirementChecker;
use App\Services\Payments\CardDetails;
use App\Services\PaymentService;
use App\Services\StatusService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * B13a — Sprint 3 base, part 1: airports, staff stations, UTC storage with local display,
 * and each station seeing its own flights.
 *
 * Time zones used below (November 2026, no summer time): BEY = UTC+2, CDG = UTC+1, IST = UTC+3.
 * July 2026 (summer time): BEY = UTC+3, CDG = UTC+2.
 */
class B13aStationsTimeTest extends TestCase
{
    use RefreshDatabase;

    private function staff(Role $role, ?string $station): User
    {
        $user = User::factory()->create(['role' => $role, 'airport_code' => $station]);
        $this->actingAs($user);

        return $user;
    }

    private function passengerOn(Flight $flight, string $pnr = 'K7M2NP'): Passenger
    {
        $reservation = Reservation::factory()->for($flight)->create(['pnr' => $pnr]);
        $passenger = Passenger::factory()->for($reservation)->create(['first_name' => 'Lea', 'last_name' => 'Morel']);
        TravelDocument::factory()->for($passenger)->create();

        return $passenger;
    }

    // --- 1. Airports table -------------------------------------------------------------------

    public function test_airports_table_holds_the_network_with_country_and_time_zone(): void
    {
        $expected = [
            'BEY' => ['LB', 'Asia/Beirut'],
            'CDG' => ['FR', 'Europe/Paris'],
            'IST' => ['TR', 'Europe/Istanbul'],
            'DXB' => ['AE', 'Asia/Dubai'],
            'LHR' => ['GB', 'Europe/London'],
            'JFK' => ['US', 'America/New_York'],
            'FCO' => ['IT', 'Europe/Rome'],
            // Previously in config/airports.php.
            'AMM' => ['JO', 'Asia/Amman'],
            'ATH' => ['GR', 'Europe/Athens'],
            'AUH' => ['AE', 'Asia/Dubai'],
            'CAI' => ['EG', 'Africa/Cairo'],
            'DOH' => ['QA', 'Asia/Qatar'],
            'FRA' => ['DE', 'Europe/Berlin'],
            'GVA' => ['CH', 'Europe/Zurich'],
            'JED' => ['SA', 'Asia/Riyadh'],
            'KWI' => ['KW', 'Asia/Kuwait'],
            'LCA' => ['CY', 'Asia/Nicosia'],
            'ORY' => ['FR', 'Europe/Paris'],
            'RUH' => ['SA', 'Asia/Riyadh'],
        ];

        foreach ($expected as $code => [$country, $zone]) {
            $airport = Airport::find($code);
            $this->assertNotNull($airport, "$code missing");
            $this->assertSame($country, $airport->country, $code);
            $this->assertSame($zone, $airport->timezone, $code);
            $this->assertNotEmpty($airport->name);
            $this->assertNotEmpty($airport->city);
            $this->assertSame(60, $airport->min_connection_minutes, $code);
        }
    }

    public function test_every_airport_time_zone_is_a_real_iana_name(): void
    {
        foreach (Airport::all() as $airport) {
            $this->assertContains($airport->timezone, timezone_identifiers_list(), $airport->code);
        }
    }

    public function test_a_flight_cannot_use_an_unknown_airport(): void
    {
        $this->expectException(QueryException::class);

        Flight::factory()->create(['origin' => 'ZZZ']);
    }

    public function test_a_flight_cannot_fly_to_an_unknown_airport(): void
    {
        $this->expectException(QueryException::class);

        Flight::factory()->create(['destination' => 'ZZZ']);
    }

    public function test_the_flight_form_only_accepts_existing_airports(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 08:00', 'UTC'));
        $this->staff(Role::Manager, 'BEY');
        $aircraft = Aircraft::factory()->create();

        $this->post(route('manager.flights.store'), [
            'flight_no' => 'ME205', 'origin' => 'ZZZ', 'destination' => 'QQQ', 'aircraft_id' => $aircraft->id,
            'departure_at' => '2026-11-11T14:00', 'checkin_opens_at' => '2026-11-11T11:00',
            'checkin_closes_at' => '2026-11-11T13:15', 'gate' => 'B4',
        ])->assertSessionHasErrors(['origin', 'destination']);

        $this->assertSame(0, Flight::count());
    }

    public function test_the_flight_form_takes_times_in_the_origin_airports_local_time(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 08:00', 'UTC'));
        $this->staff(Role::Manager, 'BEY');
        $aircraft = Aircraft::factory()->create();

        // Typed as Paris time (UTC+1 in November): stored one hour earlier in UTC.
        $this->post(route('manager.flights.store'), [
            'flight_no' => 'ME202', 'origin' => 'CDG', 'destination' => 'BEY', 'aircraft_id' => $aircraft->id,
            'departure_at' => '2026-11-11T14:00', 'checkin_opens_at' => '2026-11-11T11:00',
            'checkin_closes_at' => '2026-11-11T13:15', 'gate' => '',
        ])->assertSessionHasNoErrors();

        $flight = Flight::sole();
        $this->assertSame('2026-11-11 13:00:00', $flight->getRawOriginal('departure_at'));
        $this->assertSame('2026-11-11 10:00:00', $flight->getRawOriginal('checkin_opens_at'));
        $this->assertSame('2026-11-11 12:15:00', $flight->getRawOriginal('checkin_closes_at'));
    }

    public function test_SR49_entry_check_reads_the_destination_country_from_the_airports_table(): void
    {
        $this->assertNull(config('airports'));
        $this->assertFileDoesNotExist(config_path('airports.php'));

        // An airport added to the table is known to the entry check straight away.
        Airport::create([
            'code' => 'MXP', 'name' => 'Milan Malpensa', 'city' => 'Milan', 'country' => 'IT', 'timezone' => 'Europe/Rome',
        ]);
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['destination' => 'MXP']);
        $passenger = $this->passengerOn($flight);
        $passenger->travelDocuments()->update(['issuing_country' => 'IT']);

        $result = app(EntryRequirementChecker::class)->check($passenger);

        $this->assertSame(EntryStatus::Ok, $result->status);
        $this->assertStringContainsString('Italy', $result->message);
    }

    // --- 2. Station on staff -----------------------------------------------------------------

    public function test_seeded_staff_work_at_beirut_and_paris_has_one_account_per_role(): void
    {
        $this->seed(UserSeeder::class);

        foreach (['reservation', 'checkin', 'gate', 'manager'] as $role) {
            $this->assertSame('BEY', User::where('email', "{$role}@arcs.test")->value('airport_code'), $role);

            $paris = User::where('role', $role)->where('airport_code', 'CDG')->sole();
            $this->assertStringContainsString('Paris', $paris->name);
            $this->assertTrue(password_verify('password', $paris->password));
        }
    }

    public function test_account_service_creates_an_account_at_an_airport(): void
    {
        Notification::fake();

        $user = app(AccountService::class)->create([
            'name' => 'Claire Petit', 'email' => 'claire@arcs.test', 'role' => 'checkin', 'airport_code' => 'CDG',
        ]);

        $this->assertSame('CDG', $user->fresh()->airport_code);
        $this->assertSame('Europe/Paris', $user->airport->timezone);
    }

    public function test_check_in_staff_without_a_station_are_told_to_ask_their_manager(): void
    {
        $this->staff(Role::Checkin, null);

        $this->get(route('checkin.home'))->assertForbidden()->assertSee('No station set: ask your manager');
    }

    public function test_gate_staff_without_a_station_are_told_to_ask_their_manager(): void
    {
        $this->staff(Role::Gate, null);
        $flight = $this->flightWithStatus(FlightStatus::Boarding);

        $this->get(route('gate.home'))->assertForbidden()->assertSee('No station set: ask your manager');
        $this->get(route('gate.flights.show', $flight))->assertForbidden()->assertSee('No station set: ask your manager');
    }

    public function test_reservation_staff_and_managers_without_a_station_still_work(): void
    {
        $this->staff(Role::Reservation, null);
        $this->get(route('reservation.create'))->assertOk();

        $this->staff(Role::Manager, null);
        $this->get(route('manager.dashboard'))->assertOk();
    }

    // --- 3. UTC storage, local display -------------------------------------------------------

    public function test_the_app_and_mysql_use_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('+00:00', config('database.connections.mysql.timezone'));

        if (DB::getDriverName() === 'mysql') {
            $this->assertSame('+00:00', DB::selectOne('select @@session.time_zone as tz')->tz);
        }
    }

    public function test_a_local_time_given_to_a_model_is_stored_in_utc(): void
    {
        $flight = Flight::factory()->create([
            'origin' => 'CDG', 'destination' => 'BEY',
            'departure_at' => Carbon::parse('2026-07-01 14:00', 'Europe/Paris'),
        ]);

        $this->assertSame('2026-07-01 12:00:00', $flight->fresh()->getRawOriginal('departure_at'));
    }

    public function test_departure_local_is_in_the_origin_airports_time_zone(): void
    {
        $toParis = Flight::factory()->create(['origin' => 'BEY', 'destination' => 'CDG', 'departure_at' => '2026-07-01 12:00']);
        $toBeirut = Flight::factory()->create(['origin' => 'CDG', 'destination' => 'BEY', 'departure_at' => '2026-07-01 12:00']);
        $winter = Flight::factory()->create(['origin' => 'BEY', 'destination' => 'CDG', 'departure_at' => '2026-11-15 12:00']);

        $this->assertSame('2026-07-01 15:00', $toParis->departureLocal()->format('Y-m-d H:i'));
        $this->assertSame('Asia/Beirut', $toParis->departureLocal()->tzName);
        $this->assertSame('2026-07-01 14:00', $toBeirut->departureLocal()->format('Y-m-d H:i'));
        $this->assertSame('14:00', $winter->departureLocal()->format('H:i'));

        // The stored value is not changed by asking for the local time.
        $this->assertSame('UTC', $toParis->departure_at->tzName);
        $this->assertSame('12:00', $toParis->departure_at->format('H:i'));
    }

    public function test_local_time_component_shows_the_airports_time(): void
    {
        $at = Carbon::parse('2026-11-15 12:00', 'UTC');

        $this->blade('<x-local-time :at="$at" airport="CDG" format="d M H:i" />', ['at' => $at])
            ->assertSee('15 Nov 13:00')
            ->assertSee('datetime="2026-11-15T12:00:00Z"', false);

        $this->blade('<x-local-time :at="$at" airport="IST" format="H:i" />', ['at' => $at])->assertSee('15:00');
        $this->blade('<x-local-time :at="null" airport="IST" />', [])->assertSee('—');
    }

    public function test_station_time_component_uses_the_viewers_station_or_labelled_utc(): void
    {
        $at = Carbon::parse('2026-11-15 12:00', 'UTC');

        $this->staff(Role::Checkin, 'CDG');
        $this->blade('<x-station-time :at="$at" />', ['at' => $at])->assertSee('15 Nov 2026 13:00')->assertDontSee('UTC');

        $this->staff(Role::Manager, null);
        $this->blade('<x-station-time :at="$at" />', ['at' => $at])->assertSee('15 Nov 2026 12:00 UTC');
    }

    public function test_SR41_todays_flights_shows_departure_in_the_origin_local_time(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 06:00', 'UTC'));
        $this->staff(Role::Manager, 'BEY');
        Flight::factory()->create(['flight_no' => 'ME202', 'origin' => 'CDG', 'destination' => 'BEY', 'departure_at' => '2026-11-15 09:00']);
        Flight::factory()->create(['flight_no' => 'ME201', 'origin' => 'BEY', 'destination' => 'CDG', 'departure_at' => '2026-11-15 09:30']);

        $this->blade('<x-todays-flights role="manager" />')
            ->assertSeeInOrder(['ME202', 'CDG → BEY', '10:00', 'ME201', 'BEY → CDG', '11:30']);
    }

    public function test_SR8_boarding_pass_shows_departure_and_boarding_in_the_origin_local_time(): void
    {
        // Paris: UTC+1. Departure 12:00 UTC = 13:00 Paris; boarding 40 minutes before.
        $this->travelTo(Carbon::parse('2026-11-15 10:00', 'UTC'));
        $agent = $this->staff(Role::Checkin, 'CDG');
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['origin' => 'CDG', 'destination' => 'BEY']);
        $passenger = $this->passengerOn($flight);
        $this->seatPassenger($passenger);
        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');

        $boarding = app(BoardingPassService::class)->boardingTime($flight);
        $this->get(route('checkin.boarding-pass.show', $passenger))
            ->assertOk()
            ->assertSee('13:00')
            ->assertSee($boarding->copy()->tz('Europe/Paris')->format('H:i'))
            ->assertSee('11:00'); // issued at 10:00 UTC, seen by a Paris agent
        $this->assertNotNull($agent);
    }

    public function test_e_ticket_email_shows_departure_in_the_origin_local_time(): void
    {
        $flight = Flight::factory()->create(['origin' => 'IST', 'destination' => 'CDG', 'departure_at' => '2026-11-15 12:00']);
        $reservation = Reservation::factory()->for($flight)->create(['contact_email' => 'lea@example.test']);
        Passenger::factory()->for($reservation)->create();
        $this->travelTo(Carbon::parse('2026-11-10 09:00', 'UTC'));
        $payment = app(PaymentService::class)->charge(
            new CardDetails('4242 4242 4242 4242', '12/30', '123'), 1000, PaymentKind::Fare,
            User::factory()->create(['role' => Role::Reservation, 'airport_code' => 'CDG']), reservation: $reservation,
        );

        $html = (new ETicketMail($reservation->fresh(), $payment))->render();

        // Departure in Istanbul time (UTC+3); paid at in the Paris agent's station time (UTC+1).
        $this->assertStringContainsString('15 Nov 2026 15:00', $html);
        $this->assertStringContainsString('10 Nov 2026 10:00', $html);
        $this->assertStringNotContainsString('Beirut', $html);
    }

    public function test_audit_log_shows_when_it_happened_in_the_viewers_station_time(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 12:00', 'UTC'));
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);

        $this->staff(Role::Manager, 'CDG');
        $this->get(route('manager.audit', ['flight_id' => $flight->id]))->assertOk()->assertSee('15 Nov 2026 13:00');

        $this->staff(Role::Manager, null);
        $this->get(route('manager.audit', ['flight_id' => $flight->id]))->assertOk()->assertSee('15 Nov 2026 12:00 UTC');
    }

    public function test_audit_and_payment_day_filters_use_the_viewers_station_day(): void
    {
        $this->seedBookingPrices();
        $flight = Flight::factory()->create(['origin' => 'IST', 'destination' => 'CDG']);
        $reservation = Reservation::factory()->for($flight)->create();
        $agent = User::factory()->create(['role' => Role::Reservation]);

        // 22:30 UTC on the 14th is already the 15th in Istanbul (UTC+3, 01:30), still the 14th in Paris (UTC+1, 23:30).
        $this->travelTo(Carbon::parse('2026-11-14 22:30', 'UTC'));
        app(PaymentService::class)->charge(
            new CardDetails('4242 4242 4242 4242', '12/30', '123'), 1000, PaymentKind::Fee, $agent, reservation: $reservation,
        );
        AuditEntry::forFlight($flight, 'gate', 'A1', 'A2', $agent);

        $this->staff(Role::Manager, 'IST');
        $this->assertCount(1, $this->get(route('manager.payments', ['date' => '2026-11-15']))->viewData('payments'));
        $this->assertCount(0, $this->get(route('manager.payments', ['date' => '2026-11-14']))->viewData('payments'));
        $this->get(route('manager.audit', ['date' => '2026-11-15']))->assertSee('15 Nov 2026 01:30');

        $this->staff(Role::Manager, 'CDG');
        $this->assertCount(1, $this->get(route('manager.payments', ['date' => '2026-11-14']))->viewData('payments'));
        $this->assertCount(0, $this->get(route('manager.payments', ['date' => '2026-11-15']))->viewData('payments'));
        $this->get(route('manager.audit', ['date' => '2026-11-14']))->assertSee('14 Nov 2026 23:30');
    }

    /**
     * A passport must be valid on the departure date at the origin airport, not the UTC date.
     * BEY 01:00 on the 16th (local) = 23:00 UTC on the 15th: a passport ending on the 15th is expired.
     */
    public function test_SR23_document_validity_uses_the_local_departure_date_at_beirut(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 21:30', 'UTC'));
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'origin' => 'BEY', 'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-16 01:00', 'Asia/Beirut'),
        ]);
        $passenger = $this->passengerOn($flight);
        $passenger->travelDocuments()->update(['expiry_date' => '2026-11-15']);
        $this->seatPassenger($passenger);

        $this->expectException(InvalidStatusChange::class);
        app(StatusService::class)->checkIn($passenger, User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'BEY']));
    }

    /**
     * JFK 20:00 on the 15th (local) = 01:00 UTC on the 16th: a passport ending on the 15th is still valid.
     */
    public function test_SR23_document_validity_uses_the_local_departure_date_at_new_york(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 18:30', 'America/New_York'));
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'origin' => 'JFK', 'destination' => 'CDG',
            'departure_at' => Carbon::parse('2026-11-15 20:00', 'America/New_York'),
        ]);
        $passenger = $this->passengerOn($flight);
        $passenger->travelDocuments()->update(['expiry_date' => '2026-11-15']);
        $this->seatPassenger($passenger);

        app(StatusService::class)->checkIn($passenger, User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'JFK']));

        $this->assertSame('CheckedIn', $passenger->fresh()->status->value);
        // The entry check (SR-49) also counts the passport as valid on that day.
        $this->assertStringNotContainsString('No valid passport', app(EntryRequirementChecker::class)->check($passenger->fresh())->message);
    }

    public function test_SR30_check_in_window_is_the_same_instant_in_every_time_zone(): void
    {
        // Window 10:00–12:00 UTC = 11:00–13:00 Paris = 12:00–14:00 Beirut.
        $this->travelTo(Carbon::parse('2026-11-15 10:30', 'UTC'));
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'origin' => 'CDG', 'destination' => 'BEY',
            'checkin_opens_at' => Carbon::parse('2026-11-15 11:00', 'Europe/Paris'),
            'checkin_closes_at' => Carbon::parse('2026-11-15 14:00', 'Asia/Beirut'),
            'departure_at' => Carbon::parse('2026-11-15 13:00', 'UTC'),
        ]);
        $flight = $flight->fresh();

        $this->travelTo(Carbon::parse('2026-11-15 10:59', 'Europe/Paris')); // 09:59 UTC
        $this->assertFalse($flight->isCheckinWindowOpen());

        $this->travelTo(Carbon::parse('2026-11-15 12:00', 'Asia/Beirut')); // 10:00 UTC
        $this->assertTrue($flight->isCheckinWindowOpen());

        $this->travelTo(Carbon::parse('2026-11-15 13:00', 'Europe/Paris')); // 12:00 UTC
        $this->assertTrue($flight->isCheckinWindowOpen());

        $this->travelTo(Carbon::parse('2026-11-15 15:01', 'Europe/Istanbul')); // 12:01 UTC
        $this->assertFalse($flight->isCheckinWindowOpen());
        $this->assertTrue($flight->isLateCheckInOpen());

        // StatusService agrees: a Paris agent at 12:01 UTC is too late.
        $passenger = $this->passengerOn($flight);
        $this->seatPassenger($passenger);
        $this->expectException(InvalidStatusChange::class);
        app(StatusService::class)->checkIn($passenger, User::factory()->create(['role' => Role::Checkin, 'airport_code' => 'CDG']));
    }

    // --- 4. Each station sees its own flights ------------------------------------------------

    private function parisAndBeirutFlightsOn15November(): void
    {
        Flight::factory()->create(['flight_no' => 'ME202', 'origin' => 'CDG', 'destination' => 'BEY', 'departure_at' => '2026-11-15 10:00']);
        Flight::factory()->create(['flight_no' => 'ME216', 'origin' => 'IST', 'destination' => 'CDG', 'departure_at' => '2026-11-15 11:00']);
        Flight::factory()->create(['flight_no' => 'ME201', 'origin' => 'BEY', 'destination' => 'CDG', 'departure_at' => '2026-11-15 12:00']);
    }

    public function test_departing_from_scope(): void
    {
        $this->parisAndBeirutFlightsOn15November();

        $this->assertSame(['ME202'], Flight::departingFrom('CDG')->pluck('flight_no')->all());
        $this->assertSame(['ME201'], Flight::departingFrom('BEY')->pluck('flight_no')->all());
    }

    public function test_SR41_check_in_and_gate_staff_see_only_their_stations_flights(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 08:00', 'UTC'));
        $this->parisAndBeirutFlightsOn15November();

        $this->staff(Role::Checkin, 'CDG');
        $this->get(route('checkin.home'))->assertOk()->assertSee('ME202')->assertDontSee('ME201')->assertDontSee('ME216');

        $this->staff(Role::Gate, 'BEY');
        $this->get(route('gate.home'))->assertOk()->assertSee('ME201')->assertDontSee('ME202')->assertDontSee('ME216');

        $this->staff(Role::Gate, 'IST');
        $this->get(route('gate.home'))->assertOk()->assertSee('ME216')->assertDontSee('ME201')->assertDontSee('ME202');
    }

    public function test_SR41_reservation_defaults_to_its_station_and_the_manager_sees_every_station(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 08:00', 'UTC'));
        $this->parisAndBeirutFlightsOn15November();

        $this->staff(Role::Reservation, 'CDG');
        $this->blade('<x-todays-flights role="reservation" />')->assertSee('ME202')->assertDontSee('ME201');

        $this->staff(Role::Manager, 'CDG');
        $this->blade('<x-todays-flights role="manager" />')->assertSee('ME202')->assertSee('ME201')->assertSee('ME216');
    }

    public function test_SR41_today_means_today_at_the_staff_members_station(): void
    {
        // 23:30 UTC on the 14th: still the 14th in Paris (UTC+1)... until 23:00 UTC. It is 00:30 on the 15th.
        // In Beirut (UTC+2) it is 01:30 on the 15th. In New York (UTC-5) it is 18:30 on the 14th.
        $this->travelTo(Carbon::parse('2026-11-14 23:30', 'UTC'));
        Flight::factory()->create(['flight_no' => 'ME202', 'origin' => 'CDG', 'destination' => 'BEY', 'departure_at' => Carbon::parse('2026-11-15 07:00', 'Europe/Paris')]);
        Flight::factory()->create(['flight_no' => 'ME200', 'origin' => 'CDG', 'destination' => 'BEY', 'departure_at' => Carbon::parse('2026-11-14 21:00', 'Europe/Paris')]);
        Flight::factory()->create(['flight_no' => 'ME901', 'origin' => 'JFK', 'destination' => 'CDG', 'departure_at' => Carbon::parse('2026-11-14 21:00', 'America/New_York')]);

        $this->staff(Role::Checkin, 'CDG');
        $this->blade('<x-todays-flights role="checkin" />')->assertSee('ME202')->assertSee('07:00')->assertDontSee('ME200');

        $this->staff(Role::Gate, 'JFK');
        $this->blade('<x-todays-flights role="gate" />')->assertSee('ME901')->assertSee('21:00');
    }

    public function test_SR6_check_in_search_lists_only_flights_from_the_agents_station(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 08:00', 'UTC'));
        $paris = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME202', 'origin' => 'CDG', 'destination' => 'BEY']);
        $beirut = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME201', 'origin' => 'BEY', 'destination' => 'CDG']);
        $this->passengerOn($paris, 'PAR234');
        $this->passengerOn($beirut, 'BEY234');

        $this->staff(Role::Checkin, 'CDG');
        $this->get(route('checkin.home', ['q' => 'Morel']))->assertSee('PAR234')->assertDontSee('BEY234');
        $this->get(route('checkin.home', ['q' => 'BEY234']))->assertSee('Reservation not found');
    }

    public function test_SR6_check_in_actions_on_another_stations_flight_are_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 08:00', 'UTC'));
        $beirut = $this->flightWithStatus(FlightStatus::CheckinOpen, ['origin' => 'BEY', 'destination' => 'CDG']);
        $passenger = $this->passengerOn($beirut);
        $this->seatPassenger($passenger);
        $document = $passenger->travelDocuments()->first();

        $this->staff(Role::Checkin, 'CDG');
        $this->get(route('checkin.passengers.show', $passenger))->assertForbidden();
        $this->post(route('checkin.passengers.confirm', $passenger))->assertForbidden();
        $this->post(route('checkin.documents.verify', $document))->assertForbidden();
        $this->post(route('checkin.bags.store', $passenger), ['weight_kg' => 10, 'type' => 'Standard'])->assertForbidden();
        $this->get(route('checkin.reservations.group', $passenger->reservation))->assertForbidden();
        $this->assertSame('Booked', $passenger->fresh()->status->value);

        // The Beirut agent can.
        $this->staff(Role::Checkin, 'BEY');
        $this->post(route('checkin.passengers.confirm', $passenger))->assertSessionHas('success');
        $this->assertSame('CheckedIn', $passenger->fresh()->status->value);
    }

    public function test_SR10_gate_actions_on_another_stations_flight_are_refused(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 08:00', 'UTC'));
        $beirut = $this->flightWithStatus(FlightStatus::Boarding, ['origin' => 'BEY', 'destination' => 'CDG']);

        $this->staff(Role::Gate, 'CDG');
        $this->get(route('gate.flights.show', $beirut))->assertForbidden();
        $this->get(route('gate.flights.counts', $beirut))->assertForbidden();
        $this->post(route('gate.flights.close', $beirut))->assertForbidden();
        $this->assertSame(FlightStatus::Boarding, $beirut->fresh()->status);

        $this->staff(Role::Gate, 'BEY');
        $this->get(route('gate.flights.show', $beirut))->assertOk();
    }

    public function test_reservation_staff_can_book_a_flight_from_any_station(): void
    {
        $this->travelTo(Carbon::parse('2026-11-15 08:00', 'UTC'));
        Flight::factory()->create(['flight_no' => 'ME216', 'origin' => 'IST', 'destination' => 'CDG', 'departure_at' => '2026-11-16 09:00']);

        $this->staff(Role::Reservation, 'BEY');
        $this->get(route('reservation.create'))->assertOk()->assertSee('ME216')->assertSee('16 Nov 2026 12:00');
    }

    // --- 5. Seed data -----------------------------------------------------------------------

    public function test_seeded_paris_demo_has_return_flights_with_passengers(): void
    {
        $this->seed(DatabaseSeeder::class);

        $toBeirut = Flight::where('origin', 'CDG')->where('destination', 'BEY')->firstOrFail();
        $fromIstanbul = Flight::where('origin', 'IST')->where('destination', 'CDG')->firstOrFail();
        $this->assertGreaterThan(0, $toBeirut->passengers()->count());
        $this->assertGreaterThan(0, $fromIstanbul->passengers()->count());
        $this->assertSame(FlightStatus::CheckinOpen, $toBeirut->status);
        $this->assertTrue($toBeirut->isCheckinWindowOpen());

        // The Paris check-in agent sees their flight, and can open its passengers.
        $this->actingAs(User::where('role', Role::Checkin)->where('airport_code', 'CDG')->sole());
        $this->get(route('checkin.home'))->assertOk()->assertSee($toBeirut->flight_no)->assertDontSee('ME305');
        $this->get(route('checkin.passengers.show', $toBeirut->passengers()->first()))->assertOk();
    }
}
