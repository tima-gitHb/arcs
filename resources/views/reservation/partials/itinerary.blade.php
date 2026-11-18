@php
    use App\Enums\SegmentDirection;
    use App\Support\ConnectionTime;

    $segments = $reservation->segments;
    $hasReturn = $segments->contains(fn ($segment) => $segment->direction === SegmentDirection::Return);
    $showDirectionHeaders = $hasReturn || $segments->count() > 1;
@endphp

<div class="space-y-6">
    @foreach ([SegmentDirection::Outbound, SegmentDirection::Return] as $direction)
        @php
            $legs = $segments->where('direction', $direction)->values();
        @endphp
        @if ($legs->isEmpty())
            @continue
        @endif

        <section>
            @if ($showDirectionHeaders)
                <h3 class="text-sm font-semibold uppercase tracking-wide text-navy">{{ $direction->label() }}</h3>
            @endif

            <ol class="{{ $showDirectionHeaders ? 'mt-3' : '' }} space-y-4">
                @foreach ($legs as $index => $segment)
                    @php
                        $flight = $segment->flight;
                        $previous = $index > 0 ? $legs[$index - 1]->flight : null;
                        $connection = $previous ? ConnectionTime::label($previous, $flight) : null;
                    @endphp

                    @if ($connection)
                        <li class="rounded-md border border-dashed border-navy/20 bg-[#F7FAFD] px-3 py-2 text-sm text-navy">
                            Connection at {{ $previous->destination }}: {{ $connection }}
                        </li>
                    @endif

                    <li class="rounded-md border border-gray-100 bg-white px-4 py-3">
                        <p class="text-base font-semibold text-navy">
                            {{ $flight->flight_no }}
                            <span class="font-medium text-gray-700">· {{ $flight->origin }} → {{ $flight->destination }}</span>
                        </p>
                        <dl class="mt-2 grid gap-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-gray-500">Departure ({{ $flight->origin }} local)</dt>
                                <dd class="font-medium">
                                    <x-local-time :at="$flight->departure_at" :airport="$flight->origin" format="D d M Y · H:i" />
                                </dd>
                            </div>
                            <div>
                                <dt class="text-gray-500">Arrival ({{ $flight->destination }} local)</dt>
                                <dd class="font-medium">
                                    <x-local-time :at="$flight->arrives_at" :airport="$flight->destination" format="D d M Y · H:i" />
                                </dd>
                            </div>
                        </dl>
                        <div class="mt-3 text-sm">
                            <p class="text-gray-500">Seats</p>
                            <ul class="mt-1 space-y-0.5">
                                @foreach ($reservation->passengers as $passenger)
                                    @php
                                        $coupon = $passenger->coupons->firstWhere('flight_id', $flight->id);
                                        $seatNo = $coupon?->seatAssignment?->seat?->seat_no;
                                    @endphp
                                    <li class="font-medium text-gray-900">
                                        {{ $passenger->first_name }} {{ $passenger->last_name }}:
                                        @if ($passenger->type === App\Enums\PassengerType::Infant)
                                            No seat
                                        @elseif ($seatNo)
                                            {{ $seatNo }}
                                        @else
                                            Not assigned
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>
    @endforeach
</div>
