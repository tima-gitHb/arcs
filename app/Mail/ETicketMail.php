<?php

namespace App\Mail;

use App\Models\Payment;
use App\Models\Reservation;
use App\Support\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-ticket / receipt emailed to the reservation contact after a paid booking.
 * Uses the ARCS mail layout; the printable e-ticket is attached as a PDF.
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
        $this->loadRelations();

        return new Content(
            markdown: 'emails.eticket',
            with: [
                'reservation' => $this->reservation,
                'payment' => $this->payment,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $this->loadRelations();

        $pnr = $this->reservation->pnr;
        $bytes = Pdf::raw('reservation.pdf.eticket', [
            'reservation' => $this->reservation,
            'payment' => $this->payment,
        ]);

        return [
            Attachment::fromData(fn () => $bytes, 'eticket-'.$pnr.'.pdf')
                ->withMime('application/pdf'),
        ];
    }

    private function loadRelations(): void
    {
        $this->reservation->loadMissing([
            'flight',
            'segments.flight',
            'passengers.seatAssignment.seat',
            'passengers.coupons.flight',
            'passengers.coupons.seatAssignment.seat',
        ]);
    }
}
