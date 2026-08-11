<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Notices</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Saving writes a draft. Nothing reaches the public page until you publish it.
            </p>
        </div>

        <button wire:click="create"
                class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
            Write a notice
        </button>
    </div>

    @error('publication')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    {{-- Editor --}}
    @if ($editingId !== null)
        <form wire:submit="save" class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">
                {{ $editingId ? 'Edit notice' : 'New notice' }}
            </h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="title" class="block text-sm font-medium text-slate-700">Title</label>
                    <input id="title" wire:model="title" type="text"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('title') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="category" class="block text-sm font-medium text-slate-700">Kind</label>
                    <select id="category" wire:model="category"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($categories as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="expires_at" class="block text-sm font-medium text-slate-700">
                        Applies until <span class="font-normal text-slate-500">(optional)</span>
                    </label>
                    <input id="expires_at" wire:model="expires_at" type="datetime-local"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    <p class="mt-1 text-xs text-slate-500">For a notice that stops being true, like a suspension.</p>
                    @error('expires_at') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="excerpt" class="block text-sm font-medium text-slate-700">
                        Short summary <span class="font-normal text-slate-500">(optional)</span>
                    </label>
                    <input id="excerpt" wire:model="excerpt" type="text" maxlength="400"
                           placeholder="Shown in listings. Leave blank to use the start of the notice."
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('excerpt') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="body" class="block text-sm font-medium text-slate-700">Notice</label>
                    <textarea id="body" wire:model="body" rows="8"
                              class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600"></textarea>
                    <p class="mt-1 text-xs text-slate-500">
                        Plain text. Leave a blank line between paragraphs.
                    </p>
                    @error('body') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-start gap-3 text-sm sm:col-span-2">
                    <input type="checkbox" wire:model="is_pinned"
                           class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-600">
                    <span>
                        <span class="font-medium text-slate-800">Pin to the top of the public page</span>
                        <span class="block text-slate-500">Use sparingly — pinning everything pins nothing.</span>
                    </span>
                </label>
            </div>

            <div class="flex gap-3">
                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                    Save
                </button>
                <button type="button" wire:click="resetForm"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    {{-- Confirmation --}}
    @if ($confirming)
        <div class="rounded-xl border-2 border-amber-400 bg-amber-50 p-6">
            @if ($confirmAction === 'publish')
                <h2 class="text-base font-semibold text-amber-900">Publish to the public page</h2>
                <p class="mt-2 text-sm text-amber-900">
                    This puts <strong>“{{ $confirming->title }}”</strong> where anyone visiting the
                    municipality's website can read it, immediately. There is no undo that erases
                    what was read — taking it down again only stops it being shown from that point on.
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
                <h2 class="text-base font-semibold text-amber-900">Take down from the public page</h2>
                <p class="mt-2 text-sm text-amber-900">
                    <strong>“{{ $confirming->title }}”</strong> will no longer appear on the public page.
                </p>

                <div class="mt-4">
                    <label for="reason" class="block text-sm font-medium text-amber-900">Why</label>
                    <textarea id="reason" wire:model="reason" rows="2"
                              class="mt-1 block w-full rounded-lg border-amber-300 shadow-sm focus:border-amber-600 focus:ring-amber-600"></textarea>
                    @error('reason') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div class="mt-4 flex gap-3">
                    <button type="button" wire:click="withdraw"
                            class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-800">
                        Take it down
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
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search notices…"
               class="w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600 sm:w-72">

        <select wire:model.live="filter"
                class="rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
            <option value="all">All</option>
            <option value="live">On the public page</option>
            <option value="draft">Drafts</option>
        </select>
    </div>

    {{-- List --}}
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Title</th>
                    <th scope="col" class="px-4 py-3">Kind</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($announcements as $announcement)
                    <tr wire:key="ann-{{ $announcement->id }}" class="hover:bg-slate-50">
                        <td class="px-4 py-3 align-top">
                            <span class="font-medium text-slate-900">{{ $announcement->title }}</span>
                            @if ($announcement->is_pinned)
                                <x-status-badge tone="amber" label="Pinned" class="ml-2" />
                            @endif
                            <span class="mt-0.5 block text-xs text-slate-500">
                                {{ $announcement->published_at ? 'Posted '.ph_date($announcement->published_at) : 'Not published' }}
                            </span>
                        </td>

                        <td class="px-4 py-3 align-top text-slate-600">{{ $announcement->category->label() }}</td>

                        <td class="px-4 py-3 align-top">
                            <x-status-badge :tone="$announcement->statusTone()" :label="$announcement->statusLabel()" />
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 text-right align-top">
                            <button wire:click="edit({{ $announcement->id }})"
                                    class="text-sm font-medium text-blue-700 hover:underline">
                                Edit
                            </button>

                            @if ($canPublish)
                                @if ($announcement->isLive())
                                    <button wire:click="confirm('withdraw', {{ $announcement->id }})"
                                            class="ml-3 text-sm font-medium text-red-700 hover:underline">
                                        Take down
                                    </button>
                                @else
                                    <button wire:click="confirm('publish', {{ $announcement->id }})"
                                            class="ml-3 text-sm font-medium text-green-700 hover:underline">
                                        Publish
                                    </button>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-10 text-center text-slate-500">No notices yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $announcements->links() }}
</div>
