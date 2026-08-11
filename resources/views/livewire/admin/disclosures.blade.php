<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Disclosure board</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Preparing an entry does not make it public. Check the file, then publish it as a
                separate step.
            </p>
        </div>

        <button wire:click="prepare"
                class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
            Prepare a document
        </button>
    </div>

    @error('publication')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    {{-- Step one: prepare --}}
    @if ($preparing)
        <form wire:submit="savePreparation" class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">Prepare a document for disclosure</h2>
            <p class="text-sm text-slate-600">
                Choose from files already in your office's drive. This does not publish anything yet.
            </p>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="file_id" class="block text-sm font-medium text-slate-700">File</label>
                    <select id="file_id" wire:model.live="file_id"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        <option value="">Choose a file…</option>
                        @foreach ($candidates as $file)
                            <option value="{{ $file->id }}">
                                {{ $file->name }} — {{ $file->folder?->name }} ({{ $file->humanSize() }})
                            </option>
                        @endforeach
                    </select>
                    @error('file_id') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="disclosureTitle" class="block text-sm font-medium text-slate-700">
                        Public title
                    </label>
                    <input id="disclosureTitle" wire:model="title" type="text"
                           placeholder="2026 Annual Budget"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    <p class="mt-1 text-xs text-slate-500">What the public sees. The file's own name is often unhelpful.</p>
                    @error('title') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="disclosureCategory" class="block text-sm font-medium text-slate-700">Shelf</label>
                    <select id="disclosureCategory" wire:model="category"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($categories as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                    @error('category') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="fiscal_year" class="block text-sm font-medium text-slate-700">
                        Fiscal year <span class="font-normal text-slate-500">(optional)</span>
                    </label>
                    <input id="fiscal_year" wire:model="fiscal_year" type="number" min="1900" max="2200"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('fiscal_year') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="disclosureDescription" class="block text-sm font-medium text-slate-700">
                        Note <span class="font-normal text-slate-500">(optional)</span>
                    </label>
                    <input id="disclosureDescription" wire:model="description" type="text" maxlength="500"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('description') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="flex gap-3">
                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                    Prepare
                </button>
                <button type="button" wire:click="cancelPreparation"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    {{-- Step two: confirmation --}}
    @if ($confirming)
        <div class="rounded-xl border-2 border-amber-400 bg-amber-50 p-6">
            @if ($confirmAction === 'publish')
                <h2 class="text-base font-semibold text-amber-900">Publish to the disclosure board</h2>
                <p class="mt-2 text-sm text-amber-900">
                    This makes <strong>“{{ $confirming->title }}”</strong> downloadable by anyone
                    visiting the municipality's website, without signing in, immediately.
                </p>

                <div class="mt-4 flex gap-3">
                    <button type="button" wire:click="publish"
                            class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700">
                        Yes, publish it now
                    </button>
                    <button type="button" wire:click="cancelConfirmation"
                            class="rounded-lg border border-amber-300 px-4 py-2 text-sm font-medium text-amber-900 transition hover:bg-amber-100">
                        Cancel
                    </button>
                </div>
            @else
                <h2 class="text-base font-semibold text-amber-900">Withdraw from the disclosure board</h2>
                <p class="mt-2 text-sm text-amber-900">
                    <strong>“{{ $confirming->title }}”</strong> has been downloaded
                    {{ $confirming->download_count }} time(s). It will stop being reachable from this point on.
                </p>

                <div class="mt-4">
                    <label for="withdrawReason" class="block text-sm font-medium text-amber-900">Why</label>
                    <textarea id="withdrawReason" wire:model="reason" rows="2"
                              class="mt-1 block w-full rounded-lg border-amber-300 shadow-sm focus:border-amber-600 focus:ring-amber-600"></textarea>
                    @error('reason') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div class="mt-4 flex gap-3">
                    <button type="button" wire:click="withdraw"
                            class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-800">
                        Withdraw it
                    </button>
                    <button type="button" wire:click="cancelConfirmation"
                            class="rounded-lg border border-amber-300 px-4 py-2 text-sm font-medium text-amber-900 transition hover:bg-amber-100">
                        Cancel
                    </button>
                </div>
            @endif
        </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search…"
               class="w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600 sm:w-72">

        <select wire:model.live="filter"
                class="rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
            <option value="all">All</option>
            <option value="published">Published</option>
            <option value="prepared">Prepared, not yet published</option>
        </select>
    </div>

    {{-- List --}}
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Title</th>
                    <th scope="col" class="px-4 py-3">Shelf</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">Downloads</th>
                    <th scope="col" class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($entries as $entry)
                    <tr wire:key="pf-{{ $entry->id }}" class="hover:bg-slate-50">
                        <td class="px-4 py-3 align-top">
                            <span class="font-medium text-slate-900">{{ $entry->title }}</span>
                            <span class="mt-0.5 block text-xs text-slate-500">
                                {{ $entry->file?->kindLabel() }} · {{ $entry->file?->humanSize() }}
                            </span>
                        </td>

                        <td class="px-4 py-3 align-top text-slate-600">{{ $entry->shelfLabel() }}</td>

                        <td class="px-4 py-3 align-top">
                            <x-status-badge :tone="$entry->statusTone()" :label="$entry->statusLabel()" />
                        </td>

                        <td class="px-4 py-3 align-top text-slate-600">{{ $entry->download_count }}</td>

                        <td class="whitespace-nowrap px-4 py-3 text-right align-top">
                            @if ($entry->isLive())
                                <button wire:click="confirm('withdraw', {{ $entry->id }})"
                                        class="text-sm font-medium text-red-700 hover:underline">
                                    Withdraw
                                </button>
                            @else
                                <button wire:click="confirm('publish', {{ $entry->id }})"
                                        class="text-sm font-medium text-green-700 hover:underline">
                                    Publish
                                </button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                            Nothing has been prepared yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $entries->links() }}
</div>
