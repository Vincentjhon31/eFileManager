@props(['label'])

{{-- Submit and cancel for the drive's action panels, so five forms do not each
     carry their own copy of the same two buttons. --}}
<div class="flex gap-3">
    <button type="submit"
            class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
        {{ $label }}
    </button>
    <button type="button" wire:click="closePanel"
            class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
        Cancel
    </button>
</div>
