<?php

namespace Database\Factories;

use App\Enums\ActionRequested;
use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DocumentType>
 */
class DocumentTypeFactory extends Factory
{
    protected $model = DocumentType::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'code' => Str::upper(Str::substr(Str::slug($name, ''), 0, 8)).fake()->unique()->numberBetween(1, 999),
            'name' => $name,
            'description' => fake()->sentence(),
            'default_action' => fake()->randomElement(ActionRequested::all()),
            'retention_years' => 5,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 500),
        ];
    }

    /** Ordinances, resolutions and appointment papers are never disposed of. */
    public function permanent(): static
    {
        return $this->state(fn () => ['retention_years' => null]);
    }
}
