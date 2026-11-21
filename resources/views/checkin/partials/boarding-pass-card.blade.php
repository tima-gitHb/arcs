{{-- Boarding passes on the check-in screen (C5 / C14): one active pass per flight of the direction. --}}
@php
    $directionPasses = $boardingPasses ?? collect([$passenger->activeBoardingPass])->filter();
@endphp

@if ($directionPasses->isNotEmpty())
    <div>
        <h2 class="mb-3 text-base font-semibold text-gray-900">Boarding pass{{ $directionPasses->count() > 1 ? 'es' : '' }}</h2>

        <ul class="grid gap-4 lg:grid-cols-2">
            @foreach ($directionPasses as $index => $pass)
                @php($passFlight = $pass->coupon?->flight ?? $flight ?? $passenger->reservation->flight)
                <li class="overflow-hidden rounded-[22px] bg-white shadow-sm" style="box-shadow: var(--arcs-shadow-card);">
                    <div class="flex items-center justify-between px-4 py-3 text-white" style="background: var(--arcs-bar);">
                        <p class="text-xs font-semibold uppercase tracking-wide text-white/70">
                            Boarding pass {{ $index + 1 }} of {{ $directionPasses->count() }}
                        </p>
                        <p class="arcs-mono text-sm font-semibold">{{ $passFlight->flight_no }}</p>
                    </div>
                    <div class="p-4">
                        <p class="text-2xl font-bold tracking-tight text-gray-900">
                            {{ $passFlight->origin }} <span class="text-gray-300">→</span> {{ $passFlight->destination }}
                        </p>
                        <p class="mt-1 text-sm font-medium" style="color: var(--arcs-accent-text);">
                            {{ $passFlight->flight_no }} · {{ $passFlight->origin }} → {{ $passFlight->destination }}
                        </p>
                        <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <dt class="text-xs text-gray-500">Seat</dt>
                                <dd class="font-semibold arcs-mono">{{ $pass->coupon?->seatAssignment?->seat?->seat_no ?? $passenger->seatAssignment?->seat?->seat_no ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">Gate</dt>
                                <dd class="font-semibold">{{ $passFlight->gate ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">Departs</dt>
                                <dd class="font-semibold"><x-local-time :at="$passFlight->departure_at" :airport="$passFlight->origin" format="H:i" /></dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500">Issued</dt>
                                <dd class="font-semibold"><x-station-time :at="$pass->issued_at" format="d M H:i" /></dd>
                            </div>
                        </dl>
                        <p class="mt-3 arcs-mono text-xs text-gray-500">{{ $pass->barcode }}</p>

                        <div class="mt-4 flex flex-wrap items-center gap-3">
                            <a href="{{ route('checkin.boarding-pass.show', [$passenger, 'flight' => $passFlight->id]) }}" target="_blank"
                               class="arcs-btn-primary">
                                Print
                            </a>

                            @if (($pass->coupon?->status ?? $passenger->status) === \App\Enums\PassengerStatus::CheckedIn)
                                <form method="POST" action="{{ route('checkin.boarding-pass.reprint', $passenger) }}">
                                    @csrf
                                    <input type="hidden" name="flight_id" value="{{ $passFlight->id }}">
                                    <button type="submit" class="arcs-btn-secondary">
                                        Reprint (voids this pass)
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif
