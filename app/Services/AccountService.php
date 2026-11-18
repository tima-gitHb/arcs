<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\WelcomeAccount;
use DomainException;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * What the Manager does to staff accounts (SR-22, SR-35, SR-36).
 * A refused action throws a DomainException whose message can be shown to the Manager.
 */
class AccountService
{
    /**
     * Create an account and send the welcome email with a set-password link (SR-36).
     * airport_code is the station (B13a): an airports.code, or null for none yet.
     *
     * @param  array{name: string, email: string, role: string, airport_code?: string|null}  $data
     */
    public function create(array $data): User
    {
        // users.password cannot be null, so the account gets a random password nobody knows.
        // The 'hashed' cast on User hashes it; the person sets their own through the link.
        $user = User::create($data + ['password' => Str::random(40)]);

        $token = Password::broker()->createToken($user);
        $user->notify(new WelcomeAccount($token));

        return $user;
    }

    /**
     * Activate or deactivate an account (SR-22). A manager cannot deactivate themselves.
     */
    public function setActive(User $user, bool $active, User $by): void
    {
        if (! $active && $user->is($by)) {
            throw new DomainException('You cannot deactivate your own account.');
        }

        $user->update(['active' => $active]);
    }

    /**
     * Clear the lock and the failed-login counter (SR-35). Only the Manager can do this;
     * a password reset never unlocks. 'locked' and 'failed_logins' are not fillable, so forceFill.
     */
    public function unlock(User $user): void
    {
        $user->forceFill(['locked' => false, 'failed_logins' => 0])->save();
    }

    /**
     * Send a password reset link, with the same rule as the public form (SR-37):
     * no link for a locked or deactivated account.
     */
    public function sendResetLink(User $user): void
    {
        if ($user->locked || ! $user->active) {
            throw new DomainException('Unlock or activate the account first.');
        }

        $status = Password::sendResetLink(['email' => $user->email]);

        // e.g. "Please wait before retrying." when a link was sent less than 60 seconds ago.
        if ($status !== Password::RESET_LINK_SENT) {
            throw new DomainException(trans($status));
        }
    }

    /**
     * Send the welcome email again, with a new set-password link (M18).
     * Locked or deactivated accounts are refused first (SR-37). An account that has
     * already logged in uses "Send reset link" instead. The broker applies the 60-second
     * throttle and replaces the previous token, so the old link stops working.
     */
    public function resendWelcome(User $user): void
    {
        if ($user->locked || ! $user->active) {
            throw new DomainException('Unlock or activate the account first.');
        }

        if ($user->last_login_at !== null) {
            throw new DomainException('This account has already logged in. Use Send reset link instead.');
        }

        $status = Password::sendResetLink(['email' => $user->email], function (User $user, string $token) {
            $user->notify(new WelcomeAccount($token));
        });

        if ($status !== Password::RESET_LINK_SENT) {
            throw new DomainException(trans($status));
        }
    }
}
