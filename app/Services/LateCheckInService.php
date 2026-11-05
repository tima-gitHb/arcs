<?php

namespace App\Services;

use App\Enums\Role;
use App\Exceptions\ManagerApprovalRefused;
use App\Models\Passenger;
use App\Models\Seat;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Late passenger override at the gate (G5, SR-29): checks the Manager's approval, then gives
 * the seat, checks the passenger in and issues the pass. Status changes only in StatusService.
 */
class LateCheckInService
{
    public function __construct(private SeatService $seats, private BoardingPassService $passes) {}

    /**
     * One transaction: if the check-in is refused, the chosen seat is not kept either.
     * $seat is used only if the passenger has no seat yet.
     */
    public function complete(
        Passenger $passenger,
        User $gateUser,
        string $managerEmail,
        #[\SensitiveParameter] string $managerPassword,
        string $reason,
        ?Seat $seat,
    ): void {
        $manager = $this->approvingManager($managerEmail, $managerPassword);

        DB::transaction(function () use ($passenger, $gateUser, $manager, $reason, $seat) {
            if ($seat && $passenger->seatAssignment()->doesntExist()) {
                $this->seats->assign($passenger, $seat, $gateUser);
            }

            $this->passes->completeLateCheckIn($passenger, $gateUser, $manager, $reason);
        });
    }

    /**
     * Auth::validate() only checks the credentials: the Manager is not logged in and the
     * gate agent stays logged in. The password is checked first, so the other messages
     * cannot be used to find out about an account without knowing its password.
     */
    private function approvingManager(string $email, #[\SensitiveParameter] string $password): User
    {
        if (! Auth::validate(['email' => $email, 'password' => $password])) {
            throw new ManagerApprovalRefused('Manager email or password is incorrect.');
        }

        $manager = User::query()->where('email', $email)->firstOrFail();

        if ($manager->role !== Role::Manager) {
            throw new ManagerApprovalRefused('That account is not a Manager.');
        }
        if ($manager->locked) {
            throw new ManagerApprovalRefused('That Manager account is locked.');
        }
        if (! $manager->active) {
            throw new ManagerApprovalRefused('That Manager account is deactivated.');
        }

        return $manager;
    }
}
