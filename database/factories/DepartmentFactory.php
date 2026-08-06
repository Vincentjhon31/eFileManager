<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /**
     * Defaults to an internal office that is NOT onboarded, because that is the
     * majority case during the pilot — most offices are valid routing targets
     * long before their staff have accounts.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'code' => Str::upper(Str::substr(Str::slug($name, ''), 0, 8)).fake()->unique()->numberBetween(1, 999),
            'name' => "Municipal {$name} Office",
            'short_name' => Str::limit($name, 40, ''),
            'is_onboarded' => false,
            'is_external' => false,
            'sort_order' => fake()->numberBetween(1, 500),
        ];
    }

    /** An office whose staff sign in and receive documents digitally. */
    public function onboarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_onboarded' => true,
            'is_external' => false,
        ]);
    }

    /** A provincial, national, barangay or private party. Never gets accounts. */
    public function external(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_external' => true,
            'is_onboarded' => false,
        ]);
    }
}
