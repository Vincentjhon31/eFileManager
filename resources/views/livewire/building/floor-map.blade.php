{{--
    The building.

    Two presentations of one set of data, and no hidden content: above the md
    breakpoint the plan is drawn and the room list sits beneath it; below, the
    plan is dropped and the same list carries the whole screen. Everything the
    map says, the list says too — which is what makes this usable with a
    keyboard, a screen reader, or a phone in a corridor, without maintaining a
    second copy of anything.
--}}
<div class="space-y-6" wire:poll.30s>

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">
                {{ $floor?->building?->name ?? 'Building' }}
            </h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Every door shows what is waiting behind it. Amber means work is sitting with that
                office; red means something has passed its deadline.
            </p>
        </div>

        @if ($floors->count() > 1)
            <div class="flex flex-wrap gap-2">
                @foreach ($floors as $option)
                    <button type="button" wire:click="showFloor('{{ $option->slug }}')"
                            @class([
                                'rounded-lg border px-3 py-1.5 text-sm font-medium transition',
                                'border-blue-700 bg-blue-700 text-white' => $floor && $option->id === $floor->id,
                                'border-slate-300 text-slate-700 hover:bg-slate-50' => ! $floor || $option->id !== $floor->id,
                            ])>
                        {{ $option->name }}
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    @if (! $floor)
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">
            No floors have been set up yet.
        </div>
    @else

        {{-- Legend --}}
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-slate-600">
            @foreach ([
                \App\Enums\DoorState::Idle,
                \App\Enums\DoorState::Pending,
                \App\Enums\DoorState::Overdue,
                \App\Enums\DoorState::Vacant,
            ] as $legend)
                <span class="inline-flex items-center gap-2">
                    <span class="inline-block size-3.5 rounded-sm border"
                          style="background: {{ $legend->fill() }}; border-color: {{ $legend->stroke() }}"></span>
                    {{ $legend->label() }}
                </span>
            @endforeach
        </div>

        {{-- The plan --}}
        @if ($svg)
            @php
                // Generated stylesheet rather than touching the drawing. The
                // application never parses the SVG — it only knows the ids the
                // seeder recorded, and paints them from here.
                $rules = '';
                foreach ($rooms as $r) {
                    if (! $r->svg_shape_id) { continue; }

                    if ($r->type->isNavigable()) {
                        $rules .= sprintf('#%s{cursor:pointer}', $r->svg_shape_id);
                    }

                    // No rule at all where the door has nothing to say, and the
                    // drawing's own neutral fill simply stands.
                    if (! ($states[$r->id]['showsState'] ?? false)) { continue; }

                    $s = $states[$r->id]['state'];
                    $rules .= sprintf(
                        '#%s{fill:%s;stroke:%s;stroke-width:%s}',
                        $r->svg_shape_id, $s->fill(), $s->stroke(), $s->shouldPulse() ? '6' : '3',
                    );

                    if ($s->shouldPulse()) {
                        $rules .= sprintf('#%s{animation:door-pulse 2.4s ease-in-out infinite}', $r->svg_shape_id);
                    }
                }
            @endphp

            <style>
                {!! $rules !!}
                @keyframes door-pulse { 0%,100% { stroke-opacity: 1 } 50% { stroke-opacity: .35 } }
                @media (prefers-reduced-motion: reduce) {
                    #floor-plan [id^="room-"] { animation: none !important }
                }
                #floor-plan [id^="room-"] { transition: fill .25s ease }
                #floor-plan [id^="room-"]:hover { filter: brightness(.95) }
            </style>

            <div class="hidden overflow-hidden rounded-xl border border-slate-200 bg-white p-4 md:block">
                {{--
                    Clicks are caught here and traced back up to the nearest
                    element whose id starts "room-". Delegating rather than
                    wiring each shape means a redrawn plan needs no rewiring at
                    all — a shape that keeps its id keeps its behaviour.
                --}}
                <div id="floor-plan" class="relative"
                     x-data="{
                         rooms: {{ Js::from($shapeMap) }},
                         pick(event) {
                             let node = event.target
                             while (node && node !== this.$el) {
                                 if (node.id && this.rooms[node.id] !== undefined) {
                                     $wire.selectRoom(this.rooms[node.id])
                                     return
                                 }
                                 node = node.parentElement
                             }
                         }
                     }"
                     @click="pick($event)">
                    {!! $svg !!}

                    {{-- Badges, positioned as a percentage of the drawing so
                         they stay on their door at any size. --}}
                    @foreach ($rooms as $r)
                        @php $st = $states[$r->id] ?? null; @endphp
                        @if ($st && $r->hasBadgePosition() && $r->type->carriesBadge() && $st['waiting'] > 0)
                            <button type="button" wire:click="selectRoom({{ $r->id }})"
                                    class="absolute -translate-x-1/2 -translate-y-1/2 rounded-full px-2.5 py-1 text-xs font-bold shadow-sm ring-2 ring-white transition hover:scale-110
                                           {{ $st['overdue'] > 0 ? 'bg-red-600 text-white' : 'bg-amber-500 text-white' }}"
                                    style="left: {{ $r->centroid_x }}%; top: {{ $r->centroid_y }}%"
                                    title="{{ $r->name }}: {{ $st['waiting'] }} waiting{{ $st['overdue'] > 0 ? ", {$st['overdue']} overdue" : '' }}">
                                {{ $st['waiting'] > 99 ? '99+' : $st['waiting'] }}
                            </button>
                        @endif
                    @endforeach
                </div>
            </div>
        @else
            <div class="hidden rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500 md:block">
                There is no plan drawn for {{ $floor->name }} yet. Its rooms are listed below.
            </div>
        @endif

        {{-- The room list. The map's equal, not its footnote. --}}
        <div>
            <h2 class="text-base font-semibold text-slate-900">Rooms on this floor</h2>

            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($rooms as $r)
                    @php
                        $st = $states[$r->id] ?? null;
                        $state = $st['state'] ?? \App\Enums\DoorState::Vacant;
                    @endphp

                    <div wire:key="room-{{ $r->id }}"
                         @class([
                             'rounded-xl border bg-white p-4 transition',
                             'border-slate-200' => $r->id !== $selectedRoomId,
                             'border-blue-600 ring-1 ring-blue-600' => $r->id === $selectedRoomId,
                         ])>
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                @if ($r->type->isNavigable())
                                    <button type="button" wire:click="selectRoom({{ $r->id }})"
                                            class="text-left font-medium text-blue-700 hover:underline">
                                        {{ $r->name }}
                                    </button>
                                @else
                                    <span class="font-medium text-slate-700">{{ $r->name }}</span>
                                @endif

                                <span class="mt-0.5 block text-xs text-slate-500">
                                    {{ $r->department?->displayName() ?? $r->type->label() }}
                                    @if ($st && ! $st['canOpen'] && $r->department)
                                        · another office
                                    @endif
                                </span>
                            </div>

                            <div class="flex shrink-0 flex-col items-end gap-1">
                                @if ($st && $st['showsState'])
                                    <x-status-badge :tone="$state->tone()" :label="$state->label()" />
                                @elseif ($r->department)
                                    <x-status-badge tone="slate" :label="$r->type->label()" />
                                @else
                                    <x-status-badge tone="slate" :label="$state->label()" />
                                @endif
                                @if ($st && $st['waiting'] > 0)
                                    {{-- Built in PHP rather than inline directives: Blade will not
                                         compile an @if that follows a word character directly, and
                                         the stray @endif takes the whole page down. --}}
                                    <span class="text-xs text-slate-500">
                                        {{ $st['overdue'] > 0
                                            ? "{$st['waiting']} waiting, {$st['overdue']} late"
                                            : "{$st['waiting']} waiting" }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Drill-down --}}
        @if ($selected)
            <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-slate-900">{{ $selected->name }}</h2>
                        <p class="mt-1 text-sm text-slate-600">
                            {{ $selected->department?->name ?? 'No office has been assigned to this room yet.' }}
                        </p>
                    </div>

                    <button type="button" wire:click="clearRoom"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                        Close
                    </button>
                </div>

                @if ($selected->department && $selectedState)
                    <div class="mt-5 grid gap-4 sm:grid-cols-3">
                        @foreach ([
                            ['To receive', $selectedState['incoming']],
                            ['On their desk', $selectedState['onDesk']],
                            ['Overdue', $selectedState['overdue']],
                        ] as [$label, $value])
                            <div class="rounded-lg bg-slate-50 px-4 py-3">
                                <p class="text-xs uppercase tracking-wide text-slate-500">{{ $label }}</p>
                                <p @class([
                                    'mt-0.5 text-2xl font-semibold',
                                    'text-red-700' => $label === 'Overdue' && $value > 0,
                                    'text-slate-900' => ! ($label === 'Overdue' && $value > 0),
                                ])>{{ $value }}</p>
                            </div>
                        @endforeach
                    </div>

                    @if ($selectedDocuments->isNotEmpty())
                        <ul class="mt-5 divide-y divide-slate-100 border-t border-slate-100">
                            @foreach ($selectedDocuments as $document)
                                <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                    <a href="{{ route('documents.show', $document) }}" wire:navigate class="min-w-0">
                                        <span class="font-mono text-xs font-semibold text-blue-700">{{ $document->tracking_no }}</span>
                                        <span class="mt-0.5 block truncate text-sm text-slate-800">{{ $document->subject }}</span>
                                    </a>
                                    @if ($document->due_at)
                                        <span class="text-xs {{ $document->isOverdue() ? 'font-medium text-red-700' : 'text-slate-500' }}">
                                            Due {{ ph_date($document->due_at) }}
                                        </span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @elseif (! $selectedState['canOpen'])
                        <p class="mt-5 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
                            The counts above are what is sitting with
                            {{ $selected->department->displayName() }} right now. Opening their documents
                            is a different matter — you can read a document once it has passed through
                            your own office.
                        </p>
                    @else
                        <p class="mt-5 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-600">
                            Nothing is sitting on this office's desk.
                        </p>
                    @endif

                    @if ($selectedState['canOpen'])
                        <a href="{{ route('desk') }}" wire:navigate
                           class="mt-5 inline-block rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                            Open my desk
                        </a>
                    @endif
                @else
                    <p class="mt-5 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        This room is on the plan but no office has been mapped to it. An administrator
                        can assign one on the Rooms screen — until then it stays grey rather than being
                        attached to the wrong office.
                    </p>
                @endif
            </div>
        @endif
    @endif
</div>
