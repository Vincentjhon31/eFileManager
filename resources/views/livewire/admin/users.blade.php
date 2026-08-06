<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Users</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Accounts are created here — there is no self-registration. Staff who leave are
                deactivated rather than deleted, so the document trail they appear in stays intact.
            </p>
        </div>

        <button wire:click="create"
                class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
            Add user
        </button>
    </div>

    @error('active')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    {{-- Shown once, immediately after creation or reset. Deliberately not
         emailed: the administrator hands it over and the employee changes it. --}}
    @if ($generatedPassword)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-5">
            <h2 class="text-sm font-semibold text-amber-900">Temporary password — shown once</h2>
            <p class="mt-2 font-mono text-lg font-semibold tracking-wider text-amber-950">
                {{ $generatedPassword }}
            </p>
            <p class="mt-2 text-sm text-amber-800">
                Write this down and give it to the employee in person. It cannot be shown again,
                and it is never stored in readable form. Ask them to change it after signing in.
            </p>
            <button wire:click="$set('generatedPassword', null)"
                    class="mt-3 text-sm font-medium text-amber-900 underline">
                I have recorded it
            </button>
        </div>
    @endif

    {{-- Form --}}
    @if ($editingId !== null)
        <form wire:submit="save" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">
                {{ $editingId ? 'Edit user' : 'New user' }}
            </h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="name" class="block text-sm font-medium text-slate-700">Full name</label>
                    <input id="name" wire:model="name" type="text"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="employee_no" class="block text-sm font-medium text-slate-700">
                        Employee number <span class="font-normal text-slate-500">(optional)</span>
                    </label>
                    <input id="employee_no" wire:model="employee_no" type="text"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('employee_no') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-slate-700">Email address</label>
                    <input id="email" wire:model="email" type="email"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('email') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="position" class="block text-sm font-medium text-slate-700">
                        Position <span class="font-normal text-slate-500">(optional)</span>
                    </label>
                    <input id="position" wire:model="position" type="text" placeholder="Administrative Officer IV"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('position') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="department_id" class="block text-sm font-medium text-slate-700">Office</label>
                    <select id="department_id" wire:model="department_id" @disabled(! $this->canManageAllUsers())
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600 disabled:bg-slate-100">
                        <option value="">Select an office…</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}">{{ $department->displayName() }}</option>
                        @endforeach
                    </select>
                    @unless ($this->canManageAllUsers())
                        <p class="mt-1 text-xs text-slate-500">
                            You may only manage accounts within your own office.
                        </p>
                    @endunless
                    @error('department_id') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="role" class="block text-sm font-medium text-slate-700">Role</label>
                    <select id="role" wire:model="role"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($roles as $roleOption)
                            @if (in_array($roleOption->value, $this->assignableRoles(), true))
                                <option value="{{ $roleOption->value }}">{{ $roleOption->label() }}</option>
                            @endif
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ collect($roles)->firstWhere('value', $role)?->description() }}
                    </p>
                    @error('role') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-6 flex gap-3">
                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                    Save user
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
        <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search name, email or employee no…"
               class="w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600 sm:w-80">

        @if ($this->canManageAllUsers())
            <select wire:model.live="departmentFilter"
                    class="rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                <option value="">All offices</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}">{{ $department->displayName() }}</option>
                @endforeach
            </select>
        @endif
    </div>

    {{-- Table --}}
    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Name</th>
                    <th scope="col" class="px-4 py-3">Office</th>
                    <th scope="col" class="px-4 py-3">Role</th>
                    <th scope="col" class="px-4 py-3">Last signed in</th>
                    <th scope="col" class="px-4 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($users as $user)
                    <tr wire:key="user-{{ $user->id }}" @class(['hover:bg-slate-50', 'opacity-60' => ! $user->is_active])>
                        <td class="px-4 py-3">
                            <span class="font-medium text-slate-900">{{ $user->name }}</span>
                            <span class="block text-xs text-slate-500">{{ $user->email }}</span>
                            @unless ($user->is_active)
                                <span class="mt-1 inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-800">
                                    Deactivated
                                </span>
                            @endunless
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ $user->department?->displayName() ?? '—' }}
                            @if ($user->position)
                                <span class="block text-xs text-slate-500">{{ $user->position }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-slate-600">
                            {{ $user->roles->first()?->name ?? '—' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                            {{ ph_datetime($user->last_login_at) ?? 'Never' }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <button wire:click="edit({{ $user->id }})"
                                    class="text-sm font-medium text-blue-700 hover:underline">
                                Edit
                            </button>
                            <button wire:click="resetPassword({{ $user->id }})"
                                    wire:confirm="Generate a new password for {{ $user->name }}? Their current password stops working immediately."
                                    class="ml-3 text-sm font-medium text-slate-600 hover:underline">
                                Reset password
                            </button>
                            <button wire:click="toggleActive({{ $user->id }})"
                                    class="ml-3 text-sm font-medium text-slate-600 hover:underline">
                                {{ $user->is_active ? 'Deactivate' : 'Reactivate' }}
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                            No users match that search.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $users->links() }}
</div>
