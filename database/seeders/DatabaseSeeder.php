<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Every seeder here is idempotent, so this is safe to re-run against an
     * existing database — including production, where it is the supported way
     * to pick up newly added permissions or offices.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DepartmentSeeder::class,
            CompoundSeeder::class,
            DocumentTypeSeeder::class,
            FolderSeeder::class,
            WorkspaceAppSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
