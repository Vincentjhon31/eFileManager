<?php

namespace App\Support;

use App\Enums\DocumentStatus;
use App\Enums\DoorState;
use App\Enums\Permission;
use App\Enums\RouteStatus;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What every door on a floor is showing, worked out in two queries.
 *
 * This runs on a screen that refreshes every thirty seconds while somebody
 * leaves it open on a wall display, so it counts the whole building in two
 * grouped queries rather than one per room. Twenty rooms polling individually
 * would be forty queries a minute, all day, for a picture that mostly does not
 * change.
 *
 * The counts deliberately cover every office, not only the viewer's. See
 * DoorState for why: a count is what a stack of folders on a desk tells anyone
 * walking past. Opening the door is still governed by the same rule as
 * everywhere else — you can see a document if it has passed through your
 * office — and that is decided per room by canOpen.
 */
class DoorStates
{
    /**
     * @param  Collection<int, Room>  $rooms
     * @return array<int, array{
     *     state: DoorState, showsState: bool, waiting: int, overdue: int,
     *     incoming: int, onDesk: int, canOpen: bool
     * }>  keyed by room id
     */
    public static function for(Collection $rooms, ?User $viewer): array
    {
        $officeIds = $rooms->pluck('department_id')->filter()->unique()->values();

        if ($officeIds->isEmpty()) {
            return $rooms->mapWithKeys(fn (Room $room) => [$room->getKey() => self::vacant()])->all();
        }

        $incoming = self::incomingCounts($officeIds);
        $desks = self::deskCounts($officeIds);

        $seesEverything = (bool) $viewer?->can(Permission::DocumentsViewAllDepartments->value);

        return $rooms->mapWithKeys(function (Room $room) use ($incoming, $desks, $viewer, $seesEverything) {
            $officeId = $room->department_id;

            if (! $officeId) {
                return [$room->getKey() => self::vacant()];
            }

            $awaitingReceipt = (int) ($incoming[$officeId] ?? 0);
            $onDesk = (int) ($desks[$officeId]['total'] ?? 0);
            $overdue = (int) ($desks[$officeId]['overdue'] ?? 0);
            $waiting = $awaitingReceipt + $onDesk;

            return [$room->getKey() => [
                'state' => DoorState::decide(true, $waiting, $overdue),

                /*
                 * Whether that state should be painted on the door.
                 *
                 * Only a room where papers actually sit. Tinting the SB Session
                 * Hall amber because the Sangguniang Bayan has work waiting
                 * would be a small lie — nothing is waiting *there*, it is
                 * waiting in their office. The counts are still computed and
                 * still shown when the room is opened; the door just does not
                 * claim something the building would not.
                 */
                'showsState' => $room->type->carriesBadge(),

                'waiting' => $waiting,
                'overdue' => $overdue,
                'incoming' => $awaitingReceipt,
                'onDesk' => $onDesk,
                'canOpen' => $seesEverything || $viewer?->department_id === $officeId,
            ]];
        })->all();
    }

    /** Transmittals sent to each office that nobody has signed for. */
    private static function incomingCounts(Collection $officeIds): array
    {
        return DocumentRoute::query()
            ->where('status', RouteStatus::Pending->value)
            ->whereIn('to_department_id', $officeIds)
            ->groupBy('to_department_id')
            ->selectRaw('to_department_id, count(*) as total')
            ->pluck('total', 'to_department_id')
            ->all();
    }

    /**
     * What each office is holding, and how much of it is late.
     *
     * One pass. Counting overdue separately would double the queries to learn
     * something the same rows already know.
     */
    private static function deskCounts(Collection $officeIds): array
    {
        return Document::query()
            ->where('status', DocumentStatus::Received->value)
            ->whereIn('current_holder_department_id', $officeIds)
            ->groupBy('current_holder_department_id')
            ->selectRaw(
                'current_holder_department_id as office_id, count(*) as total, '
                .'sum(case when due_at is not null and due_at < ? then 1 else 0 end) as overdue',
                [now()]
            )
            ->get()
            ->mapWithKeys(fn ($row) => [
                (int) $row->office_id => ['total' => (int) $row->total, 'overdue' => (int) $row->overdue],
            ])
            ->all();
    }

    /** @return array{state: DoorState, showsState: bool, waiting: int, overdue: int, incoming: int, onDesk: int, canOpen: bool} */
    private static function vacant(): array
    {
        return [
            'state' => DoorState::Vacant,
            'showsState' => false,
            'waiting' => 0,
            'overdue' => 0,
            'incoming' => 0,
            'onDesk' => 0,
            'canOpen' => false,
        ];
    }
}
