<?php

namespace Database\Factories;

use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    protected $model = Building::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'code' => Str::upper(Str::substr(Str::slug($name, ''), 0, 8)).fake()->unique()->numberBetween(1, 999),
            'name' => $name.' Building',
            'description' => null,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }
}
