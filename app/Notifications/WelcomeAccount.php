<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email for a new staff account, with the link to set a first password (SR-36).
 */
class WelcomeAccount extends Notification
{
    /**
     * @param  string  $token  a password-broker token, made by AccountService::create()
     */
    public function __construct(public string $token) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        // The same page as "forgot password": it checks the token and the email, then saves the new password.
        $url = route('password.reset', ['token' => $this->token, 'email' => $notifiable->email]);
        $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        // The station is the user's airport. loadMissing so a caller that did not
        // eager-load it still gets the city, and a missing station stays null.
        $notifiable->loadMissing('airport');
        $role = "Your role: {$notifiable->role->label()}";
        if ($notifiable->airport !== null) {
            $role .= " · {$notifiable->airport->city} ({$notifiable->airport->code})";
        }

        $message = (new MailMessage)
            ->subject('Welcome to ARCS')
            ->greeting("Hello {$notifiable->name},")
            ->line($role);

        // Check-in and gate staff work a station. A manager or reservation agent may have none.
        if ($notifiable->airport === null && in_array($notifiable->role, [Role::Checkin, Role::Gate], true)) {
            $message->line("Your station hasn't been set yet. Ask your manager before your first shift.");
        }

        return $message
            ->line('Click the button below to choose your password.')
            ->action('Set your password', $url)
            ->line("This link expires in {$minutes} minutes. If it has expired, ask the Manager to send you a new link.")
            ->salutation('The ARCS team');
    }
}
