<div class="space-y-5"
     x-data="driveBrowser({ canWrite: @js($canWriteHere), bundleUrl: @js(route('files.bundle')) })"
     x-on:keydown.window="hotkey($event)">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Drive</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Your office's files. Nothing is overwritten — uploading a replacement keeps the old
                version — and deleting means the trash, not gone.
            </p>
        </div>

        @if ($usedBytes !== null)
            <p class="text-sm text-slate-500">
                <span class="font-medium text-slate-700">{{ \App\Support\Bytes::human($usedBytes) }}</span> used
            </p>
        @endif
    </div>

    @error('drive')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    <div class="flex flex-wrap items-center justify-between gap-3">
        {{-- Views. role="tablist"/"tab" rather than a bare <nav>: these buttons
             switch which of three record sets fills the page below them, which
             is exactly what the tab pattern is for, and it gets aria-selected
             for free instead of relying on colour alone to say which is active. --}}
        <div class="inline-flex rounded-lg bg-slate-100 p-1 text-sm" role="tablist" aria-label="Drive view">
            @foreach ([
                'office' => ['My office', 'M4 7a2 2 0 0 1 2-2h3.5l1.8 2H18a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7Z'],
                'shared' => ['Shared with me', 'M16.5 14.5a4 4 0 1 0-9 0M12 11a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4ZM4 20a8 8 0 0 1 16 0'],
                'trash' => ['Trash', 'M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-9 0 1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12'],
            ] as $key => [$label, $path])
                <button type="button" wire:click="switchView('{{ $key }}')"
                        role="tab" aria-selected="{{ $view === $key ? 'true' : 'false' }}"
                        @class([
                            'flex items-center gap-1.5 whitespace-nowrap rounded-md px-3 py-1.5 font-medium transition',
                            'bg-white text-slate-900 shadow-sm' => $view === $key,
                            'text-slate-600 hover:text-slate-900' => $view !== $key,
                        ])>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4 shrink-0">
                        <path d="{{ $path }}" />
                    </svg>
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2">
            {{-- Sort --}}
            @php
                $sortLabels = ['name' => 'Name', 'updated_at' => 'Last changed', 'size' => 'Size'];
            @endphp
            <x-dropdown align="right" label="Sort by">
                <x-slot:trigger>
                    <button type="button" :aria-expanded="menuOpen" aria-haspopup="true"
                            class="flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm text-slate-600 transition hover:text-slate-900">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4">
                            <path d="M7 4v16m0 0-3-3m3 3 3-3M17 20V4m0 0-3 3m3-3 3 3" />
                        </svg>
                        <span class="hidden sm:inline">{{ $sortLabels[$sortBy] ?? 'Name' }}</span>
                    </button>
                </x-slot:trigger>

                @foreach ($sortLabels as $key => $label)
                    <button type="button" wire:click="sort('{{ $key }}')"
                            class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-slate-700 hover:bg-slate-50">
                        <span @class(['font-semibold text-slate-900' => $sortBy === $key])>{{ $label }}</span>
                        @if ($sortBy === $key)
                            <span class="text-xs text-slate-500">{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                        @endif
                    </button>
                @endforeach
            </x-dropdown>

            {{-- Details pane toggle --}}
            <button type="button" @click="toggleDetails()" aria-label="Details"
                    :aria-pressed="detailsOpen ? 'true' : 'false'"
                    :class="detailsOpen ? 'bg-slate-900 text-white' : 'border border-slate-200 bg-white text-slate-500 hover:text-slate-900'"
                    class="rounded-lg p-1.5 transition">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4">
                    <circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8v.01" />
                </svg>
            </button>

            {{-- Grid / list toggle --}}
            <div class="inline-flex rounded-lg bg-slate-100 p-1">
                @foreach ([
                    'grid' => 'M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm9 0h7v7h-7v-7Z',
                    'list' => 'M4 6h16M4 12h16M4 18h16',
                ] as $mode => $path)
                    <button type="button" wire:click="setDisplayMode('{{ $mode }}')" aria-label="{{ ucfirst($mode) }} view"
                            @class([
                                'rounded-md p-1.5 transition',
                                'bg-white text-slate-900 shadow-sm' => $displayMode === $mode,
                                'text-slate-500 hover:text-slate-800' => $displayMode !== $mode,
                            ])>
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4">
                            <path d="{{ $path }}" />
                        </svg>
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Breadcrumbs + toolbar --}}
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 pb-4">
        <nav class="flex min-w-0 flex-wrap items-center gap-1 text-sm text-slate-600" aria-label="Breadcrumb">
            <button type="button" wire:click="openFolder(null)"
                    class="flex items-center gap-1.5 font-medium text-slate-900 hover:text-blue-700">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4">
                    <path d="M4 7a2 2 0 0 1 2-2h3.5l1.8 2H18a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7Z" />
                </svg>
                {{ $view === 'shared' ? 'Shared' : ($view === 'trash' ? 'Trash' : 'My office') }}
            </button>
            @foreach ($breadcrumbs as $crumb)
                <span class="text-slate-300">/</span>
                @if ($loop->last)
                    <span class="truncate font-medium text-slate-900">{{ $crumb->name }}</span>
                @else
                    <button type="button" wire:click="openFolder({{ $crumb->id }})"
                            class="truncate text-slate-600 hover:text-blue-700">{{ $crumb->name }}</button>
                @endif
            @endforeach
        </nav>

        <div class="flex items-center gap-2">
            <div class="relative">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                     class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-slate-400">
                    <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
                </svg>
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search files…"
                       class="w-full rounded-lg border-slate-300 py-1.5 pl-8 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600 sm:w-56">
            </div>

            @if ($view !== 'trash' && ($canWriteHere || $current === null))
                <x-dropdown align="right">
                    <x-slot:trigger>
                        <button type="button" :aria-expanded="menuOpen" aria-haspopup="true"
                                class="flex items-center gap-1.5 rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2" stroke-linecap="round" class="size-4">
                                <path d="M12 5v14M5 12h14" />
                            </svg>
                            New
                        </button>
                    </x-slot:trigger>

                    @if ($canWriteHere)
                        <button type="button" @click="$refs.uploadInput.click()"
                                class="flex w-full items-start gap-2 px-3 py-2 text-left text-slate-700 hover:bg-slate-50">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="mt-0.5 size-4 shrink-0 text-slate-400">
                                <path d="M12 16V4m0 0 4 4m-4-4-4 4M5 16v3a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-3" />
                            </svg>
                            <span>
                                <span class="block">Upload file</span>
                                <span class="block text-xs font-normal text-slate-400">Up to {{ $maxUploadMb }} MB</span>
                            </span>
                        </button>
                    @endif
                    @can('create', \App\Models\Folder::class)
                        <button type="button" wire:click="open('new-folder')"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left text-slate-700 hover:bg-slate-50">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4 text-slate-400">
                                <path d="M4 7a2 2 0 0 1 2-2h3.5l1.8 2H18a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7Z" />
                                <path d="M12 11v4M10 13h4" />
                            </svg>
                            New folder
                        </button>
                    @endcan
                </x-dropdown>
            @elseif ($current && ! $canWriteHere)
                <p class="text-sm text-slate-500">
                    Belongs to {{ $current->department?->displayName() }} — read only.
                </p>
            @endif
        </div>
    </div>

    {{--
        Selection bar. Replaces nothing — it appears above the listing when
        something is picked, so the ordinary toolbar does not shift about as a
        selection comes and goes. Every action is offered only when *all* of
        what is selected allows it; the server checks each item again anyway.
    --}}
    <div x-show="selected.length" style="display: none"
         class="flex flex-wrap items-center gap-2 rounded-xl border border-blue-200 bg-blue-50 px-3 py-2">
        <button type="button" @click="clear()" aria-label="Clear selection"
                class="rounded-lg p-1.5 text-blue-700 transition hover:bg-blue-100">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4">
                <path d="M6 6l12 12M18 6 6 18" />
            </svg>
        </button>

        <p class="text-sm font-semibold text-blue-900" aria-live="polite"
           x-text="`${selected.length} selected`"></p>

        <div class="ml-auto flex flex-wrap items-center gap-1">
            <template x-if="every('download')">
                <button type="button" @click="download()"
                        class="rounded-lg px-2.5 py-1.5 text-sm font-medium text-blue-800 transition hover:bg-blue-100">
                    Download
                </button>
            </template>
            <template x-if="every('move')">
                <button type="button" @click="move()"
                        class="rounded-lg px-2.5 py-1.5 text-sm font-medium text-blue-800 transition hover:bg-blue-100">
                    Move
                </button>
            </template>
            <template x-if="selected.length === 1 && every('rename')">
                <button type="button" @click="single('rename')"
                        class="rounded-lg px-2.5 py-1.5 text-sm font-medium text-blue-800 transition hover:bg-blue-100">
                    Rename
                </button>
            </template>
            <template x-if="every('restore')">
                <button type="button" @click="restore()"
                        class="rounded-lg px-2.5 py-1.5 text-sm font-medium text-blue-800 transition hover:bg-blue-100">
                    Restore
                </button>
            </template>
            <template x-if="every('delete')">
                <button type="button" @click="trash()"
                        class="rounded-lg px-2.5 py-1.5 text-sm font-medium text-red-700 transition hover:bg-red-100">
                    Delete
                </button>
            </template>
            @if ($mayPurge)
                <template x-if="every('purge')">
                    <button type="button" @click="purge()"
                            class="rounded-lg px-2.5 py-1.5 text-sm font-medium text-red-700 transition hover:bg-red-100">
                        Destroy
                    </button>
                </template>
            @endif
        </div>
    </div>

    {{-- Hidden input backing both "Upload file" and drag-and-drop. Livewire's own
         upload-lifecycle events drive the progress bar and the auto-submit, so
         there is nothing to wire up beyond this one element. --}}
    <input type="file" x-ref="uploadInput" wire:model="upload" class="hidden"
           x-on:livewire-upload-start="progress = 0"
           x-on:livewire-upload-progress="progress = $event.detail.progress"
           x-on:livewire-upload-finish="progress = null; $wire.uploadFile()"
           x-on:livewire-upload-error="progress = null">

    <div x-show="progress !== null" style="display: none">
        <div class="h-1.5 overflow-hidden rounded-full bg-slate-100"
             role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-blue-600 transition-all" :style="`width: ${progress}%`"></div>
        </div>
        <p class="mt-1 text-xs text-slate-500" aria-live="polite" x-text="`Uploading… ${progress}%`"></p>
    </div>

    @error('upload')
        <p class="text-sm text-red-700" role="alert">{{ $message }}</p>
    @enderror

    {{-- Stacks below the listing on a narrow screen rather than vanishing:
         the toggle is in the toolbar at every width, and a button that does
         nothing visible is worse than no button. --}}
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start">
    <div class="min-w-0 flex-1 space-y-5">

    {{--
        The canvas: drop zone for files arriving from the desktop, and the
        surface a rubber-band selection is drawn on. isFromDesktop() keeps the
        two apart — without it, dragging a file *within* the drive would raise
        the "Drop to upload" sheet over the folder being aimed at.
    --}}
    <div class="relative" data-canvas x-ref="canvas"
         :class="marquee && 'select-none'"
         @mousedown="startMarquee($event)"
         @contextmenu="openMenu($event)"
         @dragover.prevent="if (canWrite && isFromDesktop($event)) dragging = true"
         @dragenter.prevent="if (canWrite && isFromDesktop($event)) dragging = true"
         @dragleave.prevent="dragging = false"
         @drop.prevent="dragging = false; if (canWrite && $event.dataTransfer.files.length) {
             const dt = new DataTransfer();
             dt.items.add($event.dataTransfer.files[0]);
             $refs.uploadInput.files = dt.files;
             $refs.uploadInput.dispatchEvent(new Event('change', { bubbles: true }));
         }">

        <div x-show="dragging" style="display: none"
             class="pointer-events-none absolute inset-0 z-10 flex items-center justify-center rounded-xl border-2 border-dashed border-blue-500 bg-blue-50/90">
            <p class="text-sm font-semibold text-blue-700">Drop to upload{{ $current ? " to “{$current->name}”" : '' }}</p>
        </div>

        {{-- The rubber band itself. --}}
        <div x-show="marquee" :style="marqueeStyle()"
             class="pointer-events-none absolute z-20 rounded-sm border border-blue-500 bg-blue-500/15"></div>

        @if ($folders->isEmpty() && $files->isEmpty())
            {{-- Empty state --}}
            <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
                <div class="flex size-14 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" class="size-7">
                        <path d="M4 7a2 2 0 0 1 2-2h3.5l1.8 2H18a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7Z" />
                    </svg>
                </div>
                <p class="mt-4 text-sm font-medium text-slate-700">
                    @if ($view === 'trash')
                        The trash is empty.
                    @elseif ($search !== '')
                        Nothing matches “{{ $search }}”.
                    @elseif ($current)
                        This folder is empty.
                    @else
                        Open a folder to see what is in it.
                    @endif
                </p>
                @if ($canWriteHere && $search === '')
                    <button type="button" @click="$refs.uploadInput.click()"
                            class="mt-4 rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                        Upload a file
                    </button>
                @endif
            </div>
        @elseif ($displayMode === 'grid')
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
                @foreach ($folders as $folder)
                    @php
                        $key = 'folder:'.$folder->id;
                        $may = $abilities[$key] ?? [];
                    @endphp
                    {{-- $el.dataset.key rather than the id written into each handler:
                         the key is already on the element, and one copy cannot drift
                         from the other. --}}
                    <div wire:key="folder-{{ $folder->id }}" {!! $may['attrs'] !!}
                         tabindex="0" draggable="{{ ! empty($may['move']) ? 'true' : 'false' }}"
                         role="button" aria-label="Folder {{ $folder->name }}"
                         @click="click($event, $el.dataset.key)"
                         @dblclick="openItem($el.dataset.key)"
                         @keydown.enter.stop.prevent="openItem($el.dataset.key)"
                         @contextmenu="openMenu($event, $el.dataset.key)"
                         @dragstart="startDrag($event, $el.dataset.key)"
                         @dragover="overFolder($event, $el.dataset.key)"
                         @dragleave="dropTarget = null"
                         @drop="dropOnFolder($event, $el.dataset.key)"
                         :class="{
                             'ring-2 ring-blue-500 border-blue-300 bg-blue-50': has($el.dataset.key),
                             'ring-2 ring-blue-600 bg-blue-50': dropTarget === $el.dataset.key,
                         }"
                         class="group relative cursor-pointer rounded-xl border border-slate-200 bg-white p-4 transition hover:border-slate-300 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                        {{-- In the flow, not laid over the icon: the "thumbnail" here is a
                             small tinted glyph, and a tick box sitting on its corner reads
                             as damage rather than as a control. --}}
                        <div class="mb-2 flex h-5 items-center">
                            <x-drive.select-box :item-key="$key" :label="'folder '.$folder->name" />
                        </div>

                        {{--
                            A real link, like a file's.

                            folderId is a #[Url] property, so the address alone is enough to
                            open a folder — no handler, no lookup, no state. wire:navigate
                            keeps it as quick as the old click was when the JavaScript is
                            working, and the link still works when it is not.
                        --}}
                        <a href="{{ $folder->openUrl($view) }}" wire:navigate data-open-link
                           class="block focus:outline-none">
                            <x-drive.folder-icon :system="$folder->is_system" size="lg" />
                            <span class="mt-3 block truncate text-sm font-medium text-slate-900">{{ $folder->name }}</span>
                            <span class="mt-0.5 block text-xs text-slate-500">
                                {{ $folder->files_count }} file{{ $folder->files_count === 1 ? '' : 's' }}
                                @if ($view === 'shared') · {{ $folder->department?->displayName() }} @endif
                            </span>
                        </a>

                        <div class="mt-2">
                            <x-status-badge :tone="$folder->visibility->tone()" :label="$folder->visibility->label()" />
                        </div>

                        @if (! empty($may['rename']) || ! empty($may['delete']))
                            {{-- focus-within as well as hover: these controls are in the tab order, and
                                 an opacity-0 button is still focusable — without this a keyboard user
                                 tabs into something they cannot see.

                                 The .stop handlers keep a click on this menu from also selecting or
                                 opening the tile it sits on. --}}
                            <div class="absolute right-2 top-2 opacity-0 transition focus-within:opacity-100 group-hover:opacity-100"
                                 @click.stop @dblclick.stop @mousedown.stop @contextmenu.stop>
                                <x-dropdown align="right" label="More actions for folder {{ $folder->name }}">
                                    @if (! empty($may['rename']))
                                        <button type="button" wire:click="open('rename-folder', {{ $folder->id }})"
                                                class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Rename</button>
                                        <button type="button" wire:click="open('visibility', {{ $folder->id }})"
                                                class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Sharing</button>
                                    @endif
                                    @if (! empty($may['move']))
                                        <button type="button" @click="selectOnly('{{ $key }}'); move()"
                                                class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Move</button>
                                    @endif
                                    @if (! empty($may['delete']))
                                        <button type="button" wire:click="deleteFolder({{ $folder->id }})"
                                                wire:confirm="Delete “{{ $folder->name }}”? It must be empty, including the trash."
                                                class="block w-full px-3 py-2 text-left text-red-700 hover:bg-red-50">Delete</button>
                                    @endif
                                </x-dropdown>
                            </div>
                        @endif
                    </div>
                @endforeach

                @foreach ($files as $file)
                    @php
                        $key = 'file:'.$file->id;
                        $may = $abilities[$key] ?? [];
                    @endphp
                    {{--
                        A trashed file has no download/preview route — FilePolicy scopes both
                        through visibleTo(), which excludes soft-deleted rows by default, so
                        opening one would 403. The 'open' flag is false there, and open()
                        checks it before going anywhere.
                    --}}
                    <div wire:key="file-{{ $file->id }}" {!! $may['attrs'] !!}
                         tabindex="0" draggable="{{ ! empty($may['move']) ? 'true' : 'false' }}"
                         role="button" aria-label="{{ $file->name }}"
                         @click="click($event, $el.dataset.key)"
                         @dblclick="openItem($el.dataset.key)"
                         @keydown.enter.stop.prevent="openItem($el.dataset.key)"
                         @contextmenu="openMenu($event, $el.dataset.key)"
                         @dragstart="startDrag($event, $el.dataset.key)"
                         :class="{ 'ring-2 ring-blue-500 border-blue-300 bg-blue-50': has($el.dataset.key) }"
                         @class([
                             'group relative rounded-xl border border-slate-200 bg-white p-4 transition hover:border-slate-300 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500',
                             'cursor-pointer' => ! empty($may['open']),
                             'cursor-default' => empty($may['open']),
                         ])>
                        <div class="mb-2 flex h-5 items-center">
                            <x-drive.select-box :item-key="$key" :label="$file->name" />
                        </div>

                        {{--
                            A real link, not a JavaScript handler.

                            Opening a file used to go through the selection layer: look the
                            item up in the DOM, set a state flag, let an overlay react to it.
                            Three things that can each fail quietly, and one of them did —
                            after opening a folder, no file would open again until the page
                            was reloaded. An anchor cannot fail that way. It works with the
                            selection layer broken, with Alpine not booted, and with
                            JavaScript off entirely.

                            data-open-link marks it so a modifier-click can be intercepted
                            for selection instead of opening.
                        --}}
                        @if (! empty($may['open']))
                            <a href="{{ $may['url'] }}" data-open-link
                               @if (! empty($may['preview'])) target="_blank" rel="noopener" @endif
                               class="block focus:outline-none">
                                <x-drive.file-icon :file="$file" size="lg" />
                                <span class="mt-3 block truncate text-sm font-medium text-slate-900">{{ $file->name }}</span>
                            </a>
                        @else
                            <x-drive.file-icon :file="$file" size="lg" />
                            <span class="mt-3 block truncate text-sm font-medium text-slate-900">{{ $file->name }}</span>
                        @endif

                        <span class="mt-0.5 block truncate text-xs text-slate-500">
                            {{ $file->humanSize() }}
                            @if ($file->hasOlderVersions()) · v{{ $file->version_no }} @endif
                            @if ($search !== '' || $view === 'trash') · in {{ $file->folder?->name }} @endif
                        </span>

                        <div class="absolute right-2 top-2 flex items-center gap-0.5 opacity-0 transition focus-within:opacity-100 group-hover:opacity-100"
                             @click.stop @dblclick.stop @mousedown.stop @contextmenu.stop>
                            @if ($view === 'trash')
                                @if (! empty($may['restore']))
                                    <button type="button" wire:click="restoreFile({{ $file->id }})"
                                            title="Restore" aria-label="Restore {{ $file->name }}" class="rounded-lg bg-white p-1.5 text-slate-500 shadow-sm ring-1 ring-slate-200 hover:text-blue-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4">
                                            <path d="M4 9a8 8 0 1 1 2 5.3M4 9V4m0 5h5" />
                                        </svg>
                                    </button>
                                @endif
                                @if (! empty($may['purge']))
                                    <button type="button" wire:click="purgeFile({{ $file->id }})"
                                            wire:confirm="Destroy this file and every version of it? This cannot be undone."
                                            title="Destroy" aria-label="Destroy {{ $file->name }} and every version" class="rounded-lg bg-white p-1.5 text-slate-500 shadow-sm ring-1 ring-slate-200 hover:text-red-700">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                             stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4">
                                            <path d="M6 7h12M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-8 0 1 12a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1l1-12" />
                                        </svg>
                                    </button>
                                @endif
                            @else
                                <a href="{{ route('files.download', $file) }}" title="Download" aria-label="Download {{ $file->name }}"
                                   class="rounded-lg bg-white p-1.5 text-slate-500 shadow-sm ring-1 ring-slate-200 hover:text-blue-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                         stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4">
                                        <path d="M12 4v11m0 0 3.5-3.5M12 15l-3.5-3.5M5 16v3a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-3" />
                                    </svg>
                                </a>
                                @if (! empty($may['rename']) || ! empty($may['delete']))
                                    <x-dropdown align="right" label="More actions for {{ $file->name }}">
                                        @if (! empty($may['version']))
                                            <button type="button" wire:click="open('version', {{ $file->id }})"
                                                    class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">New version</button>
                                            <button type="button" wire:click="open('rename-file', {{ $file->id }})"
                                                    class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Rename</button>
                                            <button type="button" @click="selectOnly('{{ $key }}'); move()"
                                                    class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Move</button>
                                        @endif
                                        @if (! empty($may['delete']))
                                            <button type="button" wire:click="trashFile({{ $file->id }})"
                                                    class="block w-full px-3 py-2 text-left text-red-700 hover:bg-red-50">Delete</button>
                                        @endif
                                    </x-dropdown>
                                @endif
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            {{-- List view --}}
            <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            {{-- aria-sort so a screen reader announces which column is ordering
                                 the table, and which way — the arrow alone says it only to
                                 somebody who can see it. --}}
                            @foreach ([
                                'name' => ['Name', ''],
                                'size' => ['Size', ''],
                                'updated_at' => ['Last changed', ''],
                            ] as $column => [$label, $_])
                                <th scope="col" class="px-4 py-3"
                                    aria-sort="{{ $sortBy === $column ? ($sortDir === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                                    <button type="button" wire:click="sort('{{ $column }}')"
                                            class="group inline-flex items-center gap-1 uppercase tracking-wide hover:text-slate-800">
                                        {{ $label }}
                                        <span @class([
                                            'text-[10px]',
                                            'invisible group-hover:visible' => $sortBy !== $column,
                                        ])>{{ $sortBy === $column && $sortDir === 'desc' ? '↓' : '↑' }}</span>
                                    </button>
                                </th>
                            @endforeach
                            <th scope="col" class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($folders as $folder)
                            @php
                                $key = 'folder:'.$folder->id;
                                $may = $abilities[$key] ?? [];
                            @endphp
                            <tr wire:key="folder-row-{{ $folder->id }}" {!! $may['attrs'] !!}
                                tabindex="0" draggable="{{ ! empty($may['move']) ? 'true' : 'false' }}"
                                @click="click($event, $el.dataset.key)"
                                @dblclick="openItem($el.dataset.key)"
                                @keydown.enter.stop.prevent="openItem($el.dataset.key)"
                                @contextmenu="openMenu($event, $el.dataset.key)"
                                @dragstart="startDrag($event, $el.dataset.key)"
                                @dragover="overFolder($event, $el.dataset.key)"
                                @dragleave="dropTarget = null"
                                @drop="dropOnFolder($event, $el.dataset.key)"
                                :class="{
                                    'bg-blue-50': has($el.dataset.key),
                                    'ring-2 ring-inset ring-blue-600': dropTarget === $el.dataset.key,
                                }"
                                class="group cursor-pointer hover:bg-slate-50">
                                <td class="px-4 py-2.5">
                                    <span class="flex min-w-0 items-center gap-3 text-left">
                                        <x-drive.select-box :item-key="$key" :label="'folder '.$folder->name" />

                                        {{-- A real link, for the same reason as the grid tile. --}}
                                        <a href="{{ $folder->openUrl($view) }}" wire:navigate data-open-link
                                           class="flex min-w-0 items-center gap-3 focus:outline-none">
                                            <x-drive.folder-icon :system="$folder->is_system" />
                                            <span class="min-w-0">
                                                <span class="block truncate font-medium text-slate-900">{{ $folder->name }}</span>
                                                <span class="block text-xs text-slate-500">
                                                    {{ $folder->files_count }} file{{ $folder->files_count === 1 ? '' : 's' }}
                                                    @if ($view === 'shared') · {{ $folder->department?->displayName() }} @endif
                                                </span>
                                            </span>
                                        </a>
                                    </span>
                                </td>
                                <td class="px-4 py-2.5 text-slate-400">—</td>
                                <td class="px-4 py-2.5">
                                    <x-status-badge :tone="$folder->visibility->tone()" :label="$folder->visibility->label()" />
                                </td>
                                <td class="px-4 py-2.5 text-right">
                                    @if (! empty($may['rename']) || ! empty($may['delete']))
                                        <div class="inline-block opacity-0 transition focus-within:opacity-100 group-hover:opacity-100"
                                             @click.stop @dblclick.stop @mousedown.stop @contextmenu.stop>
                                            <x-dropdown align="right" label="More actions for folder {{ $folder->name }}">
                                                @if (! empty($may['rename']))
                                                    <button type="button" wire:click="open('rename-folder', {{ $folder->id }})"
                                                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Rename</button>
                                                    <button type="button" wire:click="open('visibility', {{ $folder->id }})"
                                                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Sharing</button>
                                                @endif
                                                @if (! empty($may['move']))
                                                    <button type="button" @click="selectOnly('{{ $key }}'); move()"
                                                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Move</button>
                                                @endif
                                                @if (! empty($may['delete']))
                                                    <button type="button" wire:click="deleteFolder({{ $folder->id }})"
                                                            wire:confirm="Delete “{{ $folder->name }}”? It must be empty, including the trash."
                                                            class="block w-full px-3 py-2 text-left text-red-700 hover:bg-red-50">Delete</button>
                                                @endif
                                            </x-dropdown>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach

                        @foreach ($files as $file)
                            @php
                                $key = 'file:'.$file->id;
                                $may = $abilities[$key] ?? [];
                            @endphp
                            <tr wire:key="file-row-{{ $file->id }}" {!! $may['attrs'] !!}
                                tabindex="0" draggable="{{ ! empty($may['move']) ? 'true' : 'false' }}"
                                @click="click($event, $el.dataset.key)"
                                @dblclick="openItem($el.dataset.key)"
                                @keydown.enter.stop.prevent="openItem($el.dataset.key)"
                                @contextmenu="openMenu($event, $el.dataset.key)"
                                @dragstart="startDrag($event, $el.dataset.key)"
                                :class="{ 'bg-blue-50': has($el.dataset.key) }"
                                @class([
                                    'group hover:bg-slate-50',
                                    'cursor-pointer' => ! empty($may['open']),
                                    'cursor-default' => empty($may['open']),
                                ])>
                                <td class="px-4 py-2.5">
                                    <span class="flex min-w-0 items-center gap-3">
                                        <x-drive.select-box :item-key="$key" :label="$file->name" />

                                        {{-- A real link, for the same reason as the grid tile. --}}
                                        @if (! empty($may['open']))
                                            <a href="{{ $may['url'] }}" data-open-link
                                               @if (! empty($may['preview'])) target="_blank" rel="noopener" @endif
                                               class="flex min-w-0 items-center gap-3 focus:outline-none">
                                                <x-drive.file-icon :file="$file" />
                                                <span class="min-w-0">
                                                    <span class="block truncate font-medium text-slate-900">{{ $file->name }}</span>
                                                    <span class="block truncate text-xs text-slate-500">
                                                        {{ $file->original_name }}
                                                        @if ($file->hasOlderVersions()) · version {{ $file->version_no }} @endif
                                                        @if ($search !== '' || $view === 'trash') · in {{ $file->folder?->name }} @endif
                                                    </span>
                                                </span>
                                            </a>
                                        @else
                                            <x-drive.file-icon :file="$file" />
                                            <span class="min-w-0">
                                                <span class="block truncate font-medium text-slate-900">{{ $file->name }}</span>
                                                <span class="block truncate text-xs text-slate-500">
                                                    {{ $file->original_name }}
                                                    @if ($file->hasOlderVersions()) · version {{ $file->version_no }} @endif
                                                    @if ($search !== '' || $view === 'trash') · in {{ $file->folder?->name }} @endif
                                                </span>
                                            </span>
                                        @endif
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">{{ $file->humanSize() }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-slate-600">
                                    {{ ph_date($file->updated_at) }}
                                    <span class="block text-xs text-slate-500">{{ $file->uploader?->name }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                    <div class="inline-flex items-center gap-0.5 opacity-0 transition focus-within:opacity-100 group-hover:opacity-100"
                                         @click.stop @dblclick.stop @mousedown.stop @contextmenu.stop>
                                        @if ($view === 'trash')
                                            @if (! empty($may['restore']))
                                                <button type="button" wire:click="restoreFile({{ $file->id }})"
                                                        class="rounded-lg px-2 py-1 text-sm font-medium text-blue-700 hover:bg-blue-50">Restore</button>
                                            @endif
                                            @if (! empty($may['purge']))
                                                <button type="button" wire:click="purgeFile({{ $file->id }})"
                                                        wire:confirm="Destroy this file and every version of it? This cannot be undone."
                                                        class="rounded-lg px-2 py-1 text-sm font-medium text-red-700 hover:bg-red-50">Destroy</button>
                                            @endif
                                        @else
                                            <a href="{{ route('files.download', $file) }}" title="Download" aria-label="Download {{ $file->name }}"
                                               class="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-blue-700">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4">
                                                    <path d="M12 4v11m0 0 3.5-3.5M12 15l-3.5-3.5M5 16v3a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-3" />
                                                </svg>
                                            </a>
                                            @if (! empty($may['rename']) || ! empty($may['delete']))
                                                <x-dropdown align="right" label="More actions for {{ $file->name }}">
                                                    @if (! empty($may['version']))
                                                        <button type="button" wire:click="open('version', {{ $file->id }})"
                                                                class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">New version</button>
                                                        <button type="button" wire:click="open('rename-file', {{ $file->id }})"
                                                                class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Rename</button>
                                                        <button type="button" @click="selectOnly('{{ $key }}'); move()"
                                                                class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Move</button>
                                                    @endif
                                                    @if (! empty($may['delete']))
                                                        <button type="button" wire:click="trashFile({{ $file->id }})"
                                                                class="block w-full px-3 py-2 text-left text-red-700 hover:bg-red-50">Delete</button>
                                                    @endif
                                                </x-dropdown>
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Paging away leaves a selection pointing at rows no longer on screen: the
         bar would read "6 selected" over a page showing none of them, and Delete
         would take away things the user cannot see. The pagination links go
         through setPage(), which fires no updated-property hook on the server,
         so the selection is put down here on the way out instead. --}}
    <div @click="clear()">{{ $files->links() }}</div>

    </div>{{-- /listing column --}}

    {{--
        Details. Only ever describes one thing: with nothing or several picked
        there is no single set of facts to show, so it says so rather than
        guessing. The content is fetched on selection change (loadDetails) and
        only while the pane is open, so browsing with it closed costs nothing.
    --}}
    <aside x-show="detailsOpen" style="display: none"
           class="w-full shrink-0 rounded-xl border border-slate-200 bg-white lg:w-72"
           aria-label="Details">
        <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
            <h2 class="text-sm font-semibold text-slate-900">Details</h2>
            <button type="button" @click="toggleDetails()" aria-label="Close details"
                    class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" class="size-4">
                    <path d="M6 6l12 12M18 6 6 18" />
                </svg>
            </button>
        </div>

        @if ($details === null)
            <p class="px-4 py-6 text-sm text-slate-500" x-text="selected.length > 1
                ? `${selected.length} items selected.`
                : 'Select one item to see what is known about it.'"></p>
        @elseif ($details['kind'] === 'folder')
            @php
                $folder = $details['folder'];
            @endphp
            <dl class="space-y-3 px-4 py-4 text-sm">
                <div class="flex items-center gap-3">
                    <x-drive.folder-icon :system="$folder->is_system" />
                    <p class="min-w-0 flex-1 truncate font-medium text-slate-900">{{ $folder->name }}</p>
                </div>
                <div><dt class="text-xs text-slate-500">Holds</dt>
                    <dd class="text-slate-800">{{ $folder->files_count }} file{{ $folder->files_count === 1 ? '' : 's' }},
                        {{ $folder->children_count }} folder{{ $folder->children_count === 1 ? '' : 's' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Who can see it</dt>
                    <dd class="mt-0.5"><x-status-badge :tone="$folder->visibility->tone()" :label="$folder->visibility->label()" /></dd></div>
                <div><dt class="text-xs text-slate-500">Office</dt>
                    <dd class="text-slate-800">{{ $folder->department?->displayName() ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Inside</dt>
                    <dd class="text-slate-800">{{ $folder->parent?->name ?? 'Top level' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Created</dt>
                    <dd class="text-slate-800">{{ ph_date($folder->created_at) }}
                        @if ($folder->creator) <span class="block text-xs text-slate-500">by {{ $folder->creator->name }}</span> @endif
                    </dd></div>
            </dl>
        @else
            @php
                $file = $details['file'];
            @endphp
            <dl class="space-y-3 px-4 py-4 text-sm">
                <div class="flex items-center gap-3">
                    <x-drive.file-icon :file="$file" />
                    <p class="min-w-0 flex-1 truncate font-medium text-slate-900">{{ $file->name }}</p>
                </div>
                <div><dt class="text-xs text-slate-500">Type</dt>
                    <dd class="text-slate-800">{{ $file->kindLabel() }} · {{ $file->humanSize() }}</dd></div>
                <div><dt class="text-xs text-slate-500">Filed under</dt>
                    <dd class="text-slate-800">{{ $file->folder?->name ?? '—' }}</dd></div>
                <div><dt class="text-xs text-slate-500">Uploaded as</dt>
                    <dd class="break-all text-slate-800">{{ $file->original_name }}</dd></div>
                <div><dt class="text-xs text-slate-500">Last changed</dt>
                    <dd class="text-slate-800">{{ ph_date($file->updated_at) }}
                        @if ($file->uploader) <span class="block text-xs text-slate-500">by {{ $file->uploader->name }}</span> @endif
                    </dd></div>

                <div>
                    <dt class="text-xs text-slate-500">Versions</dt>
                    <dd class="mt-1 space-y-1">
                        @foreach ($details['versions'] as $version)
                            <div @class([
                                'flex items-baseline justify-between gap-2 rounded-lg px-2 py-1 text-xs',
                                'bg-blue-50 text-blue-900' => $version->is_current,
                                'text-slate-600' => ! $version->is_current,
                            ])>
                                <span>v{{ $version->version_no }}{{ $version->is_current ? ' · current' : '' }}</span>
                                <span class="shrink-0">{{ ph_date($version->created_at) }}</span>
                            </div>
                        @endforeach
                    </dd>
                </div>

                {{-- The recorded checksum, shortened. It is here so that "is this
                     still the file we filed" is a question the screen can answer
                     without anybody opening a terminal. --}}
                <div><dt class="text-xs text-slate-500">Checksum (SHA-256)</dt>
                    <dd class="font-mono text-xs text-slate-500">{{ mb_substr($file->sha256, 0, 16) }}…</dd></div>
            </dl>
        @endif
    </aside>
    </div>{{-- /listing + details --}}

    {{--
        Right-click menu. Offers only what everything selected allows, and
        closes on any click elsewhere, on scroll, and on Escape.
    --}}
    <div x-show="menu" style="display: none" @click.outside="menu = null" x-on:scroll.window="menu = null"
         :style="menu ? `left:${menu.x}px;top:${menu.y}px` : ''"
         class="fixed z-40 w-52 rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-xl"
         role="menu">
        <template x-if="selected.length === 0">
            <div>
                @if ($canWriteHere)
                    <button type="button" role="menuitem" @click="menu = null; $refs.uploadInput.click()"
                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Upload file</button>
                @endif
                @can('create', \App\Models\Folder::class)
                    <button type="button" role="menuitem" @click="menu = null; $wire.open('new-folder')"
                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">New folder</button>
                @endcan
                <button type="button" role="menuitem" @click="menu = null; selectAll()"
                        class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Select all</button>
            </div>
        </template>

        <template x-if="selected.length > 0">
            <div>
                <template x-if="selected.length === 1 && every('open')">
                    <button type="button" role="menuitem" @click="const k = selected[0]; menu = null; openItem(k)"
                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Open</button>
                </template>
                <template x-if="every('download')">
                    <button type="button" role="menuitem" @click="menu = null; download()"
                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50"
                            x-text="selected.length > 1 ? 'Download as zip' : 'Download'"></button>
                </template>
                <template x-if="selected.length === 1 && every('rename')">
                    <button type="button" role="menuitem" @click="single('rename')"
                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Rename</button>
                </template>
                <template x-if="selected.length === 1 && every('share')">
                    <button type="button" role="menuitem" @click="single('visibility')"
                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Sharing</button>
                </template>
                <template x-if="selected.length === 1 && every('version')">
                    <button type="button" role="menuitem" @click="single('version')"
                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">New version</button>
                </template>
                <template x-if="every('move')">
                    <button type="button" role="menuitem" @click="menu = null; move()"
                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Move</button>
                </template>
                <template x-if="every('restore')">
                    <button type="button" role="menuitem" @click="menu = null; restore()"
                            class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Restore</button>
                </template>

                <button type="button" role="menuitem" @click="menu = null; toggleDetails()"
                        class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">Details</button>

                <template x-if="every('delete')">
                    <button type="button" role="menuitem" @click="menu = null; trash()"
                            class="block w-full border-t border-slate-100 px-3 py-2 text-left text-red-700 hover:bg-red-50">Delete</button>
                </template>
                @if ($mayPurge)
                    <template x-if="every('purge')">
                        <button type="button" role="menuitem" @click="menu = null; purge()"
                                class="block w-full border-t border-slate-100 px-3 py-2 text-left text-red-700 hover:bg-red-50">Destroy for good</button>
                    </template>
                @endif
            </div>
        </template>
    </div>

    {{-- Panels --}}
    @if (in_array($panel, ['new-folder', 'rename-folder', 'visibility', 'rename-file', 'move', 'version'], true))
        @php
            // Names the dialog for assistive technology. Each panel below carries
            // the same words in its <h2>, so what is announced and what is read
            // on screen cannot drift apart.
            $panelTitle = match ($panel) {
                'new-folder' => 'New folder',
                'rename-folder' => 'Rename folder',
                'visibility' => 'Who can see this folder',
                'rename-file' => 'Rename file',
                'move' => 'Move to another folder',
                'version' => 'Upload a new version',
            };
        @endphp
        {{-- Escape closes it, as a dialog is expected to. overflow-y-auto so a
             short window (a laptop with the browser half-height) can still reach
             the buttons rather than having them fall off the bottom. --}}
        <div class="fixed inset-0 z-40 flex items-center justify-center overflow-y-auto bg-slate-900/40 p-4"
             wire:click.self="closePanel"
             x-data x-on:keydown.escape.window="$wire.closePanel()">
            {{--
                aria-modal="true" tells a screen reader the rest of the page is
                unreachable while this is open. Tab must actually agree with
                that or a sighted keyboard user ends up somewhere the reader
                already said doesn't exist — so it is cycled by hand here
                rather than left to fall through to the sidebar behind it.
            --}}
            <div class="max-h-full w-full max-w-md overflow-y-auto rounded-xl bg-white p-6 shadow-xl"
                 role="dialog" aria-modal="true" aria-label="{{ $panelTitle }}"
                 x-data
                 x-on:keydown.tab="
                     const focusable = [...$el.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex=\'-1\'])')]
                         .filter((el) => ! el.disabled && el.offsetParent !== null);
                     if (! focusable.length) return;
                     const first = focusable[0], last = focusable[focusable.length - 1];
                     if ($event.shiftKey && document.activeElement === first) { $event.preventDefault(); last.focus(); }
                     else if (! $event.shiftKey && document.activeElement === last) { $event.preventDefault(); first.focus(); }
                 ">
                @if ($panel === 'new-folder' || $panel === 'rename-folder')
                    <form wire:submit="{{ $panel === 'new-folder' ? 'createFolder' : 'renameFolder' }}" class="space-y-4">
                        <h2 class="text-base font-semibold text-slate-900">
                            {{ $panel === 'new-folder' ? 'New folder' : 'Rename folder' }}
                        </h2>

                        <div>
                            <label for="formName" class="block text-sm font-medium text-slate-700">Name</label>
                            <input id="formName" wire:model="formName" type="text" autofocus
                                   class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                            @error('formName') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>

                        @if ($panel === 'new-folder')
                            <div>
                                <label for="formVisibility" class="block text-sm font-medium text-slate-700">Who can see it</label>
                                <select id="formVisibility" wire:model.live="formVisibility"
                                        class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                                    @foreach ($levels as $level)
                                        <option value="{{ $level->value }}">{{ $level->label() }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-xs text-slate-500">
                                    {{ \App\Enums\FolderVisibility::from($formVisibility)->description() }}
                                </p>
                            </div>
                        @endif

                        <x-drive.panel-buttons :label="$panel === 'new-folder' ? 'Create folder' : 'Rename'" />
                    </form>
                @endif

                @if ($panel === 'visibility')
                    <form wire:submit="changeVisibility" class="space-y-4">
                        <h2 class="text-base font-semibold text-slate-900">Who can see this folder</h2>

                        <div>
                            <select wire:model.live="formVisibility" aria-label="Visibility"
                                    class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                                @foreach ($levels as $level)
                                    <option value="{{ $level->value }}">{{ $level->label() }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ \App\Enums\FolderVisibility::from($formVisibility)->description() }}
                            </p>
                            @error('formVisibility') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <x-drive.panel-buttons label="Save" />
                    </form>
                @endif

                @if ($panel === 'rename-file')
                    <form wire:submit="renameFile" class="space-y-4">
                        <h2 class="text-base font-semibold text-slate-900">Rename file</h2>
                        <div>
                            <label for="fileName" class="block text-sm font-medium text-slate-700">Name</label>
                            <input id="fileName" wire:model="formName" type="text" autofocus
                                   class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                            <p class="mt-1 text-xs text-slate-500">Every version of this file carries the same name.</p>
                            @error('formName') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <x-drive.panel-buttons label="Rename" />
                    </form>
                @endif

                @if ($panel === 'move')
                    @php
                        $moving = count($selection);
                        $movingFolders = collect($selection)->filter(fn ($k) => str_starts_with($k, 'folder:'))->count();
                    @endphp
                    <form wire:submit="moveSelected" class="space-y-4">
                        <h2 class="text-base font-semibold text-slate-900">
                            Move {{ $moving === 1 ? 'to another folder' : "{$moving} items" }}
                        </h2>
                        <div>
                            <label for="moveToId" class="block text-sm font-medium text-slate-700">Destination</label>
                            <select id="moveToId" wire:model="moveToId"
                                    class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                                <option value="">Choose a folder…</option>
                                {{-- Offered only when nothing being moved is a file: nothing is
                                     kept loose above a folder, so a file has nowhere to land here. --}}
                                @if ($movingFolders === $moving && $moving > 0)
                                    <option value="0">Top level</option>
                                @endif
                                @foreach ($destinations as $folder)
                                    <option value="{{ $folder->id }}">{{ $folder->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Only folders in your own office.</p>
                            @error('moveToId') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <x-drive.panel-buttons label="Move" />
                    </form>
                @endif

                @if ($panel === 'version')
                    <form wire:submit="addVersion" class="space-y-4">
                        <h2 class="text-base font-semibold text-slate-900">Upload a new version</h2>
                        <p class="text-sm text-slate-600">
                            The version already here is kept. Nothing is overwritten.
                        </p>
                        <div>
                            <label for="versionUpload" class="sr-only">Replacement file</label>
                            <input id="versionUpload" wire:model="versionUpload" type="file"
                                   class="block text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
                            @error('versionUpload') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                        <x-drive.panel-buttons label="Save new version" />
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>
