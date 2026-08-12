<x-settings.shell heading="Notifications"
                  description="What the system may send you, and what it will always show you.">

    @unless ($digestEnabledGlobally)
        <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
            An administrator has turned the morning digest off for the whole municipality.
            Your choice below is saved, but nothing will be sent until it is turned back on.
        </div>
    @endunless

    <form wire:submit="save" class="space-y-6">
        <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900">Email</h3>
            <p class="mt-1 text-xs text-slate-500">
                Sent to {{ $user->email }}.
            </p>

            <div class="mt-4 space-y-4">
                <label class="flex items-start gap-3 text-sm">
                    <input type="checkbox" wire:model.live="digest_email"
                           class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-600">
                    <span>
                        <span class="font-medium text-slate-800">Morning desk digest</span>
                        <span class="block text-slate-500">
                            One message on weekday mornings, around {{ $digestTime }}, listing what is waiting
                            for you. Nothing is sent on a day when your desk is clear.
                        </span>
                    </span>
                </label>

                @if ($canSeeOfficeSummary)
                    <label class="flex items-start gap-3 text-sm" @class(['opacity-50' => ! $digest_email])>
                        <input type="checkbox" wire:model="digest_office_summary" @disabled(! $digest_email)
                               class="mt-0.5 rounded border-slate-300 text-blue-700 focus:ring-blue-600 disabled:opacity-40">
                        <span>
                            <span class="font-medium text-slate-800">Include the office summary</span>
                            <span class="block text-slate-500">
                                As well as your own papers: what is overdue across the office, what is waiting to
                                be received, and what you have released that nobody has taken yet. Turn this off
                                to hear only about documents assigned to you personally.
                            </span>
                        </span>
                    </label>
                @endif
            </div>
        </div>

        <div>
            <button type="submit"
                    class="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800">
                Save notification settings
            </button>
        </div>
    </form>

    {{--
        Stated rather than offered as a switch. A document arriving on your desk
        is the work itself, and somebody who had silenced it would simply stop
        receiving papers without knowing why.
    --}}
    <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
        <h3 class="text-sm font-semibold text-slate-900">In-app alerts</h3>
        <p class="mt-2 text-sm text-slate-600">
            Documents routed to you, receipts, and anything returned for correction always appear in
            <a href="{{ route('alerts') }}" wire:navigate class="font-medium text-blue-700 hover:underline">Alerts</a>.
            These cannot be turned off — they are how work reaches you, not a commentary on it.
            Reading an alert clears it and changes nothing about the document's record.
        </p>
    </div>
</x-settings.shell>
