<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Workspace apps</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                The systems this municipality runs, listed on everyone's
                <a href="{{ route('workspace') }}" wire:navigate class="font-medium text-blue-700 hover:underline">Workspace</a>.
                An app here is a link and who may see it — the system itself lives wherever it already lives.
            </p>
        </div>

        <button wire:click="create"
                class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
            Add app
        </button>
    </div>

    {{-- Form --}}
    @if ($editingId !== null)
        <form wire:submit="save" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">
                {{ $editingId ? 'Edit app' : 'Add an app' }}
            </h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="app_name" class="block text-sm font-medium text-slate-700">Name</label>
                    <input id="app_name" wire:model="name" type="text" placeholder="Business Permit System"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    @error('name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="app_glyph" class="block text-sm font-medium text-slate-700">Badge</label>
                    <input id="app_glyph" wire:model="icon_glyph" type="text" maxlength="2" placeholder="BP"
                           class="mt-1 block w-full rounded-lg border-slate-300 uppercase shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    <p class="mt-1 text-xs text-slate-500">One or two letters, shown on the app's tile.</p>
                    @error('icon_glyph') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="app_url" class="block text-sm font-medium text-slate-700">Web address</label>
                    <input id="app_url" wire:model="url" type="url" placeholder="https://permits.bongabong.gov.ph"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    <p class="mt-1 text-xs text-slate-500">
                        Must start with http:// or https://. Staff will click this from a government system,
                        so check it opens what you expect before saving.
                    </p>
                    @error('url') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2">
                    <label for="app_description" class="block text-sm font-medium text-slate-700">What it is for</label>
                    <textarea id="app_description" wire:model="description" rows="2"
                              placeholder="Where business permit applications are encoded and released."
                              class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600"></textarea>
                    <p class="mt-1 text-xs text-slate-500">One sentence. It is the only clue a new employee gets.</p>
                    @error('description') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="app_scope" class="block text-sm font-medium text-slate-700">Who can see it</label>
                    <select id="app_scope" wire:model.live="scope"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($scopes as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                    @unless ($canPublishWidely)
                        <p class="mt-1 text-xs text-slate-500">
                            Listing an app for every office, or publicly, needs the settings permission. Ask MIS.
                        </p>
                    @endunless
                    @error('scope') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="app_status" class="block text-sm font-medium text-slate-700">Status</label>
                    <select id="app_status" wire:model="status"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($statuses as $option)
                            <option value="{{ $option->value }}">{{ $option->label() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Retired apps stay on this screen and leave the workspace.</p>
                    @error('status') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="app_department" class="block text-sm font-medium text-slate-700">Office that runs it</label>
                    <select id="app_department" wire:model="department_id" @disabled(! $canPublishWidely)
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600 disabled:bg-slate-100">
                        <option value="">— None —</option>
                        @foreach ($departments as $office)
                            <option value="{{ $office->id }}">{{ $office->displayName() }}</option>
                        @endforeach
                    </select>
                    @unless ($canPublishWidely)
                        <p class="mt-1 text-xs text-slate-500">Your own office.</p>
                    @endunless
                    @error('department_id') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="app_order" class="block text-sm font-medium text-slate-700">Order</label>
                    <input id="app_order" wire:model="sort_order" type="number" min="0" max="9999"
                           class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    <p class="mt-1 text-xs text-slate-500">Lower comes first. Ties fall back to the name.</p>
                    @error('sort_order') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-6 flex gap-3">
                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                    {{ $editingId ? 'Save app' : 'Add app' }}
                </button>
                <button type="button" wire:click="resetForm"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="relative">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                 class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-slate-400">
                <circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" />
            </svg>
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Search apps…"
                   class="w-full rounded-lg border-slate-300 py-1.5 pl-8 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600 sm:w-64">
        </div>

        <select wire:model.live="statusFilter"
                class="rounded-lg border-slate-300 py-1.5 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600">
            <option value="">Every status</option>
            @foreach ($statuses as $option)
                <option value="{{ $option->value }}">{{ $option->label() }}</option>
            @endforeach
        </select>
    </div>

    {{-- List --}}
    @if ($apps->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
            <p class="text-sm font-medium text-slate-700">
                @if ($search !== '' || $statusFilter !== '')
                    Nothing matches that.
                @else
                    No apps listed yet.
                @endif
            </p>
            <p class="mt-1 max-w-md text-sm text-slate-500">
                Add the systems your office already runs — the permit system, the payroll portal — so staff
                stop having to remember the addresses.
            </p>
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">App</th>
                        <th scope="col" class="px-4 py-3">Who can see it</th>
                        <th scope="col" class="px-4 py-3">Status</th>
                        <th scope="col" class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($apps as $app)
                        <tr wire:key="app-{{ $app->id }}" class="group hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-blue-700 text-xs font-bold text-white">
                                        {{ $app->icon_glyph ?: mb_strtoupper(mb_substr($app->name, 0, 2)) }}
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate font-medium text-slate-900">{{ $app->name }}</span>
                                        {{-- rel="noopener" and a new tab: this is a link out of the
                                             records system to somewhere it does not control. --}}
                                        <a href="{{ $app->url }}" target="_blank" rel="noopener noreferrer"
                                           class="block truncate text-xs text-blue-700 hover:underline">{{ $app->url }}</a>
                                        @if ($app->description)
                                            <span class="mt-0.5 block truncate text-xs text-slate-500">{{ $app->description }}</span>
                                        @endif
                                    </span>
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <x-status-badge :tone="$app->scope->tone()" :label="$app->scope->label()" />
                                @if ($app->department)
                                    <span class="mt-0.5 block text-xs text-slate-500">{{ $app->department->displayName() }}</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <x-status-badge :tone="$app->status->tone()" :label="$app->status->label()" />
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <button type="button" wire:click="edit({{ $app->id }})"
                                            class="rounded-lg px-2 py-1 text-sm font-medium text-blue-700 hover:bg-blue-50">Edit</button>

                                    <x-dropdown align="right" label="More actions for {{ $app->name }}">
                                        <button type="button" wire:click="toggleRetired({{ $app->id }})"
                                                class="block w-full px-3 py-2 text-left text-slate-700 hover:bg-slate-50">
                                            {{ $app->status === \App\Enums\WorkspaceAppStatus::Retired ? 'Bring back' : 'Retire' }}
                                        </button>
                                        <button type="button" wire:click="delete({{ $app->id }})"
                                                wire:confirm="Remove “{{ $app->name }}” entirely? Retiring it is usually better — a link staff remember should still be explainable."
                                                class="block w-full px-3 py-2 text-left text-red-700 hover:bg-red-50">
                                            Remove entirely
                                        </button>
                                    </x-dropdown>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{ $apps->links() }}
    @endif
</div>
