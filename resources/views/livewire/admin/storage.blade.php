<div class="space-y-6">

    <div>
        <h1 class="text-2xl font-semibold text-slate-900">Storage & Backups</h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-600">
            Where the drive's space is going, and a safety net — separate from the drive's own
            trash — for the database and the files behind it.
        </p>
    </div>

    @error('backup')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    {{-- Totals --}}
    @php
        $driveTotal = $usage->sum('total_size');
    @endphp
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-600">Files, all offices</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">
                {{ \App\Support\Bytes::human($driveTotal) }}
            </p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-600">Database</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">
                {{ \App\Support\Bytes::human($databaseSize) }}
            </p>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <p class="text-sm text-slate-600">Free disk space</p>
            <p class="mt-2 text-2xl font-semibold text-slate-900">
                {{ \App\Support\Bytes::human($freeDiskSpace) }}
            </p>
        </div>
    </div>

    {{-- Usage by office --}}
    <div>
        <h2 class="text-base font-semibold text-slate-900">Usage by office</h2>
        <div class="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">Office</th>
                        <th scope="col" class="px-4 py-3">Files</th>
                        <th scope="col" class="px-4 py-3">Size</th>
                        <th scope="col" class="px-4 py-3">Share</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($usage as $row)
                        <tr wire:key="usage-{{ $row['department']->id }}" class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-medium text-slate-900">
                                {{ $row['department']->displayName() }}
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $row['file_count'] }}</td>
                            <td class="px-4 py-3 text-slate-600">
                                {{ \App\Support\Bytes::human($row['total_size']) }}
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 w-32 overflow-hidden rounded-full bg-slate-100">
                                        <div class="h-2 rounded-full bg-blue-600"
                                             style="width: {{ $driveTotal > 0 ? round($row['total_size'] / $driveTotal * 100) : 0 }}%">
                                        </div>
                                    </div>
                                    <span class="text-xs text-slate-500">
                                        {{ $driveTotal > 0 ? round($row['total_size'] / $driveTotal * 100) : 0 }}%
                                    </span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-10 text-center text-slate-500">
                                No files stored yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Backups --}}
    <div>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Backups</h2>
                <p class="mt-1 max-w-2xl text-sm text-slate-600">
                    Made on demand, right here, since there is no scheduled job on this host.
                    The newest {{ $keepPerType }} of each type are kept; older ones are removed
                    automatically.
                </p>
            </div>

            <div class="flex gap-3">
                <button wire:click="backupDatabase" wire:loading.attr="disabled" wire:target="backupDatabase"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:opacity-60">
                    <span wire:loading.remove wire:target="backupDatabase">Back up database</span>
                    <span wire:loading wire:target="backupDatabase">Backing up…</span>
                </button>
                <button wire:click="backupFiles" wire:loading.attr="disabled" wire:target="backupFiles"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 disabled:opacity-60">
                    <span wire:loading.remove wire:target="backupFiles">Back up files</span>
                    <span wire:loading wire:target="backupFiles">Backing up…</span>
                </button>
            </div>
        </div>

        <div class="mt-4 overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">Type</th>
                        <th scope="col" class="px-4 py-3">Made</th>
                        <th scope="col" class="px-4 py-3">By</th>
                        <th scope="col" class="px-4 py-3">Size</th>
                        <th scope="col" class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($backupsList as $backup)
                        <tr wire:key="backup-{{ $backup->id }}" class="hover:bg-slate-50">
                            <td class="px-4 py-3">
                                <span @class([
                                    'inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium',
                                    'bg-blue-100 text-blue-800' => $backup->type === \App\Enums\BackupType::Database,
                                    'bg-slate-100 text-slate-700' => $backup->type === \App\Enums\BackupType::Files,
                                ])>
                                    {{ $backup->type->label() }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                                {{ ph_datetime($backup->created_at) }}
                            </td>
                            <td class="px-4 py-3 text-slate-600">{{ $backup->creator?->name ?? 'System' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $backup->humanSize() }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right">
                                <a href="{{ route('admin.storage.download', $backup) }}"
                                   class="text-sm font-medium text-blue-700 hover:underline">
                                    Download
                                </a>
                                <button type="button" wire:click="delete({{ $backup->id }})"
                                        wire:confirm="Delete this backup? This cannot be undone."
                                        class="ml-3 text-sm font-medium text-red-700 hover:underline">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                                No backups yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
