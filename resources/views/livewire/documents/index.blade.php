<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Documents</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Everything that has passed through your office. Search by tracking number,
                the document's own number, or its subject.
            </p>
        </div>

        @can('create', \App\Models\Document::class)
            <a href="{{ route('documents.register') }}" wire:navigate
               class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                Register a document
            </a>
        @endcan
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3">
        <input wire:model.live.debounce.300ms="search" type="search"
               placeholder="BGB-MO-2026-08-0001, Office Order 12, subject…"
               class="w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600 sm:w-96">

        <select wire:model.live="statusFilter"
                class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600">
            <option value="">Any status</option>
            @foreach ($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>

        <select wire:model.live="typeFilter"
                class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600">
            <option value="">Any kind</option>
            @foreach ($types as $type)
                <option value="{{ $type->id }}">{{ $type->name }}</option>
            @endforeach
        </select>

        <select wire:model.live="officeFilter"
                class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600">
            <option value="">Anywhere</option>
            @foreach ($offices as $office)
                <option value="{{ $office->id }}">With {{ $office->displayName() }}</option>
            @endforeach
        </select>

        <label class="inline-flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" wire:model.live="overdueOnly"
                   class="rounded border-slate-300 text-blue-700 focus:ring-blue-600">
            Overdue only
        </label>

        <button type="button" wire:click="clearFilters"
                class="text-sm font-medium text-slate-600 hover:underline">
            Clear
        </button>
    </div>

    {{-- Table --}}
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Tracking no.</th>
                    <th scope="col" class="px-4 py-3">Subject</th>
                    <th scope="col" class="px-4 py-3">From</th>
                    <th scope="col" class="px-4 py-3">Where it is</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">Due</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($documents as $document)
                    <tr wire:key="doc-{{ $document->id }}" class="hover:bg-slate-50">
                        <td class="whitespace-nowrap px-4 py-3 align-top">
                            <a href="{{ route('documents.show', $document) }}" wire:navigate
                               class="font-mono text-xs font-semibold text-blue-700 hover:underline">
                                {{ $document->tracking_no }}
                            </a>
                            <span class="mt-0.5 block text-xs text-slate-500">{{ $document->type?->name }}</span>
                        </td>

                        <td class="px-4 py-3 align-top">
                            <span class="font-medium text-slate-900">{{ $document->subject }}</span>
                            @if ($document->reference_no)
                                <span class="mt-0.5 block text-xs text-slate-500">{{ $document->reference_no }}</span>
                            @endif
                            @if ($document->isConfidential())
                                <x-status-badge tone="red" label="Confidential" class="mt-1" />
                            @endif
                        </td>

                        <td class="px-4 py-3 align-top text-slate-600">{{ $document->originLabel() }}</td>

                        <td class="px-4 py-3 align-top text-slate-600">
                            {{ $document->currentHolderDepartment?->displayName() ?? '—' }}
                            @if ($document->currentHolderUser)
                                <span class="block text-xs text-slate-500">{{ $document->currentHolderUser->name }}</span>
                            @endif
                        </td>

                        <td class="px-4 py-3 align-top">
                            <x-status-badge :tone="$document->status->tone()" :label="$document->status->label()" />
                        </td>

                        <td class="whitespace-nowrap px-4 py-3 align-top">
                            @if ($document->due_at)
                                <span @class(['text-red-700 font-medium' => $document->isOverdue(), 'text-slate-600' => ! $document->isOverdue()])>
                                    {{ ph_date($document->due_at) }}
                                </span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                            Nothing matches. Documents appear here once they have passed through your office.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $documents->links() }}
</div>
