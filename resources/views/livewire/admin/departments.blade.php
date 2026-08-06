<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Offices</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Every office documents can move between. Offices that are not onboarded are still
                valid destinations — their receipts are logged manually until they start using the system.
            </p>
        </div>

        <button wire:click="create"
                class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
            Add office
        </button>
    </div>

    @error('onboard')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    {{-- Form --}}
    @if ($editingId !== null)
        <form wire:submit="save" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">
                {{ $editingId ? 'Edit office' : 'New office' }}
            </h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="code" class="block text-sm font-medium text-slate-700">Code</label>
                    <input id="code" wire:model="code" type="text"
                           class="mt-1 block w-full rounded-lg border-slate-300 uppercase shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    <p class="mt-1 text-xs text-slate-500">
                        Appears in tracking numbers. Do not change it once this office has registered documents.
                    </p>
                    @error('code') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="short_name" class="block text-sm font-medium text-slate-700">Short name</label>
                    <input id="short_name" wire:model="short_name" type="text" placeholder="Mayor's Office"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('short_name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="name" class="block text-sm font-medium text-slate-700">Full name</label>
                    <input id="name" wire:model="name" type="text" placeholder="Office of the Municipal Mayor"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model.live="is_external"
                           class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-600">
                    <span>
                        <span class="font-medium text-slate-800">External party</span>
                        <span class="block text-slate-500">
                            Provincial, national, barangay or private. Never gets accounts.
                        </span>
                    </span>
                </label>

                <label class="flex items-start gap-3 text-sm" @disabled($is_external)>
                    <input type="checkbox" wire:model="is_onboarded" @disabled($is_external)
                           class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-600 disabled:opacity-40">
                    <span @class(['opacity-40' => $is_external])>
                        <span class="font-medium text-slate-800">Onboarded</span>
                        <span class="block text-slate-500">
                            Staff sign in and receive documents digitally.
                        </span>
                    </span>
                </label>
            </div>

            <div class="mt-6 flex gap-3">
                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                    Save office
                </button>
                <button type="button" wire:click="resetForm"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3">
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search offices…"
               class="w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600 sm:w-72">

        <select wire:model.live="filter"
                class="rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
            <option value="internal">Municipal offices</option>
            <option value="onboarded">Onboarded only</option>
            <option value="external">External parties</option>
            <option value="all">All</option>
        </select>
    </div>

    {{-- Table --}}
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Code</th>
                    <th scope="col" class="px-4 py-3">Office</th>
                    <th scope="col" class="px-4 py-3">Staff</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($departments as $department)
                    <tr wire:key="dept-{{ $department->id }}" class="hover:bg-slate-50">
                        <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-semibold text-slate-700">
                            {{ $department->code }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="font-medium text-slate-900">{{ $department->displayName() }}</span>
                            @if ($department->short_name)
                                <span class="block text-xs text-slate-500">{{ $department->name }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $department->users_count }}</td>
                        <td class="px-4 py-3">
                            @if ($department->is_external)
                                <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">
                                    External
                                </span>
                            @elseif ($department->is_onboarded)
                                <span class="inline-flex rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                                    Onboarded
                                </span>
                            @else
                                <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">
                                    Manual receipt
                                </span>
                            @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button wire:click="edit({{ $department->id }})"
                                    class="text-sm font-medium text-blue-700 hover:underline">
                                Edit
                            </button>
                            @unless ($department->is_external)
                                <button wire:click="toggleOnboarded({{ $department->id }})"
                                        class="ml-3 text-sm font-medium text-slate-600 hover:underline">
                                    {{ $department->is_onboarded ? 'Offboard' : 'Onboard' }}
                                </button>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                            No offices match that search.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $departments->links() }}
</div>
