<?php

namespace App\Http\Controllers;

use App\Models\CompoundTile;
use App\Services\AuditLogger;
use App\Support\Compound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Laying a path, and lifting one.
 *
 * The streets and the plaza were a pair of constants in PHP for a while, which
 * drew a perfectly good compound and made it one nobody could change. Paving is
 * a thing you do to a place.
 *
 * Only inside the ground the municipality has taken in: you may pave what you
 * own. Grass is not a kind of paving — a cell painted grass has its row deleted
 * rather than set — so a compound that has never been touched has an empty
 * table rather than seven hundred rows saying nothing happened here.
 *
 * Arrives as a batch because it is painted with a dragged brush, and one
 * request per cell would be forty requests and forty audit entries for one
 * stroke.
 */
class CompoundTileController extends Controller
{
    public function __invoke(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'tiles' => ['required', 'array', 'min:1', 'max:'.(Compound::MAX * Compound::MAX)],
            'tiles.*.x' => ['required', 'integer', 'min:0', 'max:'.(Compound::MAX - 1)],
            'tiles.*.y' => ['required', 'integer', 'min:0', 'max:'.(Compound::MAX - 1)],
            'tiles.*.kind' => ['required', Rule::in([...Compound::GROUNDS, 'g'])],
        ]);

        $unlocked = Compound::unlockedDistricts();

        foreach ($data['tiles'] as $tile) {
            if (! Compound::isUnlocked($tile['x'], $tile['y'], $unlocked)) {
                throw ValidationException::withMessages([
                    'tiles' => 'That ground is not part of the compound yet.',
                ]);
            }
        }

        DB::transaction(function () use ($data, $request) {
            foreach ($data['tiles'] as $tile) {
                if ($tile['kind'] === 'g') {
                    CompoundTile::query()->where('x', $tile['x'])->where('y', $tile['y'])->delete();

                    continue;
                }

                CompoundTile::updateOrCreate(
                    ['x' => $tile['x'], 'y' => $tile['y']],
                    ['kind' => $tile['kind'], 'updated_by' => $request->user()->getKey()],
                );
            }
        });

        $audit->log(
            event: 'compound.ground_laid',
            properties: ['cells' => count($data['tiles'])],
            description: count($data['tiles']) === 1
                ? 'Changed the ground under one cell of the compound.'
                : 'Changed the ground under '.count($data['tiles']).' cells of the compound.',
        );

        return response()->json(['ground' => Compound::ground()]);
    }
}
