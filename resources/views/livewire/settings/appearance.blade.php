<x-settings.shell heading="Appearance"
                  description="How the system looks on your screen. This changes nothing for anybody else.">

    <form wire:submit="save" class="space-y-6">

        {{-- Theme --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900">Theme</h3>
            <p class="mt-1 text-xs text-slate-500">
                Dark is easier on the eyes at a counter with the lights down; light is easier in a
                room with windows behind you.
            </p>

            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                @foreach ($themes as $value => $label)
                    <label @class([
                        'flex cursor-pointer flex-col gap-3 rounded-xl border p-4 transition',
                        'border-blue-600 ring-2 ring-blue-600' => $theme === $value,
                        'border-slate-200 hover:border-slate-300' => $theme !== $value,
                    ])>
                        {{--
                            A drawn miniature rather than a colour swatch: the point of a
                            theme is the relationship between page, card and text, which a
                            single square cannot show.

                            Literal colours, deliberately — not bg-white or bg-slate-800.
                            Those are the very tokens dark mode redefines, so in dark mode
                            the "Light" preview would render itself dark and the picker
                            would show two identical tiles. A preview of a theme cannot be
                            painted in the theme it is previewing.
                        --}}
                        @php
                            [$page, $card, $edge, $line, $faint] = match ($value) {
                                'light' => ['#f1f5f9', '#ffffff', '#cbd5e1', '#94a3b8', '#cbd5e1'],
                                default => ['#0b1220', '#131c2e', '#2a3a5c', '#64748b', '#475569'],
                            };
                        @endphp
                        <span class="flex h-16 overflow-hidden rounded-lg border"
                              style="background-color: {{ $page }}; border-color: {{ $edge }}">
                            <span class="w-1/3 border-r"
                                  style="background-color: {{ $card }}; border-color: {{ $edge }}"></span>
                            <span class="flex flex-1 flex-col justify-center gap-1 p-2">
                                <span class="block h-1.5 w-3/4 rounded-full" style="background-color: {{ $line }}"></span>
                                <span class="block h-1.5 w-1/2 rounded-full" style="background-color: {{ $faint }}"></span>
                            </span>

                            {{-- "Match my device" is both at once, so it is drawn as both. --}}
                            @if ($value === 'system')
                                <span class="w-1/3 border-l"
                                      style="background-color: #f1f5f9; border-color: #cbd5e1"></span>
                            @endif
                        </span>

                        {{-- .live, not deferred: the card's own highlight is server-rendered
                             from $theme, so a deferred model would leave the border on the
                             previous choice until the form was submitted — and the page would
                             not change colour until then either. --}}
                        <span class="flex items-center gap-2">
                            <input type="radio" wire:model.live="theme" value="{{ $value }}" name="theme"
                                   class="border-slate-300 text-blue-700 focus:ring-blue-600">
                            <span class="text-sm font-medium text-slate-800">{{ $label }}</span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('theme') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
        </div>

        {{-- Density and text size --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900">Spacing and type</h3>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="density" class="block text-sm font-medium text-slate-700">Density</label>
                    <select id="density" wire:model.live="density"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($densities as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        Compact fits more rows on a screen — useful on the counter machines.
                    </p>
                    @error('density') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="text_size" class="block text-sm font-medium text-slate-700">Text size</label>
                    <select id="text_size" wire:model.live="text_size"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($textSizes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        Everything scales together, so the layout stays in proportion.
                    </p>
                    @error('text_size') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div>
            <button type="submit"
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                Save appearance
            </button>
            <p class="mt-2 text-xs text-slate-500">
                The page redraws with your choice as soon as it saves.
            </p>
        </div>
    </form>
</x-settings.shell>
