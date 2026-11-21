{{-- Entry requirements (SR-49). Advisory. Expects $passenger, $entryResult, $showEntryCheck. --}}
@php
    $entryTone = match (true) {
        $entryResult->status === \App\Enums\EntryStatus::Ok => 'background: var(--arcs-success-bg); color: var(--arcs-success); border-color: var(--arcs-success);',
        $entryResult->needsAcknowledgement() => 'background: color-mix(in srgb, var(--arcs-error) 10%, white); color: var(--arcs-error); border-color: var(--arcs-error);',
        $entryResult->status === \App\Enums\EntryStatus::Unknown => 'background: color-mix(in srgb, var(--arcs-booked) 45%, white); color: var(--arcs-accent-text); border-color: var(--arcs-booked);',
        default => 'background: var(--arcs-page); color: var(--arcs-bar); border-color: var(--arcs-border);',
    };
@endphp

<div class="mt-4 rounded-[14px] border px-4 py-3 text-sm font-medium" style="{{ $entryTone }}">
    {{ $entryResult->message }}
    @if ($showEntryCheck && $entryResult->note)
        <span class="mt-1 block text-xs font-normal">{{ $entryResult->note }}</span>
    @endif
</div>

@if ($showEntryCheck)
    <p class="mt-2 text-xs text-gray-500">
        Rules as of {{ \App\Services\EntryRequirementChecker::RULES_AS_OF }}. Advisory: the agent decides.
    </p>
@else
    <a href="{{ route('checkin.passengers.show', [$passenger, 'check_entry' => 1]) }}" class="arcs-btn-secondary mt-3">
        Check requirements
    </a>
@endif
