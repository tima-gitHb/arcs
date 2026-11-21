<?php

namespace Tests\Feature;

use App\Enums\FlightStatus;
use App\Enums\PaymentKind;
use App\Enums\Role;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\AuditEntry;
use App\Models\Flight;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Payments\CardDetails;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UI4ManagerShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 10:00:00');
        $this->actingAsRole(Role::Manager);
    }

    public function test_dashboard_kpis_show_money_and_period_label_changes(): void
    {
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME901']);
        $reservation = Reservation::factory()->for($flight)->create();
        $agent = User::factory()->create(['role' => Role::Checkin]);
        app(PaymentService::class)->charge(
            new CardDetails('4242 4242 4242 4242', '12/30', '123'),
            12500,
            PaymentKind::Fare,
            $agent,
            $reservation,
        );

        $today = $this->get(route('manager.dashboard'))->assertOk();
        $today->assertDontSee('See sales');
        $today->assertSee(Money::format(12500));
        $today->assertSee('Today');

        $week = $this->get(route('manager.dashboard', ['period' => '7d']))->assertOk();
        $week->assertSee('Last 7 days');
        $week->assertDontSee('See sales');
        $week->assertSee('data-kpi-delta="ticket"', false);
        $week->assertSee('No earlier data');
    }

    public function test_dashboard_kpi_delta_compares_to_previous_period(): void
    {
        $yesterday = $this->flightWithStatus(FlightStatus::CheckinOpen, [
            'flight_no' => 'ME800',
            'departure_at' => now()->subDay()->setTime(12, 0),
        ]);
        $yRes = Reservation::factory()->for($yesterday)->create();
        $agent = User::factory()->create(['role' => Role::Checkin]);
        app(PaymentService::class)->charge(
            new CardDetails('4242 4242 4242 4242', '12/30', '123'),
            20000,
            PaymentKind::Fare,
            $agent,
            $yRes,
        );

        $todayFlight = $this->flightWithStatus(FlightStatus::CheckinOpen, ['flight_no' => 'ME801']);
        $tRes = Reservation::factory()->for($todayFlight)->create();
        app(PaymentService::class)->charge(
            new CardDetails('4242 4242 4242 4242', '12/30', '123'),
            5000,
            PaymentKind::Fare,
            $agent,
            $tRes,
        );

        $page = $this->get(route('manager.dashboard', ['period' => 'today']))->assertOk();
        $page->assertSee('data-kpi-delta="ticket"', false);
        $page->assertSee(Money::format(5000));
        $page->assertDontSee('No earlier data');
    }

    public function test_flights_list_has_no_create_form_and_new_page_creates(): void
    {
        $this->get(route('manager.flights'))
            ->assertOk()
            ->assertDontSee('name="flight_no"', false)
            ->assertDontSee('Create flight');

        $create = $this->get(route('manager.flights.create'))->assertOk();
        $create->assertSee('name="flight_no"', false);
        $create->assertSee('Create flight');

        $aircraft = Aircraft::factory()->create();
        $response = $this->post(route('manager.flights.store'), [
            'flight_no' => 'ME205',
            'origin' => 'BEY',
            'destination' => 'CDG',
            'aircraft_id' => $aircraft->id,
            'departure_at' => '2026-11-11T14:00',
            'checkin_opens_at' => '2026-11-11T11:00',
            'checkin_closes_at' => '2026-11-11T13:15',
            'gate' => 'B4',
        ])->assertSessionHasNoErrors();

        $flight = Flight::where('flight_no', 'ME205')->sole();
        $response->assertRedirect(route('manager.flights.show', $flight));
    }

    public function test_airports_list_has_no_inline_inputs_and_edit_saves(): void
    {
        $this->get(route('manager.airports'))
            ->assertOk()
            ->assertDontSee('name="min_connection_minutes"', false)
            ->assertSee('Edit →');

        $this->get(route('manager.airports.edit', 'CDG'))
            ->assertOk()
            ->assertSee('name="min_connection_minutes"', false);

        $this->put(route('manager.airports.update', 'CDG'), [
            'name' => 'Paris Charles de Gaulle',
            'city' => 'Paris',
            'country' => 'FR',
            'timezone' => 'Europe/Paris',
            'min_connection_minutes' => 75,
        ])->assertRedirect(route('manager.airports'))->assertSessionHasNoErrors();

        $this->assertSame(75, Airport::find('CDG')->min_connection_minutes);
    }

    public function test_staff_detail_shows_reset_resend_and_deactivate(): void
    {
        $user = User::factory()->create(['role' => Role::Gate, 'name' => 'Gate Agent']);

        $this->get(route('manager.accounts.show', $user))
            ->assertOk()
            ->assertSee('Send reset link')
            ->assertSee('Resend welcome email')
            ->assertSee('Deactivate');
    }

    public function test_audit_entry_query_shows_before_after_panel(): void
    {
        $entry = AuditEntry::create([
            'field' => 'gate',
            'old_value' => 'A1',
            'new_value' => 'B2',
            'user_id' => User::factory()->create()->id,
            'changed_at' => now(),
        ]);

        $this->get(route('manager.audit', ['entry' => $entry->id]))
            ->assertOk()
            ->assertSee('data-audit-before', false)
            ->assertSee('data-audit-after', false)
            ->assertSee('A1')
            ->assertSee('B2');
    }

    public function test_sidebar_shows_sub_items_only_under_active_section(): void
    {
        $flights = $this->get(route('manager.flights'))->assertOk();
        $flights->assertSee('data-manager-subnav="fleet"', false);
        $flights->assertDontSee('data-manager-subnav="staff"', false);
        $flights->assertDontSee('data-manager-subnav="fares"', false);

        $staff = $this->get(route('manager.accounts'))->assertOk();
        $staff->assertSee('data-manager-subnav="staff"', false);
        $staff->assertDontSee('data-manager-subnav="fleet"', false);

        $dashboard = $this->get(route('manager.dashboard'))->assertOk();
        $dashboard->assertDontSee('data-manager-subnav="fleet"', false);
        $dashboard->assertDontSee('data-manager-subnav="staff"', false);
    }
}
