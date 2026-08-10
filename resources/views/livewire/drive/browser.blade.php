<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Drive</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Your office's files. Nothing is overwritten — uploading a replacement keeps the old
                version — and deleting means the trash, not gone.
            </p>
        </div>

        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search files…"
               class="w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600 sm:w-72">
    </div>

    @error('drive')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    {{-- Views --}}
    <div class="border-b border-slate-200">
        <nav class="-mb-px flex gap-6 overflow-x-auto text-sm">
            @foreach (['office' => 'My office', 'shared' => 'Shared with me', 'trash' => 'Trash'] as $key => $label)
                <button type="button" wire:click="switchView('{{ $key }}')"
                        @class([
                            'whitespace-nowrap border-b-2 px-1 py-3 font-medium transition',
                            'border-blue-700 text-blue-700' => $view === $key,
                            'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' => $view !== $key,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Breadcrumbs --}}
    @if ($breadcrumbs->isNotEmpty())
        <nav class="flex flex-wrap items-center gap-1 text-sm text-slate-600" aria-label="Breadcrumb">
            <button type="button" wire:click="openFolder(null)" class="font-medium text-blue-700 hover:underline">
                {{ $view === 'shared' ? 'Shared' : 'My office' }}
            </button>
            @foreach ($breadcrumbs as $crumb)
                <span class="text-slate-400">/</span>
                @if ($loop->last)
                    <span class="font-medium text-slate-900">{{ $crumb->name }}</span>
                @else
                    <button type="button" wire:click="openFolder({{ $crumb->id }})"
                            class="text-blue-700 hover:underline">{{ $crumb->name }}</button>
                @endif
            @endforeach
        </nav>
    @endif

    {{-- Toolbar --}}
    @if ($view !== 'trash')
        <div class="flex flex-wrap items-start gap-3">
            @can('create', \App\Models\Folder::class)
                <button type="button" wire:click="open('new-folder')"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    New folder
                </button>
            @endcan

            @if ($canWriteHere)
                <form wire:submit="uploadFile" class="flex flex-wrap items-start gap-2">
                    <div>
                        <label for="upload" class="sr-only">File</label>
                        <input id="upload" wire:model="upload" type="file"
                               class="block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
                        <p class="mt-1 text-xs text-slate-500">
                            Up to {{ $maxUploadMb }} MB. PDF, images, Office documents, text, ZIP.
                        </p>
                        @error('upload') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" wire:loading.attr="disabled"
                            class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:opacity-50">
                        <span wire:loading.remove wire:target="uploadFile,upload">Upload</span>
                        <span wire:loading wire:target="uploadFile,upload">Uploading…</span>
                    </button>
                </form>
            @elseif ($current)
                <p class="text-sm text-slate-500">
                    This folder belongs to {{ $current->department?->displayName() }}. You can read it, not change it.
                </p>
            @endif
        </div>
    @endif

    {{-- Panels --}}
    @if (in_array($panel, ['new-folder', 'rename-folder', 'visibility', 'rename-file', 'move', 'version'], true))
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            @if ($panel === 'new-folder' || $panel === 'rename-folder')
                <form wire:submit="{{ $panel === 'new-folder' ? 'createFolder' : 'renameFolder' }}" class="space-y-4">
                    <h2 class="text-base font-semibold text-slate-900">
                        {{ $panel === 'new-folder' ? 'New folder' : 'Rename folder' }}
                    </h2>

                    <div class="max-w-sm">
                        <label for="formName" class="block text-sm font-medium text-slate-700">Name</label>
                        <input id="formName" wire:model="formName" type="text"
                               class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @error('formName') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    @if ($panel === 'new-folder')
                        <div class="max-w-sm">
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

                    <div class="max-w-sm">
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
                    <div class="max-w-sm">
                        <label for="fileName" class="block text-sm font-medium text-slate-700">Name</label>
                        <input id="fileName" wire:model="formName" type="text"
                               class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        <p class="mt-1 text-xs text-slate-500">Every version of this file carries the same name.</p>
                        @error('formName') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <x-drive.panel-buttons label="Rename" />
                </form>
            @endif

            @if ($panel === 'move')
                <form wire:submit="moveFile" class="space-y-4">
                    <h2 class="text-base font-semibold text-slate-900">Move to another folder</h2>
                    <div class="max-w-sm">
                        <label for="moveToId" class="block text-sm font-medium text-slate-700">Destination</label>
                        <select id="moveToId" wire:model="moveToId"
                                class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                            <option value="">Choose a folder…</option>
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
    @endif

    {{-- Folders --}}
    @if ($folders->isNotEmpty())
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($folders as $folder)
                <div wire:key="folder-{{ $folder->id }}"
                     class="flex items-start justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 transition hover:border-slate-300">
                    <button type="button" wire:click="openFolder({{ $folder->id }})" class="min-w-0 text-left">
                        <span class="block truncate font-medium text-slate-900">{{ $folder->name }}</span>
                        <span class="mt-0.5 block text-xs text-slate-500">
                            {{ $folder->files_count }} file{{ $folder->files_count === 1 ? '' : 's' }}
                            @if ($view === 'shared') · {{ $folder->department?->displayName() }} @endif
                        </span>
                    </button>

                    <div class="flex shrink-0 flex-col items-end gap-1">
                        <x-status-badge :tone="$folder->visibility->tone()" :label="$folder->visibility->label()" />

                        @if ($folder->is_system)
                            <span class="text-xs text-slate-400">System</span>
                        @else
                            <div class="flex gap-2 text-xs">
                                @can('update', $folder)
                                    <button type="button" wire:click="open('rename-folder', {{ $folder->id }})"
                                            class="font-medium text-slate-600 hover:underline">Rename</button>
                                    <button type="button" wire:click="open('visibility', {{ $folder->id }})"
                                            class="font-medium text-slate-600 hover:underline">Sharing</button>
                                @endcan
                                @can('delete', $folder)
                                    <button type="button" wire:click="deleteFolder({{ $folder->id }})"
                                            class="font-medium text-red-700 hover:underline">Delete</button>
                                @endcan
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Files --}}
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Name</th>
                    <th scope="col" class="px-4 py-3">Kind</th>
                    <th scope="col" class="px-4 py-3">Size</th>
                    <th scope="col" class="px-4 py-3">Last changed</th>
                    <th scope="col" class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($files as $file)
                    <tr wire:key="file-{{ $file->id }}" class="hover:bg-slate-50">
                        <td class="px-4 py-3 align-top">
                            <span class="font-medium text-slate-900">{{ $file->name }}</span>
                            <span class="mt-0.5 block text-xs text-slate-500">
                                {{ $file->original_name }}
                                @if ($file->hasOlderVersions())
                                    · version {{ $file->version_no }}
                                @endif
                                @if ($search !== '' || $view === 'trash')
                                    · in {{ $file->folder?->name }}
                                @endif
                            </span>
                        </td>

                        <td class="px-4 py-3 align-top text-slate-600">{{ $file->kindLabel() }}</td>
                        <td class="whitespace-nowrap px-4 py-3 align-top text-slate-600">{{ $file->humanSize() }}</td>

                        <td class="whitespace-nowrap px-4 py-3 align-top text-slate-600">
                            {{ ph_date($file->updated_at) }}
                            <span class="block text-xs text-slate-500">{{ $file->uploader?->name }}</span>
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right align-top">
                            @if ($view === 'trash')
                                @can('restore', $file)
                                    <button type="button" wire:click="restoreFile({{ $file->id }})"
                                            class="text-sm font-medium text-blue-700 hover:underline">Restore</button>
                                @endcan
                                @can('forceDelete', $file)
                                    <button type="button" wire:click="purgeFile({{ $file->id }})"
                                            wire:confirm="Destroy this file and every version of it? This cannot be undone."
                                            class="ml-3 text-sm font-medium text-red-700 hover:underline">Destroy</button>
                                @endcan
                            @else
                                @if ($file->isPreviewable())
                                    <a href="{{ route('files.preview', $file) }}" target="_blank" rel="noopener"
                                       class="text-sm font-medium text-blue-700 hover:underline">View</a>
                                @endif
                                <a href="{{ route('files.download', $file) }}"
                                   class="ml-3 text-sm font-medium text-blue-700 hover:underline">Download</a>

                                @can('update', $file)
                                    <button type="button" wire:click="open('version', {{ $file->id }})"
                                            class="ml-3 text-sm font-medium text-slate-600 hover:underline">New version</button>
                                    <button type="button" wire:click="open('rename-file', {{ $file->id }})"
                                            class="ml-3 text-sm font-medium text-slate-600 hover:underline">Rename</button>
                                    <button type="button" wire:click="open('move', {{ $file->id }})"
                                            class="ml-3 text-sm font-medium text-slate-600 hover:underline">Move</button>
                                @endcan
                                @can('delete', $file)
                                    <button type="button" wire:click="trashFile({{ $file->id }})"
                                            class="ml-3 text-sm font-medium text-red-700 hover:underline">Delete</button>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                            @if ($view === 'trash')
                                The trash is empty.
                            @elseif ($search !== '')
                                Nothing matches that search.
                            @elseif ($current)
                                This folder is empty.
                            @else
                                Open a folder to see what is in it.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $files->links() }}
</div>
