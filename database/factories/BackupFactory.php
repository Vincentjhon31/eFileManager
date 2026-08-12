<?php

namespace Database\Factories;

use App\Enums\BackupType;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Backup>
 */
class BackupFactory extends Factory
{
    protected $model = Backup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $type = fake()->randomElement(BackupType::cases());

        return [
            'type' => $type,
            'disk_path' => $type->value.'/'.fake()->unique()->numerify('backup-##########.tmp'),
            'size' => fake()->numberBetween(1_000, 50_000_000),
            'created_by' => User::factory(),
        ];
    }

    public function type(BackupType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }
}
