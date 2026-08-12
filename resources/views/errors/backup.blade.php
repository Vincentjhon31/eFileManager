<x-layouts.guest title="That backup could not be read">
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h1 class="text-lg font-semibold text-slate-900">That backup could not be read</h1>

        <p class="mt-3 text-sm text-slate-700">{{ $message }}</p>

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ route('admin.storage.index') }}"
               class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                Back to Storage & Backups
            </a>
        </div>
    </div>
</x-layouts.guest>
