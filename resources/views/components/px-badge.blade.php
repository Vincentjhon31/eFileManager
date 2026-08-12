@props(['tone' => 'slate', 'label' => ''])

{{--
    The public side's badge.

    A separate component from x-status-badge rather than a variant of it. That one
    is Tailwind pill-shaped and belongs to the staff interface, where it appears on
    thirty screens; this one is a hard-edged block on the pixel palette. Giving one
    component two skins would mean every future change to either having to be
    reasoned about twice.

    What they do share is the tone vocabulary — green, amber, blue, red, slate —
    which comes from the enums, so a notice category is the same colour on the
    public page as it is on the desk of whoever posted it.
--}}
<span {{ $attributes->class(['px-badge', 't-'.$tone]) }}>{{ $label ?: $slot }}</span>
