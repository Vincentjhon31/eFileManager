<div class="space-y-6">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">Rooms</h1>
            <p class="mt-1 max-w-2xl text-sm text-slate-600">
                Which office works in which room. This is what decides whose caseload lights up a
                door on the building map, so it is worth getting right before anyone puts the map
                on a wall display.
            </p>
        </div>

        @if ($floors->count() > 1)
            <select wire:model.live="floorSlug"
                    class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600">
                @foreach ($floors as $option)
                    <option value="{{ $option->slug }}">{{ $option->displayName() }}</option>
                @endforeach
            </select>
        @endif
    </div>

    @if ($unassigned > 0)
        <div class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ $unassigned }} office{{ $unassigned === 1 ? '' : 's' }} on this floor
            {{ $unassigned === 1 ? 'has' : 'have' }} no municipal office mapped to
            {{ $unassigned === 1 ? 'it' : 'them' }}. They show as grey on the map rather than being
            attached to the wrong office.
        </div>
    @endif

    @if (! $floor)
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-10 text-center text-slate-500">
            No floors have been set up yet.
        </div>
    @else
        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th scope="col" class="px-4 py-3">Room</th>
                        <th scope="col" class="px-4 py-3">Kind</th>
                        <th scope="col" class="px-4 py-3">Office</th>
                        <th scope="col" class="px-4 py-3">On the plan</th>
                        <th scope="col" class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rooms as $room)
                        <tr wire:key="room-{{ $room->id }}" class="hover:bg-slate-50">
                            @if ($editingId === $room->id)
                                <td class="px-4 py-3 align-top">
                                    <span class="font-medium text-slate-900">{{ $room->name }}</span>
                                    <input wire:model="room_no" type="text" placeholder="Room no."
                                           class="mt-1 block w-28 rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600">
                                    @error('room_no') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </td>

                                <td class="px-4 py-3 align-top">
                                    <select wire:model="type"
                                            class="rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600">
                                        @foreach ($types as $kind)
                                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                                        @endforeach
                                    </select>
                                    @error('type') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </td>

                                <td class="px-4 py-3 align-top" colspan="2">
                                    <select wire:model="department_id"
                                            class="block w-full max-w-sm rounded-lg border-slate-300 text-sm shadow-sm focus:border-blue-600 focus:ring-blue-600">
                                        <option value="">Nobody — leave unassigned</option>
                                        @foreach ($offices as $office)
                                            <option value="{{ $office->id }}">
                                                {{ $office->code }} — {{ $office->displayName() }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('department_id') <p class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right align-top">
                                    <button type="button" wire:click="save"
                                            class="rounded-lg bg-blue-700 px-3 py-1.5 text-sm font-semibold text-white transition hover:bg-blue-800">
                                        Save
                                    </button>
                                    <button type="button" wire:click="cancel"
                                            class="ml-2 text-sm font-medium text-slate-600 hover:underline">
                                        Cancel
                                    </button>
                                </td>
                            @else
                                <td class="px-4 py-3 align-top">
                                    <span class="font-medium text-slate-900">{{ $room->name }}</span>
                                    @if ($room->room_no)
                                        <span class="mt-0.5 block text-xs text-slate-500">Room {{ $room->room_no }}</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 align-top text-slate-600">{{ $room->type->label() }}</td>

                                <td class="px-4 py-3 align-top">
                                    @if ($room->department)
                                        <span class="text-slate-800">{{ $room->department->displayName() }}</span>
                                        <span class="mt-0.5 block font-mono text-xs text-slate-500">{{ $room->department->code }}</span>
                                    @elseif ($room->type->isNavigable())
                                        <x-status-badge tone="amber" label="Not assigned" />
                                    @else
                                        <span class="text-slate-400">—</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 align-top">
                                    @if ($room->svg_shape_id)
                                        <span class="font-mono text-xs text-slate-500">{{ $room->svg_shape_id }}</span>
                                    @else
                                        <span class="text-xs text-slate-400">Not drawn</span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap px-4 py-3 text-right align-top">
                                    <button type="button" wire:click="edit({{ $room->id }})"
                                            class="text-sm font-medium text-blue-700 hover:underline">
                                        Edit
                                    </button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="text-xs text-slate-500">
            Room shapes come from the drawing at <code>{{ $floor->svg_path ?? 'no drawing yet' }}</code>.
            A draughtsman can redraw that file freely; as long as each room keeps its element id,
            nothing on this screen has to change.
        </p>
    @endif
</div>
