{{-- Entry requirements card (SR-49). Advisory and read only. Expects $passenger, $entryResult, $showEntryCheck. --}}
<div class="rounded-lg bg-white p-6 shadow-sm">
    <h2 class="text-lg font-semibold text-navy">Entry requirements</h2>

    @if ($showEntryCheck)
        <div @class([
            'mt-3 rounded border px-4 py-3 text-sm font-medium',
            'border-green-200 bg-green-50 text-green-800' => $entryResult->status === \App\Enums\EntryStatus::Ok,
            'border-red-200 bg-red-50 text-red-800' => $entryResult->needsAcknowledgement(),
            'border-amber-200 bg-amber-50 text-amber-800' => $entryResult->status === \App\Enums\EntryStatus::Unknown,
        ])>
            {{ $entryResult->message }}
            @if ($entryResult->note)
                <span class="mt-1 block text-xs font-normal">{{ $entryResult->note }}</span>
            @endif
        </div>
        <p class="mt-2 text-xs text-gray-500">
            Rules as of {{ \App\Services\EntryRequirementChecker::RULES_AS_OF }}. Advisory: the agent decides.
        </p>
    @else
        <a href="{{ route('checkin.passengers.show', [$passenger, 'check_entry' => 1]) }}"
           class="mt-3 inline-block rounded border border-navy px-4 py-2 text-sm font-medium text-navy hover:bg-navy hover:text-white">
            Check requirements
        </a>
    @endif
</div>
