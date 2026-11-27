<x-mail::message>
# Check-in complete

{{ $passenger->first_name }} {{ $passenger->last_name }} is checked in for PNR {{ $reservation->pnr }}.

@include('emails.partials.itinerary', ['reservation' => $reservation, 'onlyLeg' => $leg ?? null])

Boarding passes and bag tags (if any) are attached as PDFs.
</x-mail::message>
