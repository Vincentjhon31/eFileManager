<?php

namespace Database\Factories;

use App\Enums\FolderVisibility;
use App\Models\Department;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Folder>
 */
class FolderFactory extends Factory
{
    protected $model = Folder::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'department_id' => Department::factory(),
            'parent_id' => null,
            'name' => Str::title(fake()->unique()->words(2, true)),
            // The normal case: an ordinary folder belonging to one office.
            'visibility' => FolderVisibility::Department,
            'is_system' => false,
            'created_by' => User::factory(),
        ];
    }

    /** Named forOffice, not for — Factory::for() is the relationship helper. */
    public function forOffice(Department $office, ?User $by = null): static
    {
        return $this->state(fn () => [
            'department_id' => $office->getKey(),
            'created_by' => $by?->getKey(),
        ]);
    }

    public function inside(Folder $parent): static
    {
        return $this->state(fn () => [
            'parent_id' => $parent->getKey(),
            'department_id' => $parent->department_id,
        ]);
    }

    public function visibility(FolderVisibility $visibility): static
    {
        return $this->state(fn () => ['visibility' => $visibility]);
    }

    /** Shared with every office. */
    public function shared(): static
    {
        return $this->visibility(FolderVisibility::Internal);
    }

    /** The creator's alone, invisible even to their office head. */
    public function private(): static
    {
        return $this->visibility(FolderVisibility::Private);
    }

    public function system(): static
    {
        return $this->state(fn () => ['is_system' => true]);
    }
}
