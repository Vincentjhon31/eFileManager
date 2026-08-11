<?php

namespace Database\Factories;

use App\Enums\AnnouncementCategory;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Announcement>
 *
 * Builds a draft by default — published_at is left null, because that is the
 * state every real announcement starts in and the one most worth testing
 * against. Use published() to get a live one without going through
 * PublicationService, which is for testing the portal's listings; testing the
 * act of publishing itself should drive the service.
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $title = ucfirst(fake()->unique()->sentence(6));

        return [
            'title' => $title,
            'slug' => Announcement::slugFor($title),
            'category' => AnnouncementCategory::Notice,
            'excerpt' => null,
            'body' => fake()->paragraphs(3, true),
            'published_at' => null,
            'published_by' => null,
            'unpublished_at' => null,
            'unpublished_by' => null,
            'expires_at' => null,
            'is_pinned' => false,
            'department_id' => null,
            'author_id' => User::factory(),
        ];
    }

    public function forOffice(Department $office): static
    {
        return $this->state(fn () => ['department_id' => $office->getKey()]);
    }

    public function category(AnnouncementCategory $category): static
    {
        return $this->state(fn () => ['category' => $category]);
    }

    /** Live without going through PublicationService — for listing tests only. */
    public function published(): static
    {
        return $this->state(fn () => [
            'published_at' => now()->subDay(),
            'published_by' => User::factory(),
        ]);
    }

    public function pinned(): static
    {
        return $this->state(fn () => ['is_pinned' => true]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'published_at' => now()->subDays(10),
            'published_by' => User::factory(),
            'expires_at' => now()->subDay(),
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn () => [
            'published_at' => now()->subDays(5),
            'published_by' => User::factory(),
            'unpublished_at' => now()->subDay(),
            'unpublished_by' => User::factory(),
        ]);
    }
}
