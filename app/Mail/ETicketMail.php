<?php

namespace App\Mail;

use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-ticket / receipt emailed to the reservation contact after a paid booking.
 */
class ETicketMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Reservation $reservation,
        public Payment $payment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ARCS e-ticket '.$this->reservation->pnr,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.eticket',
            with: [
                'reservation' => $this->reservation,
                'payment' => $this->payment,
            ],
        );
    }
}
