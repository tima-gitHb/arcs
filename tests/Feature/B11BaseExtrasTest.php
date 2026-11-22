<?php

namespace Tests\Feature;

use App\Enums\BagHandling;
use App\Enums\BagType;
use App\Enums\FlightStatus;
use App\Enums\Role;
use App\Enums\SpecialServiceCode;
use App\Models\Bag;
use App\Models\Flight;
use App\Models\Passenger;
use App\Models\Reservation;
use App\Models\SpecialService;
use App\Models\TravelDocument;
use App\Models\User;
use App\Services\StatusService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class B11BaseExtrasTest extends TestCase
{
    use RefreshDatabase;

    private const RESET_MESSAGE = 'If that email exists in ARCS, a password reset link has been sent to it.';

    private function passengerOn(Flight $flight): Passenger
    {
        $passenger = Passenger::factory()->for(Reservation::factory()->for($flight))->create();
        TravelDocument::factory()->for($passenger)->create();
        $this->seatPassenger($passenger); // check-in needs a seat (C5)

        return $passenger;
    }

    // --- SR-40: last login ---------------------------------------------------------------

    public function test_SR40_login_records_last_login(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 08:30'));
        $user = User::factory()->create();
        $this->assertNull($user->last_login_at);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->last_login_at->equalTo(now()));
    }

    public function test_SR40_failed_login_does_not_record_last_login(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);

        $this->assertNull($user->fresh()->last_login_at);
    }

    // --- SR-37 / SR-35: password reset ---------------------------------------------------

    public function test_SR37_reset_link_sent_for_active_account(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', self::RESET_MESSAGE);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_SR37_no_reset_email_for_locked_or_deactivated_account(): void
    {
        Notification::fake();
        $locked = User::factory()->create();
        $locked->forceFill(['locked' => true, 'failed_logins' => 5])->save();
        $deactivated = User::factory()->create(['active' => false]);

        foreach ([$locked->email, $deactivated->email, 'nobody@arcs.test'] as $email) {
            $this->post('/forgot-password', ['email' => $email])
                ->assertSessionHas('status', self::RESET_MESSAGE)
                ->assertSessionHasNoErrors();
        }

        Notification::assertNothingSent();
    }

    public function test_SR35_password_reset_never_unlocks_an_account(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user); // e.g. a link requested before the lock
        $user->forceFill(['locked' => true, 'failed_logins' => 5])->save();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewPassw0rd',
            'password_confirmation' => 'NewPassw0rd',
        ]);

        $this->assertTrue($user->fresh()->locked);

        $this->post('/login', ['email' => $user->email, 'password' => 'NewPassw0rd'])
            ->assertSessionHasErrors(['email' => 'Account locked. Ask the Manager to unlock it.']);
        $this->assertGuest();
    }

    // --- SR-38: password rules -----------------------------------------------------------

    public function test_SR38_weak_password_rejected_on_reset(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        foreach (['abc123', 'onlyletters', '12345678'] as $weak) {
            $this->post('/reset-password', [
                'token' => $token,
                'email' => $user->email,
                'password' => $weak,
                'password_confirmation' => $weak,
            ])->assertSessionHasErrors('password');
        }
        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'Passw0rd99',
            'password_confirmation' => 'Passw0rd99',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Passw0rd99', $user->fresh()->password));
    }

    public function test_SR38_weak_password_rejected_on_profile(): void
    {
        $user = $this->actingAsRole('gate');

        $this->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => 'lettersonly',
            'password_confirmation' => 'lettersonly',
        ])->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    // --- SR-39: idle timeout -------------------------------------------------------------

    public function test_SR39_expired_session_redirects_to_login_with_message(): void
    {
        $this->assertStringContainsString('SESSION_LIFETIME=90', file_get_contents(base_path('.env.example')));

        // Logging in leaves a marker cookie that outlives the session.
        $user = User::factory()->create(['role' => Role::Checkin]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertCookie('arcs_had_session');

        // Later the session has expired (no session), but the marker cookie is still there.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->withCookie('arcs_had_session', '1')
            ->get('/checkin')
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Session expired, please log in again.')
            ->assertCookieExpired('arcs_had_session');

        $this->withSession(['status' => 'Session expired, please log in again.'])
            ->get('/login')
            ->assertSee('Session expired, please log in again.');
    }

    public function test_SR39_guest_without_previous_session_gets_no_message(): void
    {
        $this->get('/checkin')
            ->assertRedirect(route('login'))
            ->assertSessionMissing('status');
    }

    public function test_SR39_logout_clears_the_marker(): void
    {
        $this->actingAsRole('gate');

        $this->post('/logout')->assertCookieExpired('arcs_had_session');
    }

    // --- SR-43: bag types ----------------------------------------------------------------

    public function test_SR43_wheelchair_and_stroller_do_not_count_toward_allowance(): void
    {
        foreach (BagType::cases() as $type) {
            $expected = ! in_array($type, [BagType::Wheelchair, BagType::Stroller], true);
            $this->assertSame($expected, $type->countsTowardAllowance(), $type->value);
            $this->assertNotSame('', $type->label());
        }

        $bag = Bag::factory()->create();
        $this->assertSame(BagType::Standard, $bag->fresh()->type);
        $this->assertSame(BagHandling::Hold, $bag->fresh()->handling);

        $stroller = Bag::factory()->create(['type' => BagType::Stroller, 'handling' => BagHandling::GateDelivery]);
        $this->assertSame(BagType::Stroller, $stroller->fresh()->type);
        $this->assertSame(BagHandling::GateDelivery, $stroller->fresh()->handling);
        $this->assertFalse($stroller->fresh()->type->countsTowardAllowance());
    }

    // --- SR-44: special services ---------------------------------------------------------

    public function test_SR44_codes_requiring_details(): void
    {
        $needDetails = ['UMNR', 'PETC', 'AVIH', 'DPNA', 'MEDA'];

        $this->assertCount(15, SpecialServiceCode::cases());
        foreach (SpecialServiceCode::cases() as $code) {
            $this->assertSame(in_array($code->value, $needDetails, true), $code->requiresDetails(), $code->value);
            $this->assertNotSame($code->value, $code->label());
        }

        $service = SpecialService::create([
            'passenger_id' => Passenger::factory()->create()->id,
            'type' => SpecialServiceCode::PETC,
            'details' => 'Cat, 6 kg with carrier',
        ]);
        $this->assertSame(SpecialServiceCode::PETC, $service->fresh()->type);
        $this->assertSame('Cat, 6 kg with carrier', $service->fresh()->details);
    }

    // --- SR-45: who did it ---------------------------------------------------------------

    public function test_SR45_checked_in_by_returns_the_agent_and_time(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));
        $status = app(StatusService::class);
        $agent = User::factory()->create(['role' => Role::Checkin]);
        $passenger = $this->passengerOn($this->flightWithStatus(FlightStatus::CheckinOpen));

        $this->assertNull($passenger->checkedInBy);

        $status->checkIn($passenger, $agent);

        $entry = $passenger->fresh()->checkedInBy;
        $this->assertTrue($entry->user->is($agent));
        $this->assertTrue($entry->changed_at->equalTo(now()));
        $this->assertNull($passenger->fresh()->boardedBy);
    }

    public function test_SR45_boarded_cancelled_and_no_show_by(): void
    {
        $status = app(StatusService::class);
        $reservationAgent = User::factory()->create(['role' => Role::Reservation]);
        $checkinAgent = User::factory()->create(['role' => Role::Checkin]);
        $gateAgent = User::factory()->create(['role' => Role::Gate]);

        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);
        $boarded = $this->passengerOn($flight);
        $noShow = $this->passengerOn($flight);
        $cancelled = $this->passengerOn($flight);

        $status->checkIn($boarded, $checkinAgent);
        $status->checkIn($noShow, $checkinAgent);
        $status->cancel($cancelled, $reservationAgent);
        $status->setFlightStatus($flight, FlightStatus::Boarding, $gateAgent);
        $status->board($boarded, $gateAgent);
        $status->closeBoarding($flight, $gateAgent);

        $this->assertTrue($boarded->fresh()->boardedBy->user->is($gateAgent));
        $this->assertTrue($cancelled->fresh()->cancelledBy->user->is($reservationAgent));
        $this->assertTrue($noShow->fresh()->markedNoShowBy->user->is($gateAgent));
        $this->assertTrue($noShow->fresh()->checkedInBy->user->is($checkinAgent));
        $this->assertNull($boarded->fresh()->markedNoShowBy);
    }

    public function test_SR45_who_did_it_helpers_are_eager_loadable(): void
    {
        $status = app(StatusService::class);
        $agent = User::factory()->create(['role' => Role::Checkin]);
        $flight = $this->flightWithStatus(FlightStatus::CheckinOpen);

        for ($i = 0; $i < 10; $i++) {
            $status->checkIn($this->passengerOn($flight), $agent);
        }

        DB::enableQueryLog();
        $passengers = Passenger::with(['checkedInBy.user', 'boardedBy.user'])->get();
        foreach ($passengers as $passenger) {
            $passenger->checkedInBy?->user?->name;
            $passenger->boardedBy?->user?->name;
        }
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // passengers + 2 audit queries + 2 user queries — not one per passenger.
        $this->assertLessThanOrEqual(5, $queries);
        $this->assertSame(10, $passengers->filter(fn ($p) => $p->checkedInBy?->user?->is($agent))->count());
    }

    // --- SR-41: today's flights ----------------------------------------------------------

    public function test_SR41_todays_flights_lists_only_today_sorted_by_departure(): void
    {
        $this->travelTo(Carbon::parse('2026-11-10 10:00'));

        Flight::factory()->create(['flight_no' => $this->flightNo('900'), 'departure_at' => '2026-11-09 18:00']);
        Flight::factory()->create(['flight_no' => $this->flightNo('815'), 'destination' => 'FCO', 'gate' => 'B4', 'departure_at' => '2026-11-10 15:00']);
        Flight::factory()->create(['flight_no' => $this->flightNo('801'), 'destination' => 'CDG', 'gate' => 'A2', 'departure_at' => '2026-11-10 08:00']);
        Flight::factory()->create(['flight_no' => $this->flightNo('950'), 'departure_at' => '2026-11-11 09:00']);

        $view = $this->blade('<x-todays-flights role="gate" />');

        $view->assertSeeInOrder([$this->flightNo('801'), 'BEY → CDG', '08:00', 'A2', 'Scheduled', $this->flightNo('815'), 'BEY → FCO', '15:00', 'B4']);
        $view->assertDontSee($this->flightNo('900'));
        $view->assertDontSee($this->flightNo('950'));
    }

    public function test_SR41_todays_flights_shows_a_message_when_there_are_none(): void
    {
        $this->blade('<x-todays-flights :role="$role" />', ['role' => Role::Manager])
            ->assertSee('No flights today.');
    }
}
