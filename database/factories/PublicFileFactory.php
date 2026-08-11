<?php

namespace Database\Factories;

use App\Enums\DisclosureCategory;
use App\Models\File;
use App\Models\PublicFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublicFile>
 *
 * Defaults to prepared but not published, which is the state PublicationService
 * puts a new entry in. As with AnnouncementFactory, use published() to seed a
 * live listing quickly; drive PublicationService directly to test publishing.
 */
class PublicFileFactory extends Factory
{
    protected $model = PublicFile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'file_id' => File::factory(),
            'title' => ucfirst(fake()->unique()->words(3, true)),
            'description' => null,
            'category' => DisclosureCategory::Other,
            'fiscal_year' => null,
            'announcement_id' => null,
            'published_at' => null,
            'published_by' => null,
            'unpublished_at' => null,
            'unpublished_by' => null,
            'download_count' => 0,
            'created_by' => User::factory(),
        ];
    }

    public function forFile(File $file): static
    {
        return $this->state(fn () => ['file_id' => $file->getKey()]);
    }

    public function category(DisclosureCategory $category): static
    {
        return $this->state(fn () => ['category' => $category]);
    }

    public function fiscalYear(int $year): static
    {
        return $this->state(fn () => ['fiscal_year' => $year]);
    }

    /** Live without going through PublicationService — for listing tests only. */
    public function published(): static
    {
        return $this->state(fn () => [
            'published_at' => now()->subDay(),
            'published_by' => User::factory(),
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
