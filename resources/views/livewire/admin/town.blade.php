<div class="space-y-6">

    <div>
        <h1 class="text-2xl font-semibold text-slate-900">The town</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-600">
            The welcome page and the compound are drawings. These are the photographs behind them:
            click a landmark or an office out there and this is what opens. Anything here is public
            the moment it is added — there is no separate publish step, because a picture of the
            covered court is not a record.
        </p>
    </div>

    @if (session('status'))
        <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('status') }}</div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">

        {{-- Which place. Both lists come from the classes the two screens are
             drawn from, so anything added to either appears here on its own. --}}
        <nav class="space-y-4" aria-label="Places">
            @foreach ([['In town', $town], ['In the compound', $offices]] as [$group, $items])
                @if ($items)
                    <div class="space-y-1">
                        <h2 class="px-3 text-xs font-semibold uppercase tracking-wider text-slate-500">
                            {{ $group }}
                        </h2>

                        @foreach ($items as $id => $name)
                            <button wire:click="$set('landmark', '{{ $id }}')"
                                    @class([
                                        'flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left text-sm transition',
                                        'bg-blue-700 font-semibold text-white' => $landmark === $id,
                                        'text-slate-700 hover:bg-slate-100' => $landmark !== $id,
                                    ])
                                    @if ($landmark === $id) aria-current="true" @endif>
                                <span class="truncate">{{ $name }}</span>
                                <span @class([
                                    'shrink-0 rounded-full px-2 py-0.5 text-xs font-mono',
                                    'bg-blue-600 text-blue-50' => $landmark === $id,
                                    'bg-slate-200 text-slate-600' => $landmark !== $id,
                                ])>{{ $counts[$id] ?? 0 }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </nav>

        <div class="space-y-6">

            {{-- Adding --}}
            <form wire:submit="add" class="space-y-4 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">Add a photograph of {{ $landmarkName }}</h2>

                <div>
                    <label for="townUpload" class="block text-sm font-medium text-slate-700">Photograph</label>
                    <input id="townUpload" wire:model="upload" type="file" accept="image/jpeg,image/png,image/webp"
                           class="mt-1 block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-slate-700 hover:file:bg-slate-200">
                    <p class="mt-1 text-xs text-slate-500">
                        JPEG, PNG or WebP, up to {{ config('drive.max_upload_mb', 50) }} MB. Landscape
                        shots sit best in the frame — it is cropped to 16:10.
                    </p>
                    @error('upload') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="townCaption" class="block text-sm font-medium text-slate-700">
                        Caption <span class="font-normal text-slate-500">(optional)</span>
                    </label>
                    <input id="townCaption" wire:model="caption" type="text" maxlength="200"
                           placeholder="The court after the 2025 repainting"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('caption') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div wire:loading wire:target="upload" class="text-sm text-slate-500">Uploading…</div>

                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:opacity-50"
                        wire:loading.attr="disabled" wire:target="upload,add">
                    Add to the public page
                </button>
            </form>

            {{-- What is already there, in the order the carousel shows it --}}
            <div class="rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 px-6 py-4">
                    <h2 class="text-base font-semibold text-slate-900">
                        On {{ $landmarkName }} now
                    </h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Shown in this order. The first one is what a visitor sees before they press
                        anything.
                    </p>
                </div>

                @forelse ($photos as $i => $photo)
                    <div class="flex flex-wrap items-start gap-4 border-b border-slate-100 px-6 py-4 last:border-0">
                        <img src="{{ route('public.photo', $photo) }}"
                             alt="{{ $photo->caption ?: 'Photograph '.($i + 1) }}"
                             class="h-20 w-32 shrink-0 rounded-lg bg-slate-100 object-cover">

                        <div class="min-w-0 flex-1">
                            @if ($editingId === $photo->id)
                                <form wire:submit="saveCaption" class="flex flex-wrap items-center gap-2">
                                    <input wire:model="editingCaption" type="text" maxlength="200"
                                           class="min-w-0 flex-1 rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600">
                                    <button type="submit" class="rounded-lg bg-blue-700 px-3 py-1.5 text-sm font-semibold text-white">Save</button>
                                    <button type="button" wire:click="cancelCaption" class="text-sm text-slate-600 hover:text-slate-900">Cancel</button>
                                    @error('editingCaption') <p class="w-full text-sm text-red-700">{{ $message }}</p> @enderror
                                </form>
                            @else
                                <p class="truncate text-sm font-medium text-slate-900">
                                    {{ $photo->caption ?: 'No caption' }}
                                </p>
                                <p class="mt-1 font-mono text-xs text-slate-500">
                                    {{ $photo->file?->name }} · {{ $photo->file?->humanSize() }}
                                </p>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-1">
                            <button wire:click="move({{ $photo->id }}, -1)" @disabled($loop->first)
                                    class="rounded-lg px-2 py-1 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 disabled:opacity-30"
                                    aria-label="Move earlier">&uarr;</button>
                            <button wire:click="move({{ $photo->id }}, 1)" @disabled($loop->last)
                                    class="rounded-lg px-2 py-1 text-slate-500 transition hover:bg-slate-100 hover:text-slate-900 disabled:opacity-30"
                                    aria-label="Move later">&darr;</button>
                            <button wire:click="editCaption({{ $photo->id }})"
                                    class="rounded-lg px-2 py-1 text-sm text-slate-600 transition hover:bg-slate-100 hover:text-slate-900">Caption</button>
                            <button wire:click="remove({{ $photo->id }})"
                                    wire:confirm="Take this photograph off the public page? The file stays in the drive."
                                    class="rounded-lg px-2 py-1 text-sm text-red-700 transition hover:bg-red-50">Remove</button>
                        </div>
                    </div>
                @empty
                    <p class="px-6 py-10 text-center text-sm text-slate-500">
                        Nothing yet. Until a photograph is added, clicking {{ $landmarkName }} on the
                        welcome page opens the panel and says so.
                    </p>
                @endforelse
            </div>
        </div>
    </div>
</div>
