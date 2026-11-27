<?php

namespace App\Mail;

use App\Enums\SegmentDirection;
use App\Models\Bag;
use App\Models\BoardingPass;
use App\Models\Passenger;
use App\Services\BagTagService;
use App\Services\BoardingPassService;
use App\Services\JourneyService;
use App\Support\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Check-in complete email (C17 / SR-79): ARCS layout with boarding-pass and bag-tag PDFs.
 * Attachments and itinerary cover the checked-in leg only (C23).
 */
class CheckInCompleteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Passenger $passenger,
        public SegmentDirection $direction,
        public ?int $leg = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: config('arcs.airline.name').' check-in complete '.$this->passenger->reservation->pnr,
        );
    }

    public function content(): Content
    {
        $this->loadRelations();

        return new Content(
            markdown: 'emails.checkin-complete',
            with: [
                'passenger' => $this->passenger,
                'reservation' => $this->passenger->reservation,
                'direction' => $this->direction,
                'leg' => $this->leg,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $this->loadRelations();

        $passes = app(BoardingPassService::class)->passesFor($this->passenger, $this->direction, $this->leg);
        $bags = $this->bagsForLeg();

        $attachments = [];

        foreach ($passes as $pass) {
            $attachments[] = $this->boardingPassAttachment($pass);
        }

        $tags = app(BagTagService::class);
        foreach ($bags as $bag) {
            $attachments[] = $this->bagTagAttachment($bag, $tags);
        }

        return $attachments;
    }

    private function boardingPassAttachment(BoardingPass $pass): Attachment
    {
        $data = app(BoardingPassService::class)->printData($this->passenger, $pass);
        // Pdf::raw for the attachment bytes; Pdf::fromView is the download path (same Blade view).
        $bytes = Pdf::raw('checkin.pdf.boarding-pass', $data);
        $flightNo = $data['flight']->flight_no;

        return Attachment::fromData(fn () => $bytes, 'boarding-pass-'.$flightNo.'.pdf')
            ->withMime('application/pdf');
    }

    private function bagTagAttachment(Bag $bag, BagTagService $tags): Attachment
    {
        $bytes = $tags->pdfBytes($bag);

        return Attachment::fromData(fn () => $bytes, 'bag-tag-'.$bag->tag_no.'.pdf')
            ->withMime('application/pdf');
    }

    /**
     * @return Collection<int, Bag>
     */
    private function bagsForLeg()
    {
        $bags = $this->passenger->bags->where('direction', $this->direction);

        if ($this->leg !== null) {
            $destination = app(JourneyService::class)
                ->finalDestinationOfLeg($this->passenger->reservation, $this->leg);
            $bags = $bags->where('destination', $destination);
        }

        return $bags->values();
    }

    private function loadRelations(): void
    {
        $this->passenger->loadMissing([
            'reservation.segments.flight',
            'bags',
            'accompanyingAdult',
        ]);
    }
}
