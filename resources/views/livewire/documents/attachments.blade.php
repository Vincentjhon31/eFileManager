<div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
    <h2 class="text-base font-semibold text-slate-900">Scans and attachments</h2>
    <p class="mt-1 text-sm text-slate-600">
        Attached files are kept in your office's drive with their own version history.
        Removing one here does not delete it there.
    </p>

    @if ($files->isNotEmpty())
        <ul class="mt-4 divide-y divide-slate-100 border-y border-slate-100">
            @foreach ($files as $file)
                <li wire:key="attachment-{{ $file->id }}" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <span class="font-medium text-slate-900">{{ $file->name }}</span>
                        @if ($file->pivot->kind === 'main')
                            <x-status-badge tone="blue" label="The document" class="ml-2" />
                        @endif
                        <span class="mt-0.5 block text-xs text-slate-500">
                            {{ $file->kindLabel() }} · {{ $file->humanSize() }}
                            @if ($file->hasOlderVersions()) · version {{ $file->version_no }} @endif
                            · {{ $file->uploader?->name ?? 'Account removed' }}
                        </span>
                    </div>

                    <div class="flex shrink-0 items-center gap-3">
                        @if ($file->isPreviewable())
                            <a href="{{ route('files.preview', $file) }}" target="_blank" rel="noopener"
                               class="text-sm font-medium text-blue-700 hover:underline">View</a>
                        @endif
                        <a href="{{ route('files.download', $file) }}"
                           class="text-sm font-medium text-blue-700 hover:underline">Download</a>
                        @if ($canAttach)
                            <button type="button" wire:click="detach({{ $file->id }})"
                                    class="text-sm font-medium text-slate-600 hover:underline">Detach</button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-500">
            Nothing attached yet. The paper is still being tracked either way — attaching the scan
            just means the next office can read it before the folder arrives.
        </p>
    @endif

    @if ($canAttach)
        <form wire:submit="attach" class="mt-5 flex flex-wrap items-start gap-3 border-t border-slate-100 pt-5">
            <div>
                <label for="attachment" class="sr-only">File</label>
                <input id="attachment" wire:model="upload" type="file"
                       class="block text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
                <p class="mt-1 text-xs text-slate-500">Up to {{ $maxUploadMb }} MB.</p>
                @error('upload') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            @unless ($hasMain)
                <div>
                    <label for="attachmentKind" class="sr-only">What this is</label>
                    <select id="attachmentKind" wire:model="kind"
                            class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        <option value="attachment">An annex or supporting paper</option>
                        <option value="main">The document itself</option>
                    </select>
                </div>
            @endunless

            <button type="submit" wire:loading.attr="disabled"
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:opacity-50">
                <span wire:loading.remove wire:target="attach,upload">Attach</span>
                <span wire:loading wire:target="attach,upload">Attaching…</span>
            </button>
        </form>
    @endif
</div>
