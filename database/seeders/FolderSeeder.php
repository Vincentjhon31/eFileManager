<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Services\FileStorageService;
use Illuminate\Database\Seeder;

class FolderSeeder extends Seeder
{
    /**
     * Give every municipal office its two system folders up front.
     *
     * They would be created on first use anyway. Doing it here means they exist
     * before anyone touches the drive, which removes the only window in which
     * two simultaneous first visits could produce a duplicate.
     *
     * Idempotent: safe to re-run when new offices are added.
     */
    public function run(FileStorageService $drive): void
    {
        Department::internal()->orderBy('sort_order')->each(
            fn (Department $office) => $drive->documentScansFolderFor($office),
        );
    }
}
