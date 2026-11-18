<?php

namespace App\Mail;

use App\Models\Flight;
use App\Models\Reservation;
use App\Support\LocalTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Gate change or delay, one per reservation contact (M16).
 * Queued, and only after the surrounding database transaction commits.
 */
class FlightChangeMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $kind  'gate' or 'delay'
     */
    public function __construct(
        public Reservation $reservation,
        public Flight $flight,
        public string $kind,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        $this->flight->loadMissing('destinationAirport');

        return new Content(
            markdown: 'emails.flight-change',
            with: [
                'flight' => $this->flight,
                'kind' => $this->kind,
                'city' => $this->flight->destinationAirport->city,
            ],
        );
    }

    /**
     * "{flight_no} to {destination city}: new gate B7" or "delayed to 23:30".
     * 23:30 is the origin airport's clock, not UTC.
     */
    private function subjectLine(): string
    {
        $this->flight->loadMissing('destinationAirport');

        $about = $this->flight->flight_no.' to '.$this->flight->destinationAirport->city;

        if ($this->kind === 'gate') {
            return $about.': new gate '.$this->flight->gate;
        }

        $time = LocalTime::at($this->flight->departure_at, $this->flight->origin)->format('H:i');

        return $about.': delayed to '.$time;
    }
}
