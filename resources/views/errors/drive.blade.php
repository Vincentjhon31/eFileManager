<x-layouts.guest title="That file could not be opened">
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h1 class="text-lg font-semibold text-slate-900">That file could not be opened</h1>

        <p class="mt-3 text-sm text-slate-700">{{ $message }}</p>

        <div class="mt-6 flex flex-wrap gap-3">
            <a href="{{ url()->previous() }}"
               class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                Go back
            </a>
            <a href="{{ route('drive') }}"
               class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                Open the drive
            </a>
        </div>
    </div>
</x-layouts.guest>
