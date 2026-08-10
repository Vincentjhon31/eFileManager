<?php

namespace App\Support;

use App\Models\Document;
use App\Models\DocumentRoute;
use App\Models\User;

/**
 * The four numbers an employee cares about, in one place.
 *
 * Shared by the dashboard tiles and the My Desk tabs so the two can never
 * disagree — and these are the same counts that will light the doors on the
 * building map in Phase 5.
 */
class DeskCounts
{
    /** @return array{incoming: int, desk: int, awaiting: int, overdue: int} */
    public static function for(?User $user): array
    {
        $officeId = $user?->department_id;

        if (! $user || ! $officeId) {
            return ['incoming' => 0, 'desk' => 0, 'awaiting' => 0, 'overdue' => 0];
        }

        return [
            // Sent to us, not yet signed for.
            'incoming' => DocumentRoute::awaitingReceiptBy($officeId)->count(),

            // Signed for and sitting with us. Run through visibleTo so a badge
            // never promises a confidential document the user cannot open.
            'desk' => Document::query()->visibleTo($user)->onDeskOf($officeId)->count(),

            // Sent by us and still unsigned — the chase list, which is the one
            // thing paper cannot tell you.
            'awaiting' => DocumentRoute::releasedBy($officeId)->count(),

            'overdue' => Document::query()
                ->visibleTo($user)
                ->where('current_holder_department_id', $officeId)
                ->overdue()
                ->count(),
        ];
    }
}
