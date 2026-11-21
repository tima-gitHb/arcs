{{-- Boarding passes on the check-in screen (C5 / C14): one active pass per flight of the direction. --}}
@php
    $directionPasses = $boardingPasses ?? collect([$passenger->activeBoardingPass])->filter();
@endphp

@if ($directionPasses->isNotEmpty())
    <x-panel>
        <h2 class="text-base font-semibold text-gray-900">Boarding pass{{ $directionPasses->count() > 1 ? 'es' : '' }}</h2>

        <ul class="mt-3 space-y-4">
            @foreach ($directionPasses as $pass)
                @php($passFlight = $pass->coupon?->flight ?? $flight ?? $passenger->reservation->flight)
                <li class="rounded-[14px] border p-4" style="border-color: var(--arcs-border);">
                    <p class="text-sm font-medium" style="color: var(--arcs-accent-text);">
                        {{ $passFlight->flight_no }} · {{ $passFlight->origin }} → {{ $passFlight->destination }}
                    </p>
                    <p class="mt-1 text-sm text-gray-700">
                        Issued <x-station-time :at="$pass->issued_at" format="d M H:i" />
                        · <span class="arcs-mono">{{ $pass->barcode }}</span>
                    </p>

                    <div class="mt-3 flex flex-wrap items-center gap-3">
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
                </li>
            @endforeach
        </ul>
    </x-panel>
@endif
