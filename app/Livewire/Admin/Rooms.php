<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Enums\RoomType;
use App\Models\Department;
use App\Models\Floor;
use App\Models\Room;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Say which office works in which room.
 *
 * The one screen that exists because a floor plan and an org chart are drawn by
 * different people. The plan labels a room "Office of the Municipal
 * Administrator"; the org chart has codes. Nobody but the LGU can reconcile
 * those two, so this is where they do it — rather than a developer guessing in
 * a seeder and putting the wrong office on a wall display.
 *
 * Deliberately narrow. Rooms and their shapes come from the drawing and the
 * seeder; the only thing editable here is the occupant, and the room number a
 * building has painted on its doors.
 */
class Rooms extends Component
{
    #[Url(except: '')]
    public string $floorSlug = '';

    public ?int $editingId = null;

    public ?int $department_id = null;

    public string $room_no = '';

    public string $type = 'office';

    public function mount(): void
    {
        // Same class of act as managing the offices themselves, and held by the
        // same people — there is no separate "rooms" permission to remember.
        $this->authorize(Permission::DepartmentsManage->value);

        if ($this->floorSlug === '') {
            $this->floorSlug = Floor::query()->orderByDesc('sort_order')->value('slug') ?? '';
        }
    }

    public function edit(int $id): void
    {
        $room = Room::findOrFail($id);

        $this->editingId = $room->id;
        $this->department_id = $room->department_id;
        $this->room_no = $room->room_no ?? '';
        $this->type = $room->type->value;

        $this->resetValidation();
    }

    public function save(AuditLogger $audit): void
    {
        // Same class of act as managing the offices themselves, and held by the
        // same people — there is no separate "rooms" permission to remember.
        $this->authorize(Permission::DepartmentsManage->value);

        $room = Room::findOrFail($this->editingId);

        $data = $this->validate([
            'department_id' => ['nullable', 'exists:departments,id'],
            'room_no' => ['nullable', 'string', 'max:16'],
            'type' => ['required', Rule::enum(RoomType::class)],
        ]);

        $before = $room->department?->code;

        $room->update([
            'department_id' => $data['department_id'] ?: null,
            'room_no' => $data['room_no'] ?: null,
            'type' => RoomType::from($data['type']),
        ]);

        $room->refresh();

        // Worth its own entry: this is what decides whose caseload lights up a
        // door, and getting it wrong is visible to the whole hall.
        $audit->log(
            event: 'room.assigned',
            subject: $room,
            properties: ['before' => $before, 'after' => $room->department?->code, 'type' => $room->type->value],
            description: sprintf(
                'Room “%s” assigned to %s.',
                $room->name,
                $room->department?->displayName() ?? 'no office',
            ),
        );

        $this->reset(['editingId', 'department_id', 'room_no', 'type']);
        session()->flash('status', "{$room->name} updated.");
    }

    public function cancel(): void
    {
        $this->reset(['editingId', 'department_id', 'room_no', 'type']);
        $this->resetValidation();
    }

    public function canManage(): bool
    {
        return Auth::user()->can(Permission::DepartmentsManage->value);
    }

    public function render()
    {
        $floor = Floor::query()->with('building')->where('slug', $this->floorSlug)->first();

        return view('livewire.admin.rooms', [
            'floor' => $floor,
            'floors' => Floor::query()->with('building')->orderBy('sort_order')->get(),
            'rooms' => $floor ? $floor->rooms()->with('department')->get() : collect(),
            'offices' => Department::internal()->orderBy('sort_order')->get(),
            'types' => RoomType::all(),
            'unassigned' => $floor
                ? $floor->rooms()->whereNull('department_id')->ofType(RoomType::Office)->count()
                : 0,
        ])->layout('components.layouts.app', ['title' => 'Rooms']);
    }
}
