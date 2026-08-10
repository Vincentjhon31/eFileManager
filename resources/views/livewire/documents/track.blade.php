{{--
    A phone screen. Everything is sized for one hand in a corridor: large type,
    one obvious action, no horizontal anything.
--}}
<div class="mx-auto max-w-lg space-y-5">

    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-mono text-sm font-semibold text-slate-700">{{ $document->tracking_no }}</span>
            <x-status-badge :tone="$document->status->tone()" :label="$document->status->label()" />
            @if ($document->isOverdue())
                <x-status-badge tone="red" label="Overdue" />
            @endif
        </div>

        <h1 class="mt-2 text-xl font-semibold leading-snug text-slate-900">{{ $document->subject }}</h1>

        <p class="mt-1 text-sm text-slate-600">
            {{ $document->type?->name }} · from {{ $document->originLabel() }}
        </p>

        <div class="mt-4 rounded-lg bg-slate-50 px-4 py-3">
            <p class="text-xs font-medium uppercase tracking-wide text-slate-500">Where it is</p>
            <p class="mt-0.5 text-sm font-medium text-slate-900">{{ $document->locationLabel() }}</p>
            @if ($document->due_at)
                <p class="mt-1 text-xs {{ $document->isOverdue() ? 'font-medium text-red-700' : 'text-slate-500' }}">
                    Due {{ ph_datetime($document->due_at) }}
                </p>
            @endif
        </div>
    </div>

    @error('routing')
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">{{ $message }}</div>
    @enderror

    {{-- The one action this screen exists for --}}
    @if ($leg && $canReceive)
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-5">
            <p class="text-sm text-blue-900">
                Sent by <strong>{{ $leg->fromDepartment?->displayName() }}</strong>
                to <strong>{{ $leg->toDepartment?->displayName() }}</strong>
                @if ($leg->toUser) for {{ $leg->toUser->name }} @endif
                — {{ mb_strtolower($leg->action_requested?->label() ?? 'for action') }}.
            </p>
            @if ($leg->remarks)
                <p class="mt-2 text-sm text-blue-900">“{{ $leg->remarks }}”</p>
            @endif
            <p class="mt-1 text-xs text-blue-800">Released {{ ph_datetime($leg->sent_at) }}</p>

            @unless ($confirming)
                <button type="button" wire:click="startReceiving"
                        class="mt-4 w-full rounded-lg bg-blue-700 px-4 py-3 text-base font-semibold text-white transition hover:bg-blue-800">
                    Receive this document
                </button>
            @endunless

            @if ($confirming)
                <form wire:submit="receive" class="mt-4 space-y-4">
                    @if ($digital)
                        <p class="text-sm text-blue-900">
                            This will record <strong>{{ auth()->user()->name }}</strong> as having
                            received it, at the moment you press the button. The time cannot be
                            changed afterwards.
                        </p>
                        <input type="hidden" wire:model="receipt_method">
                    @else
                        <div>
                            <label for="receipt_method" class="block text-sm font-medium text-blue-900">How</label>
                            <select id="receipt_method" wire:model.live="receipt_method"
                                    class="mt-1 block w-full rounded-lg border-blue-300 text-base shadow-sm focus:border-blue-600 focus:ring-blue-600">
                                @foreach ($methods as $method)
                                    <option value="{{ $method->value }}">{{ $method->label() }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-blue-800">
                                {{ $leg->toDepartment?->displayName() }} is not using the system yet,
                                so this is recorded from their signature on the slip.
                            </p>
                            @error('receipt_method') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="received_by_name" class="block text-sm font-medium text-blue-900">
                                Signed by
                            </label>
                            <input id="received_by_name" wire:model="received_by_name" type="text"
                                   placeholder="Name written on the slip"
                                   class="mt-1 block w-full rounded-lg border-blue-300 text-base shadow-sm focus:border-blue-600 focus:ring-blue-600">
                            @error('received_by_name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="received_at" class="block text-sm font-medium text-blue-900">
                                Signed at <span class="font-normal">(leave blank for now)</span>
                            </label>
                            <input id="received_at" wire:model="received_at" type="datetime-local"
                                   class="mt-1 block w-full rounded-lg border-blue-300 text-base shadow-sm focus:border-blue-600 focus:ring-blue-600">
                            @error('received_at') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div class="flex gap-3">
                        <button type="submit"
                                class="grow rounded-lg bg-blue-700 px-4 py-3 text-base font-semibold text-white transition hover:bg-blue-800">
                            Confirm receipt
                        </button>
                        <button type="button" wire:click="cancelReceiving"
                                class="rounded-lg border border-blue-300 px-4 py-3 text-sm font-medium text-blue-900 transition hover:bg-blue-100">
                            Cancel
                        </button>
                    </div>
                </form>
            @endif
        </div>
    @elseif ($leg)
        <div class="rounded-xl border border-slate-200 bg-white p-5 text-sm text-slate-600">
            Awaiting receipt by <strong class="text-slate-900">{{ $leg->toDepartment?->displayName() }}</strong>,
            released {{ ph_datetime($leg->sent_at) }}. Only that office or the office that sent it
            can record the receipt.
        </div>
    @endif

    {{-- Latest few acts, so a scan answers "what happened to this" too --}}
    <div class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-sm font-semibold text-slate-900">Latest activity</h2>

        <ul class="mt-3 space-y-3">
            @foreach ($recent as $entry)
                <li class="text-sm">
                    <span class="font-medium text-slate-800">{{ $entry->action->label() }}</span>
                    <span class="block text-xs text-slate-500">
                        {{ $entry->actorLabel() }} · {{ ph_datetime($entry->created_at) }}
                    </span>
                </li>
            @endforeach
        </ul>

        <a href="{{ route('documents.show', $document) }}" wire:navigate
           class="mt-4 inline-block text-sm font-medium text-blue-700 hover:underline">
            Open the full record →
        </a>
    </div>
</div>
