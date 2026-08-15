<?php

namespace App\Http\Controllers;

use App\Models\CompoundBuilding;
use App\Services\AuditLogger;
use App\Support\Compound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Where the compound's buildings stand, after somebody moved them.
 *
 * The one place in the drawn half of this system that writes anything. Both
 * renderers have otherwise never made a request — no fetch, no CSRF, nothing —
 * and that was worth keeping until there was something a person could actually
 * change. Dragging the Treasurer's Office across the plaza is that something.
 *
 * Every rule the editor enforces in the browser is enforced again here, in
 * full: on ground the municipality has taken in, and not on top of another
 * building. The editor's version exists so the ghost under the cursor turns red
 * before the drop rather than after it — it is feedback, not enforcement, and a
 * request that arrives having ignored it is refused.
 */
class CompoundLayoutController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): JsonResponse
    {
        $moves = $request->validate([
            'buildings' => ['required', 'array', 'min:1', 'max:'.(Compound::MAX * Compound::MAX)],
            'buildings.*.id' => ['required', 'integer', 'exists:compound_buildings,id'],
            'buildings.*.gx' => ['required', 'integer', 'min:0', 'max:'.(Compound::MAX - 1)],
            'buildings.*.gy' => ['required', 'integer', 'min:0', 'max:'.(Compound::MAX - 1)],
        ])['buildings'];

        $buildings = CompoundBuilding::query()->with('department')->get()->keyBy('id');

        // Applied to the in-memory set first so the checks below see the whole
        // arrangement as it would be, not each move against the old one. Two
        // buildings swapping places is a legitimate pair of moves that would
        // fail if they were checked one at a time.
        foreach ($moves as $move) {
            $building = $buildings->get($move['id']);

            if (! $building) {
                throw ValidationException::withMessages([
                    'buildings' => 'One of those buildings is no longer in the compound.',
                ]);
            }

            $building->gx = $move['gx'];
            $building->gy = $move['gy'];
        }

        $this->assertEverythingFits($buildings);

        DB::transaction(function () use ($buildings, $moves, $request) {
            foreach ($moves as $move) {
                $buildings->get($move['id'])
                    ->forceFill(['updated_by' => $request->user()->getKey()])
                    ->save();
            }
        });

        $audit->log(
            event: 'compound.rearranged',
            properties: [
                'moved' => collect($moves)->map(fn (array $m) => [
                    'building' => $m['id'],
                    'office' => $buildings->get($m['id'])->department?->code,
                    'gx' => $m['gx'],
                    'gy' => $m['gy'],
                ])->all(),
            ],
            description: count($moves) === 1
                ? 'Moved a building in the compound.'
                : 'Moved '.count($moves).' buildings in the compound.',
        );

        return response()->json(['saved' => count($moves)]);
    }

    /**
     * @param  Collection<int, CompoundBuilding>  $buildings
     */
    private function assertEverythingFits($buildings): void
    {
        foreach ($buildings as $building) {
            if (! Compound::isBuildable($building->gx, $building->gy, $building->w, $building->h)) {
                throw ValidationException::withMessages([
                    'buildings' => $this->nameOf($building).' cannot stand there.',
                ]);
            }
        }

        foreach ($buildings as $building) {
            foreach ($buildings as $other) {
                if ($other->getKey() === $building->getKey()) {
                    continue;
                }

                if ($building->overlaps($other)) {
                    throw ValidationException::withMessages([
                        'buildings' => $this->nameOf($building).' would be standing on '
                            .$this->nameOf($other).'.',
                    ]);
                }
            }
        }
    }

    private function nameOf(CompoundBuilding $building): string
    {
        return $building->department?->displayName() ?? ucfirst($building->sprite);
    }
}
