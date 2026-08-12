{{--
    One app in the catalog grid. Pulled into its own partial because it is
    used identically from the Home strip and the full Apps catalog — the only
    difference between those two is which query feeds the grid.
--}}
<div wire:key="app-{{ $app->id }}"
     class="flex gap-3 rounded-xl border border-slate-200 bg-white p-4 transition hover:border-slate-300">
    <span class="{{ $glyphClass }} flex size-9 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white">
        {{ $app->icon_glyph }}
    </span>

    <div class="min-w-0 flex-1">
        <h3 class="truncate text-sm font-semibold text-slate-900">{{ $app->name }}</h3>
        <p class="mt-1 text-xs leading-relaxed text-slate-600">{{ $app->description }}</p>

        <div class="mt-3 flex flex-wrap items-center gap-2">
            <x-status-badge :tone="$app->status->tone()" :label="$app->status->label()" />
            <x-status-badge :tone="$app->scope->tone()"
                :label="$app->scope->value === 'department' ? ($app->department?->displayName() ?? $app->scope->label()) : $app->scope->label()" />
        </div>

        <a href="{{ $app->url }}" target="_blank" rel="noopener noreferrer"
           class="mt-3 inline-block text-sm font-medium text-blue-700 hover:underline">
            Open ›
        </a>
    </div>
</div>
