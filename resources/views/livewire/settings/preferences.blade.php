<x-settings.shell heading="Preferences"
                  description="How the system looks to you. These change nothing for anybody else, and nothing about what you are allowed to see.">

    <form wire:submit="save" class="space-y-6">

        {{-- Starting point --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900">Getting around</h3>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="landing" class="block text-sm font-medium text-slate-700">Open this after signing in</label>
                    <select id="landing" wire:model="landing"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($landings as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        A receiving clerk usually wants My Desk; most others want the Dashboard.
                        If you cannot reach the page you pick, you land on the Dashboard instead.
                    </p>
                    @error('landing') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="rows_per_page" class="block text-sm font-medium text-slate-700">Rows per page</label>
                    <select id="rows_per_page" wire:model="rows_per_page"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($rowOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">
                        Applies to documents, the drive, alerts and the audit trail.
                    </p>
                    @error('rows_per_page') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Dates --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900">Dates and times</h3>
            <p class="mt-1 text-xs text-slate-500">
                Everything is shown in Philippine time ({{ ph_tz() }}) whatever you choose here —
                this is only how it is written.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="date_format" class="block text-sm font-medium text-slate-700">Date</label>
                    <select id="date_format" wire:model.live="date_format"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($dateFormats as $format => $example)
                            <option value="{{ $format }}">{{ $example }}</option>
                        @endforeach
                    </select>
                    @error('date_format') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="time_format" class="block text-sm font-medium text-slate-700">Time</label>
                    <select id="time_format" wire:model.live="time_format"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($timeFormats as $format => $example)
                            <option value="{{ $format }}">{{ $example }}</option>
                        @endforeach
                    </select>
                    @error('time_format') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>

            <p class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-700">
                Timestamps will read <span class="font-semibold text-slate-900">{{ $sample }}</span>.
            </p>
        </div>

        {{-- Drive --}}
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900">Drive</h3>
            <p class="mt-1 text-xs text-slate-500">
                What the drive opens as. Changing it on the drive itself only lasts for that visit;
                this is what it goes back to.
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="drive_view" class="block text-sm font-medium text-slate-700">Layout</label>
                    <select id="drive_view" wire:model="drive_view"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($driveViews as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('drive_view') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="drive_sort" class="block text-sm font-medium text-slate-700">Sort by</label>
                    <select id="drive_sort" wire:model="drive_sort"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($driveSorts as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('drive_sort') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="drive_sort_dir" class="block text-sm font-medium text-slate-700">Order</label>
                    <select id="drive_sort_dir" wire:model="drive_sort_dir"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        <option value="asc">First to last (A→Z, oldest, smallest)</option>
                        <option value="desc">Last to first (Z→A, newest, largest)</option>
                    </select>
                    @error('drive_sort_dir') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <div class="flex flex-wrap gap-3">
            <button type="submit"
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                Save preferences
            </button>
            <button type="button" wire:click="resetToDefaults"
                    wire:confirm="Put every preference on this page back to its default?"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                Reset to defaults
            </button>
        </div>
    </form>
</x-settings.shell>
