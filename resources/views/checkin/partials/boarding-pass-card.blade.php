{{-- Boarding pass card on the check-in screen (C5). Shown once a pass has been issued. --}}
@php($pass = $passenger->activeBoardingPass)

@if ($pass)
    <div class="rounded-lg bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-navy">Boarding pass</h2>
        <p class="mt-2 text-sm text-gray-700">
            Issued <x-station-time :at="$pass->issued_at" format="d M H:i" /> · <span class="font-mono">{{ $pass->barcode }}</span>
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <a href="{{ route('checkin.boarding-pass.show', $passenger) }}" target="_blank"
               class="rounded bg-navy px-4 py-2 text-sm font-medium text-white hover:bg-navy/90">
                Print boarding pass
            </a>

            @if ($passenger->status === \App\Enums\PassengerStatus::CheckedIn)
                <form method="POST" action="{{ route('checkin.boarding-pass.reprint', $passenger) }}">
                    @csrf
                    <button type="submit" class="rounded border border-navy px-4 py-2 text-sm font-medium text-navy hover:bg-navy hover:text-white">
                        Reprint (voids this pass)
                    </button>
                </form>
            @endif
        </div>
    </div>
@endif
