<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\Folder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<File>
 *
 * Builds a File row without writing anything to disk, which is what you want
 * for listing, visibility and permission tests.
 *
 * Anything that reads the bytes — downloads, previews, versioning, integrity —
 * must go through FileStorageService with a real UploadedFile. A row whose
 * storage_path points at nothing is exactly the state this system treats as a
 * fault, so a test built on one would be testing the fault.
 */
class FileFactory extends Factory
{
    protected $model = File::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(3, true));

        return [
            'folder_id' => Folder::factory(),
            'department_id' => fn (array $attributes) => Folder::find($attributes['folder_id'])?->department_id
                ?? Folder::factory(),
            'name' => $name,
            'original_name' => Str::slug($name).'.pdf',
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(20_000, 8_000_000),
            'sha256' => hash('sha256', $name.fake()->uuid()),
            'storage_path' => '0/2026/08/'.Str::uuid(),
            'version_group_id' => (string) Str::uuid(),
            'version_no' => 1,
            'is_current' => true,
            'uploaded_by' => User::factory(),
        ];
    }

    public function in(Folder $folder): static
    {
        return $this->state(fn () => [
            'folder_id' => $folder->getKey(),
            'department_id' => $folder->department_id,
        ]);
    }

    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'mime' => 'image/jpeg',
            'original_name' => Str::slug($attributes['name']).'.jpg',
        ]);
    }

    public function spreadsheet(): static
    {
        return $this->state(fn (array $attributes) => [
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'original_name' => Str::slug($attributes['name']).'.xlsx',
        ]);
    }

    public function trashed(): static
    {
        return $this->state(fn () => ['deleted_at' => now()]);
    }
}
