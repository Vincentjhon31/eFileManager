<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\CompoundBuilding;
use App\Models\Department;
use App\Services\AuditLogger;
use App\Support\Compound;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Putting a building up, and taking one down.
 *
 * The compound stopped being a drawing of the offices and became the way the
 * offices are arranged; this is the step after that, where it becomes the way
 * one is added. Pick a design, pick an office, pick a colour, drop it where it
 * goes.
 *
 * Two permissions, not one, and the difference matters. **settings.manage** is
 * enough to put up a building for an office that already exists, because that
 * is a drawing decision. Bringing a whole new office into existence is not —
 * its code goes inside every tracking number the office ever issues and cannot
 * be changed afterwards — so that additionally needs **departments.manage**,
 * the same permission the Offices screen has always been behind.
 *
 * Removing a building removes the building. The office stays: it is a row that
 * documents point at, and "delete the Treasurer's Office" is not something that
 * should be one click away on a map.
 */
class CompoundBuildingController extends Controller
{
    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $templates = collect(Compound::templates())->keyBy('id');

        $data = $request->validate([
            'template' => ['required', Rule::in($templates->keys()->all())],
            'gx' => ['required', 'integer', 'min:0', 'max:'.(Compound::MAX - 1)],
            'gy' => ['required', 'integer', 'min:0', 'max:'.(Compound::MAX - 1)],
            'wall' => ['required', Rule::in(collect(Compound::palette())->pluck('wall')->all())],
            'roof' => ['required', Rule::in(collect(Compound::palette())->pluck('roof')->all())],

            // One of the two, for an office template: an office that exists and
            // has nowhere to stand, or a new one.
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('is_external', false)],

            /*
             * Creating an office is a decision, not an inference.
             *
             * This used to be inferred from office_name being filled, which
             * meant a browser quietly autofilling that box was enough to bring a
             * whole department into existence — and it did, once, during
             * testing. An office code goes inside every tracking number that
             * office ever issues. It takes a flag.
             */
            'create_office' => ['nullable', 'boolean'],
            'office_name' => ['nullable', 'string', 'max:255', 'required_if:create_office,true'],
            'office_code' => ['nullable', 'string', 'max:12', 'regex:/^[A-Z0-9-]+$/', 'unique:departments,code'],
        ], attributes: [
            'office_code' => 'office code',
            'office_name' => 'office name',
        ]);

        $template = $templates->get($data['template']);
        $office = $this->officeFor($request, $template, $data);

        $building = new CompoundBuilding([
            'department_id' => $office?->getKey(),
            'sprite' => $template['sprite'],
            'style' => $template['style'],
            'gx' => $data['gx'],
            'gy' => $data['gy'],
            'w' => $template['w'],
            'h' => $template['h'],
            'height' => $template['height'],
            'wall' => $data['wall'],
            'roof' => $data['roof'],
            'updated_by' => $request->user()->getKey(),
        ]);

        $this->assertItFits($building);

        DB::transaction(fn () => $building->save());

        $audit->log(
            event: 'compound.building_added',
            subject: $building,
            properties: [
                'template' => $template['id'],
                'office' => $office?->code,
                'gx' => $building->gx,
                'gy' => $building->gy,
            ],
            description: $office
                ? "Put up a building for {$office->displayName()} in the compound."
                : "Put up a {$template['name']} in the compound.",
        );

        return response()->json([
            'places' => Compound::places($request->user()),
            'vacant' => Compound::officesWithoutABuilding(),
        ], 201);
    }

    /**
     * Changing a building that is already standing.
     *
     * Its design and its colours, and nothing else. Not which office it belongs
     * to — an office's building is where people are told to go, and quietly
     * turning the Treasurer's Office into the Health Office would be a change to
     * the directory dressed up as a change to a picture. Move it, repaint it,
     * rebuild it bigger; whose it is stays a decision made once.
     *
     * Which is also why the designs on offer are only those of the same kind:
     * an office building may be redesigned as a different office building, and
     * a bit of scenery as different scenery. Turning a flagpole into an office
     * would be asking, halfway through a repaint, which department it is for.
     *
     * Only settings.manage, not departments.manage. Nothing here brings an
     * office into existence, so nothing here needs the heavier permission.
     */
    public function update(Request $request, CompoundBuilding $building, AuditLogger $audit): JsonResponse
    {
        $kind = $building->department_id ? 'office' : 'scenery';

        $templates = collect(Compound::templates())
            ->filter(fn (array $template) => $template['kind'] === $kind)
            ->keyBy('id');

        $data = $request->validate([
            'template' => ['required', Rule::in($templates->keys()->all())],
            'wall' => ['required', Rule::in(collect(Compound::palette())->pluck('wall')->all())],
            'roof' => ['required', Rule::in(collect(Compound::palette())->pluck('roof')->all())],
        ]);

        $template = $templates->get($data['template']);
        $was = $building->sprite.'/'.$building->style;

        $building->fill([
            'sprite' => $template['sprite'],
            'style' => $template['style'],
            'w' => $template['w'],
            'h' => $template['h'],
            'height' => $template['height'],
            'wall' => $data['wall'],
            'roof' => $data['roof'],
            'updated_by' => $request->user()->getKey(),
        ]);

        /*
         * Asked again after the change, because a design change is a size
         * change: a two-by-two rebuilt as a four-by-two grows east and south
         * into whatever was there, which may be the neighbouring office or the
         * edge of the ground the municipality holds.
         */
        $this->assertItFits($building);

        DB::transaction(fn () => $building->save());

        $audit->log(
            event: 'compound.building_changed',
            subject: $building,
            properties: [
                'template' => $template['id'],
                'office' => $building->department?->code,
                'was' => $was,
            ],
            description: 'Changed the '
                .($building->department?->displayName() ?? $template['name'])
                .' building in the compound.',
        );

        return response()->json([
            'places' => Compound::places($request->user()),
            'vacant' => Compound::officesWithoutABuilding(),
        ]);
    }

    public function destroy(Request $request, CompoundBuilding $building, AuditLogger $audit): JsonResponse
    {
        $name = $building->department?->displayName() ?? ucfirst($building->sprite);

        $audit->log(
            event: 'compound.building_removed',
            properties: [
                'office' => $building->department?->code,
                'sprite' => $building->sprite,
            ],
            // Said plainly, because it is the part people ask about afterwards.
            description: "Took the building for {$name} out of the compound. The office itself is untouched.",
        );

        $building->delete();

        return response()->json([
            'places' => Compound::places($request->user()),
            'vacant' => Compound::officesWithoutABuilding(),
        ]);
    }

    /**
     * The office this building belongs to, if any — found, made, or neither.
     */
    private function officeFor(Request $request, array $template, array $data): ?Department
    {
        if ($template['kind'] !== 'office') {
            return null;
        }

        if (filled($data['department_id'] ?? null)) {
            $office = Department::query()->internal()->findOrFail($data['department_id']);

            if ($office->building()->exists()) {
                throw ValidationException::withMessages([
                    'department_id' => $office->displayName().' already has a building in the compound.',
                ]);
            }

            return $office;
        }

        if (! ($data['create_office'] ?? false)) {
            throw ValidationException::withMessages([
                'department_id' => 'Choose which office this building is for.',
            ]);
        }

        /*
         * Making a new office. A heavier act than anything else on this screen:
         * the code is embedded in every tracking number the office will ever
         * issue and is treated as immutable once real documents exist.
         */
        abort_unless($request->user()->can(Permission::DepartmentsManage->value), 403);

        $code = Str::upper($data['office_code'] ?? '') ?: $this->codeFrom($data['office_name']);

        return Department::create([
            'code' => $code,
            'name' => $data['office_name'],
            'short_name' => Str::limit($data['office_name'], 60, ''),
            'is_external' => false,

            // Not onboarded: an office exists on the organisational chart long
            // before anybody in it has an account, and pretending otherwise
            // would let documents be routed somewhere nobody is looking.
            'is_onboarded' => false,
            'sort_order' => (int) Department::query()->internal()->max('sort_order') + 10,
        ]);
    }

    /** Initials, when somebody could not think of a code. */
    private function codeFrom(string $name): string
    {
        $code = Str::of($name)
            ->replaceMatches('/[^A-Za-z0-9 ]/', '')
            ->explode(' ')
            ->filter()
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))
            ->join('');

        $code = Str::limit($code, 10, '') ?: 'OFF';

        // Two offices called "Municipal Office" would otherwise both want MO.
        $candidate = $code;

        for ($n = 2; Department::query()->where('code', $candidate)->exists(); $n++) {
            $candidate = Str::limit($code, 10, '').$n;
        }

        return $candidate;
    }

    /**
     * Inside the compound's ground, and not on top of anything.
     *
     * The same two rules the layout editor enforces, asked again — this is a
     * different route, and a rule enforced on one of two ways in is not
     * enforced.
     *
     * A building already standing is not counted as standing on itself. Without
     * that, repainting the Mayor's Office would be refused on the grounds that
     * the Mayor's Office is in the way.
     */
    private function assertItFits(CompoundBuilding $building): void
    {
        if (! Compound::isBuildable($building->gx, $building->gy, $building->w, $building->h)) {
            throw ValidationException::withMessages([
                'gx' => 'That ground is not part of the compound yet.',
            ]);
        }

        $clash = CompoundBuilding::all()
            ->reject(fn (CompoundBuilding $other) => $other->is($building))
            ->first(fn (CompoundBuilding $other) => $building->overlaps($other));

        if ($clash) {
            throw ValidationException::withMessages([
                'gx' => 'Something is already standing there.',
            ]);
        }
    }
}
