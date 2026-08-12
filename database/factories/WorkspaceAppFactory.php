<?php

namespace Database\Factories;

use App\Enums\WorkspaceAppScope;
use App\Enums\WorkspaceAppStatus;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceApp;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WorkspaceApp>
 */
class WorkspaceAppFactory extends Factory
{
    protected $model = WorkspaceApp::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'description' => fake()->sentence(),
            'url' => fake()->url(),
            'icon_glyph' => mb_strtoupper(mb_substr($name, 0, 1)),
            'status' => WorkspaceAppStatus::Live,
            'scope' => WorkspaceAppScope::Department,
            'department_id' => Department::factory(),
            'sort_order' => 0,
            'created_by' => User::factory(),
        ];
    }

    public function forOffice(Department $office): static
    {
        return $this->state(fn () => ['department_id' => $office->getKey()]);
    }

    public function scope(WorkspaceAppScope $scope): static
    {
        return $this->state(fn () => ['scope' => $scope]);
    }

    /** Visible to every office, regardless of which one runs it. */
    public function orgWide(): static
    {
        return $this->scope(WorkspaceAppScope::Organization);
    }

    /** Reachable with no account, the same audience as the public portal. */
    public function openToPublic(): static
    {
        return $this->scope(WorkspaceAppScope::Public);
    }

    public function status(WorkspaceAppStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function pilot(): static
    {
        return $this->status(WorkspaceAppStatus::Pilot);
    }

    public function retired(): static
    {
        return $this->status(WorkspaceAppStatus::Retired);
    }
}
