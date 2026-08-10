<div class="space-y-6">

    {{-- Header --}}
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-sm font-semibold text-slate-700">{{ $document->tracking_no }}</span>
                    <x-status-badge :tone="$document->status->tone()" :label="$document->status->label()" />
                    @if ($document->confidentiality !== \App\Enums\Confidentiality::Internal)
                        <x-status-badge :tone="$document->confidentiality->tone()"
                                        :label="$document->confidentiality->label()" />
                    @endif
                    @if ($document->isOverdue())
                        <x-status-badge tone="red" label="Overdue" />
                    @endif
                </div>

                <h1 class="mt-2 text-2xl font-semibold text-slate-900">{{ $document->subject }}</h1>

                <p class="mt-1 text-sm text-slate-600">
                    {{ $document->type?->name }}
                    @if ($document->reference_no) · {{ $document->reference_no }} @endif
                    · from {{ $document->originLabel() }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                {{-- Opens in its own tab: the clerk prints it and comes back to
                     a page that has not lost its place. --}}
                <a href="{{ route('documents.slip', $document) }}" target="_blank" rel="noopener"
                   class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    Routing slip
                </a>
                <a href="{{ route('desk') }}" wire:navigate
                   class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    Back to my desk
                </a>
            </div>
        </div>

        {{-- The question this system exists to answer. --}}
        <div class="mt-5 rounded-lg bg-slate-50 px-4 py-3">
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

    {{-- What can be done now --}}
    @php
        $buttons = array_filter([
            $can['release'] ? ['release', 'Send to another office', true] : null,
            $can['release'] && $document->status === \App\Enums\DocumentStatus::Received
                ? ['return', 'Return to sender', false] : null,
            $can['receive'] ? ['receive', 'Record receipt', true] : null,
            $can['recall'] ? ['recall', 'Recall', false] : null,
            $can['act'] && $document->status->allowsRelease() ? ['assign', 'Assign', false] : null,
            $can['comment'] ? ['remarks', 'Add remarks', false] : null,
            $can['act'] && $document->status->isOpen() ? ['close', 'Close or withdraw', false] : null,
            $can['act'] && ! $document->status->isOpen()
                && $document->status !== \App\Enums\DocumentStatus::Cancelled ? ['reopen', 'Reopen', false] : null,
        ]);
    @endphp

    @if ($buttons)
        <div class="flex flex-wrap gap-3">
            @foreach ($buttons as [$key, $label, $primary])
                <button type="button" wire:click="open('{{ $key }}')"
                        @class([
                            'rounded-lg px-4 py-2 text-sm font-semibold transition',
                            'bg-blue-700 text-white hover:bg-blue-800' => $primary,
                            'border border-slate-300 text-slate-700 hover:bg-slate-50' => ! $primary,
                        ])>
                    {{ $label }}
                </button>
            @endforeach
        </div>
    @endif

    {{-- Action panels --}}
    @if ($panel === 'release' || $panel === 'return')
        <form wire:submit="{{ $panel === 'return' ? 'returnToSender' : 'release' }}"
              class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">
                {{ $panel === 'return' ? 'Return to the sending office' : 'Send to another office' }}
            </h2>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                @if ($panel === 'release')
                    <div>
                        <label for="to_department_id" class="block text-sm font-medium text-slate-700">Send to</label>
                        <select id="to_department_id" wire:model="to_department_id"
                                class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                            <option value="">Choose an office…</option>
                            @foreach ($destinations as $office)
                                <option value="{{ $office->id }}">
                                    {{ $office->displayName() }}@unless ($office->acceptsDigitalReceipt()) — signs on paper @endunless
                                </option>
                            @endforeach
                        </select>
                        @error('to_department_id') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div>
                    <label for="action_requested" class="block text-sm font-medium text-slate-700">Asking them to</label>
                    <select id="action_requested" wire:model="action_requested"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($actions as $action)
                            <option value="{{ $action->value }}">{{ $action->label() }}</option>
                        @endforeach
                    </select>
                    @error('action_requested') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                @if ($panel === 'release')
                    <div>
                        <label for="route_due_at" class="block text-sm font-medium text-slate-700">
                            Deadline <span class="font-normal text-slate-500">(optional)</span>
                        </label>
                        <input id="route_due_at" wire:model="route_due_at" type="datetime-local"
                               class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @error('route_due_at') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="sm:col-span-2">
                    <label for="route_remarks" class="block text-sm font-medium text-slate-700">
                        Remarks {{ $panel === 'return' ? '' : '(optional)' }}
                    </label>
                    <textarea id="route_remarks" wire:model="route_remarks" rows="2"
                              placeholder="{{ $panel === 'return' ? 'Say what needs fixing.' : 'Anything the receiving office should know.' }}"
                              class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600"></textarea>
                    @error('route_remarks') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="mt-5 flex gap-3">
                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                    {{ $panel === 'return' ? 'Return it' : 'Release it' }}
                </button>
                <button type="button" wire:click="closePanel"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    @if ($panel === 'receive')
        @php $destination = $document->openRoute?->toDepartment; @endphp

        <form wire:submit="receive" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">Record receipt</h2>
            <p class="mt-1 text-sm text-slate-600">
                Sent to {{ $destination?->displayName() }}.
                @unless ($destination?->acceptsDigitalReceipt())
                    They are not yet using the system, so this can only be recorded from the signed transmittal.
                @endunless
            </p>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="receipt_method" class="block text-sm font-medium text-slate-700">How</label>
                    <select id="receipt_method" wire:model.live="receipt_method"
                            class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @foreach ($methods as $method)
                            @continue ($method === \App\Enums\ReceiptMethod::Qr)
                            <option value="{{ $method->value }}">{{ $method->label() }}</option>
                        @endforeach
                    </select>
                    @error('receipt_method') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                </div>

                @if ($receipt_method === \App\Enums\ReceiptMethod::Manual->value)
                    <div>
                        <label for="received_by_name" class="block text-sm font-medium text-slate-700">
                            Signed by
                        </label>
                        <input id="received_by_name" wire:model="received_by_name" type="text"
                               placeholder="Name written on the transmittal"
                               class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        @error('received_by_name') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="received_at" class="block text-sm font-medium text-slate-700">
                            Signed at <span class="font-normal text-slate-500">(optional)</span>
                        </label>
                        <input id="received_at" wire:model="received_at" type="datetime-local"
                               class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                        <p class="mt-1 text-xs text-slate-500">Leave blank for now. It cannot be before it was sent.</p>
                        @error('received_at') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <div class="mt-5 flex gap-3">
                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                    Record it
                </button>
                <button type="button" wire:click="closePanel"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    @if ($panel === 'assign')
        <form wire:submit="assign" class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">Assign within your office</h2>

            <div class="mt-4 max-w-sm">
                <label for="assignee_id" class="block text-sm font-medium text-slate-700">Put it with</label>
                <select id="assignee_id" wire:model="assignee_id"
                        class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600">
                    <option value="">Nobody in particular — the office pool</option>
                    @foreach ($colleagues as $colleague)
                        <option value="{{ $colleague->id }}">{{ $colleague->displayName() }}</option>
                    @endforeach
                </select>
                @error('assignee_id') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="mt-5 flex gap-3">
                <button type="submit"
                        class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                    Assign
                </button>
                <button type="button" wire:click="closePanel"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    @if (in_array($panel, ['remarks', 'recall', 'reopen', 'close'], true))
        @php
            $panelCopy = [
                'remarks' => ['Add remarks', 'Note', 'Add to the record', 'addRemarks'],
                'recall'  => ['Recall the transmittal', 'Why', 'Recall it', 'recall'],
                'reopen'  => ['Reopen the document', 'Why', 'Reopen it', 'reopen'],
                'close'   => ['Close or withdraw', 'Remarks', null, null],
            ][$panel];
        @endphp

        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">{{ $panelCopy[0] }}</h2>

            @if ($panel === 'close')
                <p class="mt-1 text-sm text-slate-600">
                    Completing means the work is done. Withdrawing means it should not have been registered —
                    the record and its whole trail are kept either way.
                </p>
            @endif

            <div class="mt-4">
                <label for="note" class="block text-sm font-medium text-slate-700">{{ $panelCopy[1] }}</label>
                <textarea id="note" wire:model="note" rows="3"
                          class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-blue-600 focus:ring-blue-600"></textarea>
                @error('note') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
            </div>

            <div class="mt-5 flex flex-wrap gap-3">
                @if ($panel === 'close')
                    @if ($document->status->allowsRelease())
                        <button type="button" wire:click="complete"
                                class="rounded-lg bg-green-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-green-800">
                            Mark complete
                        </button>
                    @endif
                    <button type="button" wire:click="cancel"
                            class="rounded-lg bg-red-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-red-800">
                        Withdraw
                    </button>
                @else
                    <button type="button" wire:click="{{ $panelCopy[3] }}"
                            class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                        {{ $panelCopy[2] }}
                    </button>
                @endif

                <button type="button" wire:click="closePanel"
                        class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    @if ($document->status === \App\Enums\DocumentStatus::Completed && $can['act'])
        <div class="flex gap-3">
            <button type="button" wire:click="archive"
                    class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                Archive it
            </button>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">

        {{-- Facts --}}
        <div class="space-y-6">
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">Details</h2>

                <dl class="mt-4 space-y-3 text-sm">
                    @foreach ([
                        'Registered by' => $document->registeringDepartment?->displayName(),
                        'Registered' => ph_datetime($document->created_at),
                        'Entered by' => $document->creator?->displayName() ?? 'Account removed',
                        'Origin' => $document->originLabel(),
                        'Handling' => $document->confidentiality->label(),
                        'Retention' => $document->type?->retention_years
                            ? $document->type->retention_years.' years'
                            : 'Permanent',
                        'Closed' => ph_datetime($document->closed_at),
                    ] as $term => $value)
                        @if ($value)
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-slate-500">{{ $term }}</dt>
                                <dd class="mt-0.5 text-slate-800">{{ $value }}</dd>
                            </div>
                        @endif
                    @endforeach
                </dl>

                @if ($document->description)
                    <div class="mt-4 border-t border-slate-100 pt-4">
                        <p class="text-xs uppercase tracking-wide text-slate-500">Notes</p>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $document->description }}</p>
                    </div>
                @endif
            </div>
        </div>

        {{-- Attachments and timeline --}}
        <div class="space-y-6 lg:col-span-2">

            @livewire('documents.attachments', ['document' => $document], key('attachments-'.$document->id))

            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-base font-semibold text-slate-900">Chain of custody</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Every act, in order. Entries are never edited or removed.
                </p>

                <ol class="mt-5 space-y-5">
                    @foreach ($timeline as $entry)
                        <li class="relative flex gap-4">
                            <div class="flex flex-col items-center">
                                <span @class([
                                    'mt-1 size-2.5 shrink-0 rounded-full',
                                    'bg-green-600' => $entry->action->tone() === 'green',
                                    'bg-amber-500' => $entry->action->tone() === 'amber',
                                    'bg-blue-600' => $entry->action->tone() === 'blue',
                                    'bg-red-600' => $entry->action->tone() === 'red',
                                    'bg-slate-400' => $entry->action->tone() === 'slate',
                                ])></span>
                                @unless ($loop->last)
                                    <span class="mt-1 w-px grow bg-slate-200"></span>
                                @endunless
                            </div>

                            <div class="min-w-0 pb-1">
                                <p class="text-sm font-medium text-slate-900">
                                    {{ $entry->action->label() }}
                                    @if ($entry->route?->toDepartment)
                                        <span class="font-normal text-slate-600">
                                            — {{ $entry->route->toDepartment->displayName() }}
                                        </span>
                                    @endif
                                </p>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $entry->actorLabel() }} · {{ ph_datetime($entry->created_at) }}
                                </p>
                                @if ($entry->remarks)
                                    <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $entry->remarks }}</p>
                                @endif
                                @if (($entry->meta['witnessed'] ?? null) === false)
                                    <p class="mt-1 text-xs text-amber-700">
                                        Recorded from a paper signature by {{ $entry->meta['signed_by'] ?? 'an unnamed person' }},
                                        not witnessed by the system.
                                    </p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </div>

            {{-- Transmittal ledger --}}
            @if ($document->routes->isNotEmpty())
                <div class="mt-6 overflow-x-auto rounded-xl border border-slate-200 bg-white">
                    <div class="border-b border-slate-200 px-6 py-4">
                        <h2 class="text-base font-semibold text-slate-900">Transmittals</h2>
                        <p class="mt-1 text-sm text-slate-600">
                            Each handover, including the ones that were recalled.
                        </p>
                    </div>

                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">#</th>
                                <th scope="col" class="px-4 py-3">From → to</th>
                                <th scope="col" class="px-4 py-3">Asked for</th>
                                <th scope="col" class="px-4 py-3">Sent</th>
                                <th scope="col" class="px-4 py-3">Received</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($document->routes as $leg)
                                <tr wire:key="route-{{ $leg->id }}">
                                    <td class="px-4 py-3 align-top text-slate-500">{{ $leg->seq }}</td>

                                    <td class="px-4 py-3 align-top">
                                        <span class="text-slate-800">
                                            {{ $leg->fromDepartment?->displayName() }}
                                            <span class="text-slate-400">→</span>
                                            {{ $leg->toDepartment?->displayName() }}
                                        </span>
                                        @if ($leg->is_return)
                                            <x-status-badge tone="amber" label="Return" class="ml-1" />
                                        @endif
                                        @if ($leg->remarks)
                                            <span class="mt-0.5 block text-xs text-slate-500">{{ $leg->remarks }}</span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 align-top text-slate-600">
                                        {{ $leg->action_requested?->label() }}
                                    </td>

                                    <td class="whitespace-nowrap px-4 py-3 align-top text-slate-600">
                                        {{ ph_datetime($leg->sent_at) }}
                                    </td>

                                    <td class="px-4 py-3 align-top text-slate-600">
                                        @if ($leg->received_at)
                                            {{ ph_datetime($leg->received_at) }}
                                            <span class="mt-0.5 block text-xs text-slate-500">
                                                {{ $leg->received_by_name }}
                                                @unless ($leg->receipt_method?->isWitnessed())
                                                    · on paper
                                                @endunless
                                            </span>
                                        @else
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </td>

                                    <td class="px-4 py-3 align-top">
                                        <x-status-badge :tone="$leg->status->tone()" :label="$leg->status->label()" />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
