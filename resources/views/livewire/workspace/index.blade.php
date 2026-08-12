<?php
    // A fixed, literal palette — Tailwind scans source for class names, so this
    // cannot be built from interpolated color strings. Picked by id rather than
    // scope or status, which already carry their own meaning via the status
    // badges below; this one is just so the grid is not a wall of one color.
    $glyphPalette = ['bg-blue-600', 'bg-emerald-600', 'bg-amber-600', 'bg-rose-600', 'bg-violet-600', 'bg-slate-600'];
    $glyphClass = fn ($app) => $glyphPalette[$app->id % count($glyphPalette)];
?>

<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Workspace</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Your office's files and the systems it runs, in one place. Files and apps stay two
                different kinds of thing underneath — this page just answers to one search box.
            </p>
        </div>

        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search files and apps…"
               class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600 sm:w-80">
    </div>

    {{-- Tabs --}}
    @unless ($searching)
        <div class="border-b border-slate-200">
            <nav class="-mb-px flex gap-6 overflow-x-auto text-sm">
                @foreach (['home' => 'Home', 'apps' => 'Apps'] as $key => $label)
                    <button type="button" wire:click="switchTab('{{ $key }}')"
                            @class([
                                'whitespace-nowrap border-b-2 px-1 py-3 font-medium transition',
                                'border-blue-700 text-blue-700' => $tab === $key,
                                'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' => $tab !== $key,
                            ])>
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>
    @endunless

    @if ($searching)
        {{-- Search results: apps and files, side by side, so "where is X" never
             depends on already knowing which kind of thing X is. --}}
        <div class="grid gap-6 lg:grid-cols-2">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Apps</h2>
                <div class="mt-3 space-y-2">
                    @forelse ($matchedApps as $app)
                        <a wire:key="search-app-{{ $app->id }}" href="{{ $app->url }}" target="_blank" rel="noopener noreferrer"
                           class="flex items-center gap-3 rounded-lg border border-slate-200 bg-white p-3 transition hover:border-slate-300">
                            <span class="{{ $glyphClass($app) }} flex size-8 shrink-0 items-center justify-center rounded-lg text-sm font-bold text-white">
                                {{ $app->icon_glyph }}
                            </span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-slate-900">{{ $app->name }}</span>
                                <span class="block truncate text-xs text-slate-500">
                                    {{ $app->department?->displayName() ?? 'Every office' }}
                                </span>
                            </span>
                        </a>
                    @empty
                        <p class="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500">
                            No apps match "{{ $search }}".
                        </p>
                    @endforelse
                </div>
            </div>

            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Files</h2>
                <div class="mt-3 overflow-hidden rounded-lg border border-slate-200 bg-white">
                    @forelse ($matchedFiles as $file)
                        <a wire:key="search-file-{{ $file->id }}" href="{{ route('drive', ['folderId' => $file->folder_id]) }}"
                           class="flex items-center justify-between gap-3 border-b border-slate-100 p-3 text-sm last:border-b-0 hover:bg-slate-50">
                            <span class="min-w-0">
                                <span class="block truncate font-medium text-slate-900">{{ $file->name }}</span>
                                <span class="block truncate text-xs text-slate-500">in {{ $file->folder?->name }}</span>
                            </span>
                            <span class="shrink-0 text-xs text-slate-500">{{ $file->kindLabel() }}</span>
                        </a>
                    @empty
                        <p class="p-4 text-sm text-slate-500">No files match "{{ $search }}".</p>
                    @endforelse
                </div>
            </div>
        </div>
    @elseif ($tab === 'home')
        {{-- Apps strip --}}
        <div class="flex items-baseline justify-between">
            <h2 class="text-base font-semibold text-slate-900">Apps for this office</h2>
            <button type="button" wire:click="switchTab('apps')" class="text-sm font-medium text-blue-700 hover:underline">
                See all apps ›
            </button>
        </div>

        @if ($homeApps->isNotEmpty())
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($homeApps as $app)
                    @include('livewire.workspace.partials.app-card', ['app' => $app, 'glyphClass' => $glyphClass($app)])
                @endforeach
            </div>
        @else
            <p class="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500">
                No apps have been published for this office yet.
            </p>
        @endif

        {{-- Recent files --}}
        <div class="flex items-baseline justify-between pt-4">
            <h2 class="text-base font-semibold text-slate-900">Recent files</h2>
            <a href="{{ route('drive') }}" wire:navigate class="text-sm font-medium text-blue-700 hover:underline">
                Open Drive ›
            </a>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">Name</th>
                        <th scope="col" class="px-4 py-3">Kind</th>
                        <th scope="col" class="px-4 py-3">Last changed</th>
                        <th scope="col" class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($recentFiles as $file)
                        <tr wire:key="recent-file-{{ $file->id }}" class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <span class="font-medium text-slate-900">{{ $file->name }}</span>
                                <span class="mt-0.5 block text-xs text-slate-500">in {{ $file->folder?->name }}</span>
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $file->kindLabel() }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ ph_date($file->updated_at) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                @if ($file->isPreviewable())
                                    <a href="{{ route('files.preview', $file) }}" target="_blank" rel="noopener"
                                       class="text-sm font-medium text-blue-700 hover:underline">View</a>
                                @endif
                                <a href="{{ route('files.download', $file) }}"
                                   class="ml-3 text-sm font-medium text-blue-700 hover:underline">Download</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-slate-500">
                                Nothing here yet — files this office keeps will show up as they are added.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        {{-- Full apps catalog --}}
        <div class="flex flex-wrap gap-2">
            @foreach (['all' => 'All', 'office' => 'This office', 'shared' => 'Org-wide & public'] as $key => $label)
                <button type="button" wire:click="filterApps('{{ $key }}')"
                        @class([
                            'rounded-full border px-3 py-1.5 text-sm font-medium transition',
                            'border-blue-700 bg-blue-700 text-white' => $appFilter === $key,
                            'border-slate-300 text-slate-700 hover:bg-slate-50' => $appFilter !== $key,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        @if ($catalogApps->isNotEmpty())
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($catalogApps as $app)
                    @include('livewire.workspace.partials.app-card', ['app' => $app, 'glyphClass' => $glyphClass($app)])
                @endforeach
            </div>
        @else
            <p class="rounded-lg border border-dashed border-slate-300 p-4 text-sm text-slate-500">
                Nothing in this filter.
            </p>
        @endif
    @endif
</div>
