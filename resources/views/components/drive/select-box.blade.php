@props(['itemKey', 'label' => 'this item'])

{{--
    The way to pick something without opening it.

    A plain click opens — that is what a clerk expects of a folder, and what
    this drive did before any of this existed — so selecting needs a target of
    its own rather than stealing the primary click. It appears on hover, and
    stays visible for anything already picked, and for every row once a
    selection exists, so a selection is never invisible.

    The key is written in rather than read from the element because this sits
    inside the row, not on it. It is always of the form file:12 or folder:3.
--}}
<button type="button" role="checkbox"
        :aria-checked="has('{{ $itemKey }}') ? 'true' : 'false'"
        aria-label="Select {{ $label }}"
        @click.stop="toggle('{{ $itemKey }}')"
        @dblclick.stop
        @mousedown.stop
        @keydown.enter.stop.prevent="toggle('{{ $itemKey }}')"
        :class="[
            has('{{ $itemKey }}')
                ? 'border-blue-600 bg-blue-600 text-white'
                : 'border-slate-300 bg-white text-transparent hover:border-slate-400',
            (has('{{ $itemKey }}') || selected.length)
                ? 'opacity-100'
                : 'opacity-0 group-hover:opacity-100 focus:opacity-100',
        ]"
        {{ $attributes->merge(['class' => 'flex size-5 shrink-0 items-center justify-center rounded border transition']) }}>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
         stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="size-3">
        <path d="m5 12.5 4.5 4.5L19 7" />
    </svg>
</button>
