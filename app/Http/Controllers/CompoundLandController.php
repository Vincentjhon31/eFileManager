<?php

namespace App\Http\Controllers;

use App\Models\CompoundDistrict;
use App\Models\CompoundTile;
use App\Services\AuditLogger;
use App\Support\Compound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Taking in a block of the compound's ground, and giving one back.
 *
 * Gated on settings.manage, like arranging: how far the compound extends is the
 * municipality's own picture of itself rather than any one office's business.
 *
 * There is always somewhere left to take in. The compound has no fixed size —
 * it is as big as its blocks and no bigger — so the answer to "can we grow" is
 * yes, every time, until the backstop in App\Support\Compound is reached a very
 * long way past anything a town hall will build.
 *
 * Giving one back was refused outright for a while, on the reasoning that a rule
 * about which blocks may be handed back is a rule that would be got wrong once
 * and lose a building. The rule turns out to be three short conditions and they
 * live in Compound::removable(), which is also what draws the list — so the
 * button and the route cannot come to different answers about the same block.
 * What is refused here is refused because it would break something.
 */
class CompoundLandController extends Controller
{
    /** Take another block of ground into the compound. */
    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->block($request);

        if (Compound::isUnlocked($data['dx'] * Compound::DISTRICT, $data['dy'] * Compound::DISTRICT)) {
            // Two people pressing the same padlock is not an error worth a
            // failure page — the land is open either way.
            throw ValidationException::withMessages([
                'dx' => 'That ground is already part of the compound.',
            ]);
        }

        /*
         * Only ground the compound already touches.
         *
         * The same rule the frontier is drawn from, asked again here because
         * this is a route and the drawing is a suggestion. Without it somebody
         * could take in a block eight kilometres to the north-east, and the
         * compound would be two specks with a vast empty grid between them —
         * which is not a compound, it is a coordinate mistake made permanent.
         */
        $offered = collect(Compound::frontier())
            ->contains(fn (array $block) => $block['dx'] === $data['dx'] && $block['dy'] === $data['dy']);

        if (! $offered) {
            throw ValidationException::withMessages([
                'dx' => 'The compound has to grow outwards from where it already is.',
            ]);
        }

        $district = CompoundDistrict::create([
            'dx' => $data['dx'],
            'dy' => $data['dy'],
            'unlocked_by' => $request->user()->getKey(),
        ]);

        $audit->log(
            event: 'compound.land_taken',
            subject: $district,
            properties: ['dx' => $district->dx, 'dy' => $district->dy],
            description: 'Took another block of ground into the compound.',
        );

        return response()->json($this->theLandNow());
    }

    /**
     * Give a block back.
     *
     * Its paving goes with it. Ground the municipality does not hold is not
     * ground with a path on it, and a block that came back a year later still
     * carrying the paving somebody laid before it was given up would be a
     * surprise nobody asked for.
     */
    public function destroy(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->block($request);

        if (! Compound::isUnlocked($data['dx'] * Compound::DISTRICT, $data['dy'] * Compound::DISTRICT)) {
            throw ValidationException::withMessages([
                'dx' => 'That ground is not part of the compound.',
            ]);
        }

        if (! Compound::isRemovable($data['dx'], $data['dy'])) {
            throw ValidationException::withMessages([
                'dx' => 'That block cannot be given back — something is standing on it, '
                    .'the rest of the compound would be cut in two, or it is the last one left.',
            ]);
        }

        $district = CompoundDistrict::query()
            ->where('dx', $data['dx'])
            ->where('dy', $data['dy'])
            ->firstOrFail();

        DB::transaction(function () use ($district, $data) {
            CompoundTile::query()
                ->whereBetween('x', [
                    $data['dx'] * Compound::DISTRICT,
                    ($data['dx'] + 1) * Compound::DISTRICT - 1,
                ])
                ->whereBetween('y', [
                    $data['dy'] * Compound::DISTRICT,
                    ($data['dy'] + 1) * Compound::DISTRICT - 1,
                ])
                ->delete();

            $district->delete();
        });

        $audit->log(
            event: 'compound.land_given_back',
            properties: ['dx' => $data['dx'], 'dy' => $data['dy']],
            description: 'Gave a block of ground back out of the compound.',
        );

        return response()->json($this->theLandNow());
    }

    /** @return array<string, int> */
    private function block(Request $request): array
    {
        return $request->validate([
            'dx' => ['required', 'integer', 'min:0', 'max:'.(Compound::MAX_DISTRICTS - 1)],
            'dy' => ['required', 'integer', 'min:0', 'max:'.(Compound::MAX_DISTRICTS - 1)],
        ]);
    }

    /**
     * The compound's ground as it now stands, for the renderer.
     *
     * The size comes back with the answer because taking in or giving up a
     * block along an edge moves the whole boundary, and the renderer cannot
     * work that out for itself without reimplementing extent() in JavaScript.
     * The ground comes too: a wider compound needs longer rows, a taller one
     * needs rows that did not exist a moment ago, and a smaller one needs the
     * paving that went with the block it just gave up.
     *
     * @return array<string, mixed>
     */
    private function theLandNow(): array
    {
        [$cols, $rows] = Compound::extent();

        return [
            'unlocked' => array_values(Compound::unlockedDistricts()),
            'land' => Compound::frontier(),
            'giveBack' => Compound::removable(),
            'cols' => $cols,
            'rows' => $rows,
            'ground' => Compound::ground(),
        ];
    }
}
