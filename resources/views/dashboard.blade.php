<x-layouts.app title="Dashboard">
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

        {{-- Placeholder until the routing engine lands in the next phase. The
             counts these tiles will carry are the same ones that light up the
             doors on the building map. --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['label' => 'Awaiting your action', 'value' => '—'],
                ['label' => 'Incoming to your office', 'value' => '—'],
                ['label' => 'Sent, not yet received', 'value' => '—'],
                ['label' => 'Overdue', 'value' => '—'],
            ] as $tile)
                <div class="rounded-xl border border-slate-200 bg-white p-5">
                    <p class="text-sm text-slate-600">{{ $tile['label'] }}</p>
                    <p class="mt-2 text-3xl font-semibold text-slate-400">{{ $tile['value'] }}</p>
                </div>
            @endforeach
        </div>

        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-6">
            <h2 class="text-base font-semibold text-slate-900">Document tracking is not switched on yet</h2>
            <p class="mt-2 max-w-2xl text-sm text-slate-600">
                Offices, accounts and the audit trail are in place. Registering and routing
                documents arrives in the next phase, followed by QR routing slips, the
                document library, and the building map.
            </p>

            @can('departments.manage')
                <div class="mt-4 flex flex-wrap gap-3">
                    <a href="{{ route('admin.departments.index') }}"
                       class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                        Review offices
                    </a>
                    <a href="{{ route('admin.users.index') }}"
                       class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                        Add staff accounts
                    </a>
                </div>
            @endcan
        </div>
    </div>
</x-layouts.app>
