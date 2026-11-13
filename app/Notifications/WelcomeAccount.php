<?php

namespace App\Notifications;

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

        return (new MailMessage)
            ->subject('Welcome to ARCS')
            ->greeting("Hello {$notifiable->name},")
            ->line("An ARCS account has been created for you as {$notifiable->role->label()}.")
            ->line('Click the button below to choose your password.')
            ->action('Set your password', $url)
            ->line("This link expires in {$minutes} minutes. If it has expired, ask the Manager to send you a new link.")
            ->salutation('The ARCS team');
    }
}
