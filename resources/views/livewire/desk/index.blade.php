<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">My Desk</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                What is coming in, what you are holding, and what you are still waiting on.
            </p>
        </div>

        <label class="inline-flex items-center gap-2 text-sm text-slate-700">
            <input type="checkbox" wire:model.live="mineOnly"
                   class="rounded border-slate-300 text-blue-700 focus:ring-blue-600">
            Only what is addressed to me
        </label>
    </div>

    @unless (auth()->user()->department_id)
        <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Your account is not assigned to an office yet, so nothing can be sent to you or from you.
            Ask your administrator to assign one.
        </div>
    @endunless

    {{-- Tabs --}}
    @php
        $tabs = [
            'incoming' => ['To receive', $counts['incoming'], 'amber'],
            'desk'     => ['On my desk', $counts['desk'], 'blue'],
            'awaiting' => ['Awaiting receipt', $counts['awaiting'], 'slate'],
            'overdue'  => ['Overdue', $counts['overdue'], 'red'],
        ];
    @endphp

    <div class="border-b border-slate-200">
        <nav class="-mb-px flex gap-6 overflow-x-auto text-sm">
            @foreach ($tabs as $key => [$label, $count, $tone])
                <button type="button" wire:click="selectTab('{{ $key }}')"
                        @class([
                            'inline-flex items-center gap-2 whitespace-nowrap border-b-2 px-1 py-3 font-medium transition',
                            'border-blue-700 text-blue-700' => $tab === $key,
                            'border-transparent text-slate-600 hover:border-slate-300 hover:text-slate-900' => $tab !== $key,
                        ])>
                    {{ $label }}
                    @if ($count > 0)
                        <x-status-badge :tone="$tone" :label="$count" />
                    @endif
                </button>
            @endforeach
        </nav>
    </div>

    {{-- Transmittals: incoming and awaiting receipt --}}
    @if ($legs !== null)
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">Tracking no.</th>
                        <th scope="col" class="px-4 py-3">Subject</th>
                        <th scope="col" class="px-4 py-3">{{ $tab === 'incoming' ? 'Sent by' : 'Sent to' }}</th>
                        <th scope="col" class="px-4 py-3">Asked for</th>
                        <th scope="col" class="px-4 py-3">Sent</th>
                        <th scope="col" class="px-4 py-3">Due</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($legs as $leg)
                        <tr wire:key="leg-{{ $leg->id }}" class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-3 align-top">
                                <a href="{{ route('documents.show', $leg->document) }}" wire:navigate
                                   class="font-mono text-xs font-semibold text-blue-700 hover:underline">
                                    {{ $leg->document->tracking_no }}
                                </a>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ $leg->document->type?->name }}</span>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <span class="font-medium text-slate-900">{{ $leg->document->subject }}</span>
                                @if ($leg->is_return)
                                    <x-status-badge tone="amber" label="Returned" class="ml-2" />
                                @endif
                                @if ($leg->remarks)
                                    <span class="mt-0.5 block text-xs text-slate-500">{{ $leg->remarks }}</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 align-top text-slate-600">
                                {{ $tab === 'incoming'
                                    ? $leg->fromDepartment?->displayName()
                                    : $leg->toDepartment?->displayName() }}
                                @if ($tab === 'incoming' && $leg->toUser)
                                    <span class="block text-xs text-slate-500">For {{ $leg->toUser->name }}</span>
                                @endif
                            </td>

                            <td class="px-4 py-3 align-top text-slate-600">{{ $leg->action_requested?->label() }}</td>

                            <td class="whitespace-nowrap px-4 py-3 align-top text-slate-600">
                                {{ ph_datetime($leg->sent_at) }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-3 align-top">
                                @if ($leg->due_at)
                                    <span @class(['font-medium text-red-700' => $leg->isOverdue(), 'text-slate-600' => ! $leg->isOverdue()])>
                                        {{ ph_date($leg->due_at) }}
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-slate-500">
                                @if ($tab === 'incoming')
                                    Nothing waiting to be received. When another office sends you something it lands here.
                                @else
                                    Nothing outstanding. Everything your office has sent has been signed for.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $legs->links() }}
    @endif

    {{-- Documents: on my desk and overdue --}}
    @if ($documents !== null)
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">Tracking no.</th>
                        <th scope="col" class="px-4 py-3">Subject</th>
                        <th scope="col" class="px-4 py-3">From</th>
                        <th scope="col" class="px-4 py-3">Assigned to</th>
                        <th scope="col" class="px-4 py-3">Due</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($documents as $document)
                        <tr wire:key="desk-{{ $document->id }}" class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-3 align-top">
                                <a href="{{ route('documents.show', $document) }}" wire:navigate
                                   class="font-mono text-xs font-semibold text-blue-700 hover:underline">
                                    {{ $document->tracking_no }}
                                </a>
                                <span class="mt-0.5 block text-xs text-slate-500">{{ $document->type?->name }}</span>
                            </td>

                            <td class="px-4 py-3 align-top">
                                <span class="font-medium text-slate-900">{{ $document->subject }}</span>
                                @if ($document->isConfidential())
                                    <x-status-badge tone="red" label="Confidential" class="ml-2" />
                                @endif
                            </td>

                            <td class="px-4 py-3 align-top text-slate-600">{{ $document->originLabel() }}</td>

                            <td class="px-4 py-3 align-top text-slate-600">
                                {{ $document->currentHolderUser?->name ?? 'Nobody yet' }}
                            </td>

                            <td class="whitespace-nowrap px-4 py-3 align-top">
                                @if ($document->due_at)
                                    <span @class(['font-medium text-red-700' => $document->isOverdue(), 'text-slate-600' => ! $document->isOverdue()])>
                                        {{ ph_date($document->due_at) }}
                                    </span>
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-slate-500">
                                @if ($tab === 'overdue')
                                    Nothing overdue.
                                @else
                                    Your desk is clear.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $documents->links() }}
    @endif
</div>
