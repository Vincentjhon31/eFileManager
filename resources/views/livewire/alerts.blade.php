<div class="mx-auto max-w-3xl space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Alerts</h1>
            <p class="mt-1 text-sm text-slate-600">
                Reminders about what is waiting. Clearing one changes nothing about the record.
            </p>
        </div>

        @if ($unread > 0)
            <button type="button" wire:click="markAllAsRead"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                Mark all {{ $unread }} as read
            </button>
        @endif
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <ul class="divide-y divide-slate-100">
            @forelse ($alerts as $alert)
                <li wire:key="alert-{{ $alert->id }}"
                    @class(['px-5 py-4', 'bg-blue-50/50' => ! $alert->read_at])>

                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900">
                                {{ $alert->data['summary'] ?? 'Notification' }}
                            </p>
                            <p class="mt-0.5 text-xs text-slate-500">{{ ph_datetime($alert->created_at) }}</p>
                        </div>

                        @unless ($alert->read_at)
                            <button type="button" wire:click="markAsRead('{{ $alert->id }}')"
                                    class="text-xs font-medium text-blue-700 hover:underline">
                                Mark as read
                            </button>
                        @endunless
                    </div>

                    @if (! empty($alert->data['documents']))
                        <ul class="mt-3 space-y-1.5">
                            @foreach ($alert->data['documents'] as $entry)
                                <li class="text-sm">
                                    <a href="{{ route('documents.show', $entry['id']) }}" wire:navigate
                                       class="font-mono text-xs font-semibold text-blue-700 hover:underline">
                                        {{ $entry['tracking_no'] }}
                                    </a>
                                    <span class="text-slate-700"> — {{ $entry['subject'] }}</span>
                                    @if (! empty($entry['due_at']))
                                        <span class="text-xs text-slate-500">
                                            (due {{ ph_datetime($entry['due_at']) }})
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if (($alert->data['incoming'] ?? 0) > 0 || ($alert->data['awaiting'] ?? 0) > 0)
                        <p class="mt-3 text-sm text-slate-600">
                            @if (($alert->data['incoming'] ?? 0) > 0)
                                {{ $alert->data['incoming'] }} waiting to be received.
                            @endif
                            @if (($alert->data['awaiting'] ?? 0) > 0)
                                {{ $alert->data['awaiting'] }} released and not yet signed for.
                            @endif
                            <a href="{{ route('desk') }}" wire:navigate class="font-medium text-blue-700 hover:underline">
                                Open my desk
                            </a>
                        </p>
                    @endif
                </li>
            @empty
                <li class="px-5 py-10 text-center text-sm text-slate-500">
                    Nothing to report. Reminders arrive on weekday mornings when something is waiting.
                </li>
            @endforelse
        </ul>
    </div>

    {{ $alerts->links() }}
</div>
