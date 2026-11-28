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
 * Refund confirmation emailed to the reservation contact (Sprint 4 Feature 2).
 */
class RefundConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $preview
     */
    public function __construct(
        public Reservation $reservation,
        public ?Payment $refund,
        public Payment $original,
        public array $preview,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: config('arcs.airline.name').' refund '.$this->reservation->pnr,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.refund',
            with: [
                'reservation' => $this->reservation,
                'refund' => $this->refund,
                'original' => $this->original,
                'preview' => $this->preview,
            ],
        );
    }
}
