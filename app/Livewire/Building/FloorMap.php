<?php

namespace App\Livewire\Building;

use App\Models\Document;
use App\Models\Floor;
use App\Models\Room;
use App\Support\DoorStates;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The building.
 *
 * Click the municipal hall, see inside; click a room, see that office's desk.
 *
 * The map is a *view* of the document system and holds nothing of its own. Every
 * number on it comes from the same queries that drive My Desk, and deleting
 * every room would take the picture away and change nothing about what the LGU
 * can do. That is what keeps a delightful idea from becoming a maintenance trap
 * — and it is why the plan can be redrawn by a draughtsman without a migration.
 *
 * Refreshes on a timer rather than a socket. Hostinger has no persistent
 * process for Reverb to live in, and a document count that is up to half a
 * minute stale is indistinguishable from live to somebody glancing at a wall
 * display.
 */
class FloorMap extends Component
{
    /*
     * Named for what they hold, not for what the view calls them. Livewire
     * shares public properties with the template, so a property called $floor
     * would shadow the Floor model the view needs under the same name — and the
     * failure is a "property id on string" a long way from its cause.
     */
    #[Url(as: 'floor', except: '')]
    public string $floorSlug = '';

    #[Url(as: 'room', except: null)]
    public ?int $selectedRoomId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);

        if ($this->floorSlug === '') {
            // Open on the floor that has a drawing — at pilot, the second.
            $this->floorSlug = Floor::query()->mapped()->orderByDesc('sort_order')->value('slug')
                ?? Floor::query()->orderBy('sort_order')->value('slug')
                ?? '';
        }
    }

    public function selectRoom(int $id): void
    {
        $this->selectedRoomId = $id;
    }

    public function clearRoom(): void
    {
        $this->selectedRoomId = null;
    }

    public function showFloor(string $slug): void
    {
        $this->floorSlug = $slug;
        $this->selectedRoomId = null;
    }

    public function render()
    {
        $user = Auth::user();

        $floor = Floor::query()->with('building')->where('slug', $this->floorSlug)->first();
        $rooms = $floor
            ? $floor->rooms()->with('department')->get()
            : collect();

        $states = DoorStates::for($rooms, $user);

        $selected = $rooms->firstWhere('id', $this->selectedRoomId);

        return view('livewire.building.floor-map', [
            'floor' => $floor,
            'floors' => Floor::query()->with('building')->orderBy('sort_order')->get(),
            'svg' => $floor?->hasDrawing() ? $floor->svg() : null,
            'rooms' => $rooms,
            'states' => $states,
            // Shape id to room id, for the click delegation on the drawing.
            // Only navigable rooms are listed, so clicking a comfort room does
            // nothing at all rather than opening an empty panel.
            'shapeMap' => $rooms
                ->filter(fn (Room $r) => $r->svg_shape_id && $r->type->isNavigable())
                ->pluck('id', 'svg_shape_id'),
            'selected' => $selected,
            'selectedState' => $selected ? ($states[$selected->id] ?? null) : null,
            // Confined by visibleTo, so a clerk peering into another office's
            // room sees the true count on the door and only the documents that
            // have actually passed through their own hands.
            'selectedDocuments' => $selected?->department_id
                ? Document::query()
                    ->visibleTo($user)
                    ->onDeskOf($selected->department_id)
                    ->with(['type', 'currentHolderUser'])
                    ->orderByRaw('due_at is null, due_at asc')
                    ->limit(8)
                    ->get()
                : collect(),
        ])->layout('components.layouts.app', ['title' => 'Building']);
    }
}
