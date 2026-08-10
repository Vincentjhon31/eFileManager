<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\Floor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Floor>
 */
class FloorFactory extends Factory
{
    protected $model = Floor::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $level = fake()->unique()->numberBetween(1, 40);

        return [
            'building_id' => Building::factory(),
            'level' => $level,
            'name' => "Floor {$level}",
            'slug' => 'floor-'.$level.'-'.Str::lower(Str::random(4)),
            // No drawing by default: a floor that has not been surveyed yet is
            // the normal case, and the map must degrade to a room list.
            'svg_path' => null,
            'has_map' => false,
            'sort_order' => $level * 10,
        ];
    }

    /** Points at the real second-floor plan, so the drawing can be exercised. */
    public function drawn(string $path = 'floors/hall-second-floor.svg'): static
    {
        return $this->state(fn () => ['svg_path' => $path, 'has_map' => true]);
    }
}
