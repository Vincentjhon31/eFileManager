@props(['steps', 'autoStart'])

{{--
    Persisted across wire:navigate page swaps so a tour in progress survives a
    click on whatever it is currently pointing at, instead of restarting from
    step one the moment the page underneath it changes.
--}}
@persist('tour-guide')
<div x-data="tourGuide(@js($steps), @js($autoStart))" x-cloak>

    {{-- Dims the page for the intro/outro cards, which have nothing to point at. --}}
    <div x-show="$store.tour.open && !rect" x-transition.opacity
         class="fixed inset-0 z-40 bg-slate-900/40"></div>

    {{-- The ring around whatever the current step is pointing at. --}}
    <template x-if="$store.tour.open && rect">
        <div class="pointer-events-none fixed z-40 rounded-lg ring-4 ring-blue-500 ring-offset-2 transition-all duration-200"
             :style="`top:${rect.top - 4}px;left:${rect.left - 4}px;width:${rect.width + 8}px;height:${rect.height + 8}px;`">
        </div>
    </template>

    <template x-if="$store.tour.open && step">
        <div class="fixed z-50 w-80" :style="cardStyle()">
            <div class="rounded-xl bg-white p-5 shadow-xl ring-1 ring-slate-900/10">
                <p class="text-xs font-semibold uppercase tracking-wide text-blue-700"
                   x-text="`Step ${index + 1} of ${steps.length}`"></p>
                <h3 class="mt-1 text-base font-semibold text-slate-900" x-text="step.title"></h3>
                <p class="mt-2 text-sm text-slate-600" x-text="step.body"></p>

                <div class="mt-4 flex items-center justify-between gap-3">
                    <button type="button" @click="finish()"
                            class="text-sm font-medium text-slate-500 hover:text-slate-700">
                        Skip tour
                    </button>
                    <div class="flex gap-2">
                        <button type="button" x-show="!isFirst" @click="prev()"
                                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-50">
                            Back
                        </button>
                        <button type="button" @click="next()"
                                class="rounded-lg bg-blue-700 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-blue-800"
                                x-text="isLast ? 'Done' : 'Next'"></button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
@endpersist
