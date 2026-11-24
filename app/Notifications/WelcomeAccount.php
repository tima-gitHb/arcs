<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcome email for a new staff account (SR-36, M20).
 * The Manager gives them a temporary password in person; it is never in this email.
 */
class WelcomeAccount extends Notification
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
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
            ->line('Your manager has a temporary password for you. Log in with that temporary password, then you will choose your own.')
            ->line('If you do not have the temporary password, ask your manager.')
            ->salutation('The ARCS team');
    }
}
