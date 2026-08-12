<x-settings.shell heading="Profile"
                  description="How you appear on routing slips, in the document trail, and to colleagues looking for the right person.">

    <form wire:submit="save" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="name" class="block text-sm font-medium text-slate-700">Full name</label>
                <input id="name" wire:model="name" type="text" autocomplete="name"
                       class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                @error('name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="position" class="block text-sm font-medium text-slate-700">Position</label>
                <input id="position" wire:model="position" type="text" placeholder="Administrative Officer IV"
                       class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                <p class="mt-1 text-xs text-slate-500">Printed beside your name on routing slips.</p>
                @error('position') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="phone" class="block text-sm font-medium text-slate-700">Contact number</label>
                <input id="phone" wire:model="phone" type="tel" autocomplete="tel" placeholder="0917 000 0000"
                       class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                <p class="mt-1 text-xs text-slate-500">So a colleague can ask about a document without walking over.</p>
                @error('phone') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>
        </div>

        <div class="mt-6">
            <button type="submit"
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                Save profile
            </button>
        </div>
    </form>

    {{--
        Set by an administrator, not here. Office and role decide what this
        account may reach, and an account that could move itself between offices
        would undo the whole visibility model.
    --}}
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-900">Set by your administrator</h3>
        <p class="mt-1 text-xs text-slate-500">
            These decide what your account can reach, so they are not yours to change.
            Ask MIS or your office administrator if any of them is wrong.
        </p>

        <dl class="mt-4 grid gap-x-6 gap-y-4 sm:grid-cols-2">
            <div>
                <dt class="text-xs text-slate-500">Email (used to sign in)</dt>
                <dd class="mt-0.5 break-all text-sm text-slate-800">{{ $user->email }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Employee number</dt>
                <dd class="mt-0.5 text-sm text-slate-800">{{ $user->employee_no ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Office</dt>
                <dd class="mt-0.5 text-sm text-slate-800">
                    {{ $user->department?->displayName() ?? 'No office assigned' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Role</dt>
                <dd class="mt-0.5 text-sm text-slate-800">
                    @php($role = $user->roles->first()?->name)
                    {{ $role ? (\App\Enums\Role::tryFrom($role)?->label() ?? $role) : '—' }}
                </dd>
            </div>
        </dl>
    </div>
</x-settings.shell>
