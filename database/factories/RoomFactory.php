<?php

namespace Database\Factories;

use App\Enums\RoomType;
use App\Models\Department;
use App\Models\Floor;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    protected $model = Room::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'floor_id' => Floor::factory(),
            'room_no' => null,
            'name' => $name.' Room',
            'type' => RoomType::Office,
            // Unassigned by default: a room on a plan is a room until somebody
            // says whose it is, and that is the state the map has to handle.
            'department_id' => null,
            'svg_shape_id' => 'room-'.Str::slug($name).'-'.fake()->unique()->numberBetween(1, 9999),
            'centroid_x' => fake()->randomFloat(2, 5, 95),
            'centroid_y' => fake()->randomFloat(2, 5, 95),
            'sort_order' => fake()->numberBetween(1, 500),
        ];
    }

    /** Named forOffice, not for — Factory::for() is the relationship helper. */
    public function forOffice(Department $office): static
    {
        return $this->state(fn () => ['department_id' => $office->getKey()]);
    }

    public function onFloor(Floor $floor): static
    {
        return $this->state(fn () => ['floor_id' => $floor->getKey()]);
    }

    public function type(RoomType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    /** Not drawn on any plan — listed, but with no shape to colour. */
    public function undrawn(): static
    {
        return $this->state(fn () => [
            'svg_shape_id' => null,
            'centroid_x' => null,
            'centroid_y' => null,
        ]);
    }
}
