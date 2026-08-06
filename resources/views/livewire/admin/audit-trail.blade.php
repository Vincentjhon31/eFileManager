<div class="space-y-6">

    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Audit trail</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-600">
            An append-only record of activity on this system, kept for RA 10173 compliance.
            Entries cannot be edited or deleted by anyone, including system administrators.
            @unless ($this->canViewAllDepartments())
                You are seeing your own office only.
            @endunless
        </p>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search description, actor or event…"
               class="w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600 sm:w-80">

        <select wire:model.live="eventFilter"
                class="rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
            <option value="">All events</option>
            @foreach ($events as $event)
                <option value="{{ $event }}">{{ $event }}</option>
            @endforeach
        </select>

        @if ($this->canViewAllDepartments() && $departments->isNotEmpty())
            <select wire:model.live="departmentFilter"
                    class="rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                <option value="">All offices</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}">{{ $department->displayName() }}</option>
                @endforeach
            </select>
        @endif
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="px-4 py-3">When</th>
                    <th scope="col" class="px-4 py-3">Who</th>
                    <th scope="col" class="px-4 py-3">Event</th>
                    <th scope="col" class="px-4 py-3">Description</th>
                    <th scope="col" class="px-4 py-3">From</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($logs as $log)
                    <tr wire:key="log-{{ $log->id }}" class="hover:bg-slate-50">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                            {{ ph_datetime($log->created_at) }}
                        </td>
                        <td class="px-4 py-3">
                            {{-- actor_name is the denormalised value captured at the
                                 time of the act, so the trail still reads correctly
                                 after someone transfers office. --}}
                            <span class="font-medium text-slate-900">{{ $log->actor_name ?? 'System' }}</span>
                            @if ($log->department)
                                <span class="block text-xs text-slate-500">{{ $log->department->displayName() }}</span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-700">
                                {{ $log->event }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-700">{{ $log->description }}</td>
                        <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-slate-500">
                            {{ $log->ip_address }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                            No audit entries match those filters.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $logs->links() }}
</div>
