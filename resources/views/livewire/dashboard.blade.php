<div class="space-y-6">

    <div>
        <h1 class="text-2xl font-semibold text-slate-900">
            Good day, {{ auth()->user()->name }}
        </h1>
        <p class="mt-1 text-sm text-slate-600">
            {{ auth()->user()->position ?: 'Staff' }}
            @if (auth()->user()->department)
                — {{ auth()->user()->department->name }}
            @endif
        </p>
    </div>

    @unless (auth()->user()->department_id)
        <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Your account is not assigned to an office yet. Ask your administrator to assign one
            before you register or receive documents.
        </div>
    @endunless

    {{-- The same four counts that drive My Desk. --}}
    @php
        $tiles = [
            ['To receive', $counts['incoming'], 'incoming', 'amber'],
            ['On your desk', $counts['desk'], 'desk', 'blue'],
            ['Awaiting receipt', $counts['awaiting'], 'awaiting', 'slate'],
            ['Overdue', $counts['overdue'], 'overdue', 'red'],
        ];
    @endphp

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($tiles as [$label, $value, $tab, $tone])
            <a href="{{ route('desk', ['tab' => $tab]) }}" wire:navigate
               class="rounded-xl border border-slate-200 bg-white p-5 transition hover:border-slate-300 hover:shadow-sm">
                <p class="text-sm text-slate-600">{{ $label }}</p>
                <p @class([
                    'mt-2 text-3xl font-semibold',
                    'text-red-700' => $tone === 'red' && $value > 0,
                    'text-slate-900' => ! ($tone === 'red' && $value > 0) && $value > 0,
                    'text-slate-300' => $value === 0,
                ])>{{ $value }}</p>
            </a>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 overflow-hidden rounded-xl border border-slate-200 bg-white">
            <div class="border-b border-slate-200 px-6 py-4">
                <h2 class="text-base font-semibold text-slate-900">Recently moved</h2>
                <p class="mt-1 text-sm text-slate-600">The last documents to pass through your office.</p>
            </div>

            <ul class="divide-y divide-slate-100">
                @forelse ($recent as $document)
                    <li class="px-6 py-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <a href="{{ route('documents.show', $document) }}" wire:navigate
                               class="min-w-0 grow">
                                <span class="font-mono text-xs font-semibold text-blue-700">{{ $document->tracking_no }}</span>
                                <span class="mt-0.5 block truncate text-sm text-slate-800">{{ $document->subject }}</span>
                            </a>
                            <div class="flex items-center gap-2">
                                <x-status-badge :tone="$document->status->tone()" :label="$document->status->label()" />
                                <span class="text-xs text-slate-500">
                                    {{ $document->currentHolderDepartment?->displayName() }}
                                </span>
                            </div>
                        </div>
                    </li>
                @empty
                    <li class="px-6 py-10 text-center text-sm text-slate-500">
                        Nothing yet. Documents appear here once your office registers or receives one.
                    </li>
                @endforelse
            </ul>
        </div>

        <div class="space-y-4">
            @can('create', \App\Models\Document::class)
                <a href="{{ route('documents.register') }}" wire:navigate
                   class="block rounded-xl bg-blue-700 px-5 py-4 text-white transition hover:bg-blue-800">
                    <span class="block text-sm font-semibold">Register a document</span>
                    <span class="mt-0.5 block text-xs text-blue-100">
                        Take a new letter or memorandum into the system and issue its tracking number.
                    </span>
                </a>
            @endcan

            <a href="{{ route('documents.index') }}" wire:navigate
               class="block rounded-xl border border-slate-200 bg-white px-5 py-4 transition hover:border-slate-300">
                <span class="block text-sm font-semibold text-slate-900">Find a document</span>
                <span class="mt-0.5 block text-xs text-slate-600">
                    Search by tracking number, the document's own number, or its subject.
                </span>
            </a>

            @can('departments.manage')
                <a href="{{ route('admin.departments.index') }}" wire:navigate
                   class="block rounded-xl border border-slate-200 bg-white px-5 py-4 transition hover:border-slate-300">
                    <span class="block text-sm font-semibold text-slate-900">Offices</span>
                    <span class="mt-0.5 block text-xs text-slate-600">
                        Onboard an office when its staff are ready to receive digitally.
                    </span>
                </a>
            @endcan
        </div>
    </div>
</div>
