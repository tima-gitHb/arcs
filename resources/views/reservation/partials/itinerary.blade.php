@php
    use App\Enums\SegmentDirection;
    use App\Support\ConnectionTime;

    $segments = $reservation->segments;
    $hasReturn = $segments->contains(fn ($segment) => $segment->direction === SegmentDirection::Return);
    $legNumbers = $segments->pluck('leg')->unique()->sort()->values();
    $isMultiCity = ! $hasReturn && $legNumbers->count() > 1;
    $showDirectionHeaders = $hasReturn || ($segments->count() > 1 && ! $isMultiCity);
@endphp

<div class="space-y-6">
    @if ($isMultiCity)
        @foreach ($legNumbers as $legNo)
            @php
                $legs = $segments->where('leg', $legNo)->values();
            @endphp
            <section>
                <h3 class="text-sm font-semibold uppercase tracking-wide text-navy">Leg {{ $legNo }}</h3>
                <ol class="mt-3 space-y-4">
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

                        @include('reservation.partials.itinerary-flight', ['flight' => $flight, 'reservation' => $reservation])
                    @endforeach
                </ol>
            </section>
        @endforeach
    @else
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

                        @include('reservation.partials.itinerary-flight', ['flight' => $flight, 'reservation' => $reservation])
                    @endforeach
                </ol>
            </section>
        @endforeach
    @endif
</div>
