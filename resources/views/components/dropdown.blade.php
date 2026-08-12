@props(['align' => 'right', 'label' => 'More actions'])

{{--
    The local state is deliberately not called "open": several menus in this
    app put a wire:click="open(...)" action inside the slot (Drive's panels),
    and Alpine resolves a bare identifier from its own scope before it ever
    reaches Livewire's method — a variable named the same as that method
    would silently shadow it and break every one of those buttons.

    $label names the thing being acted on ("More actions for Ordinance 41.pdf").
    A listing of twenty files otherwise announces twenty identical buttons,
    which tells somebody using a screen reader nothing about which is which.
--}}
{{--
    No .stop on the escape handler: this dropdown can be the thing that OPENED
    a modal (e.g. its "Move" item), and while Alpine's leave-transition is
    still running the menu is still in the DOM and still in the bubble path.
    Swallowing Escape here would occasionally eat the keypress a parent modal
    needed to close itself, depending on exactly where focus happened to be
    when the transition started. Letting it bubble costs nothing — this menu
    is already closed by then.
--}}
<div x-data="{ menuOpen: false }" @click.outside="menuOpen = false" @keydown.escape="menuOpen = false"
     class="relative inline-block text-left">
    @if (isset($trigger))
        <div @click="menuOpen = ! menuOpen">{{ $trigger }}</div>
    @else
        <button type="button" @click="menuOpen = ! menuOpen"
                :aria-expanded="menuOpen" aria-haspopup="true" aria-label="{{ $label }}"
                class="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="size-5">
                <circle cx="12" cy="5" r="1.6" />
                <circle cx="12" cy="12" r="1.6" />
                <circle cx="12" cy="19" r="1.6" />
            </svg>
        </button>
    @endif

    {{--
        style="display: none" rather than x-cloak.

        Both hide the menu before Alpine boots, but only one survives a
        re-render. Livewire's morph copies attributes from the server HTML onto
        any element it finds *hidden*, so a closed menu gets x-cloak put back on
        it after every update — and the stylesheet hides x-cloak with
        !important, which beats the inline style x-show sets when the menu is
        later opened. The menu then cannot be opened again until a full reload.

        An inline display:none loses that fight cleanly: x-show writes to the
        same property, so opening the menu simply overwrites it.
    --}}
    <div x-show="menuOpen" x-transition style="display: none" @click="menuOpen = false"
         @class([
             'absolute z-20 mt-1 w-44 rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-lg',
             'right-0' => $align === 'right',
             'left-0' => $align === 'left',
         ])>
        {{ $slot }}
    </div>
</div>
