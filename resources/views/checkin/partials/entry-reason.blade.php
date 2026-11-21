{{--
    Reason field when the entry check found a problem (SR-49). Shared by Confirm check-in and
    the gate's late check-in. Expects $entryResult. Shows nothing for an ok or unknown result.
--}}
@if ($entryResult->needsAcknowledgement())
    <div class="mb-3 max-w-xl rounded-[14px] border p-4" style="border-color: var(--arcs-error); background: color-mix(in srgb, var(--arcs-error) 10%, white); color: var(--arcs-error);">
        <p class="text-sm font-medium">{{ $entryResult->message }}</p>
        <x-input-label for="entry_reason" value="Reason for continuing (required)" class="mt-2" />
        <x-text-input id="entry_reason" name="entry_reason" type="text" maxlength="255" class="mt-1 block w-full"
            :value="old('entry_reason')" placeholder="e.g. visa checked on paper" required autocomplete="off" />
        <x-input-error :messages="$errors->get('entry_reason')" class="mt-2" />
    </div>
@endif
