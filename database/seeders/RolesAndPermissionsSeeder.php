<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: safe to re-run after adding a permission to the enum. Existing
 * role assignments are preserved; only the role→permission map is re-synced.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (PermissionEnum::all() as $permission) {
            Permission::findOrCreate($permission->value, 'web');
        }

        // Flush again before syncing roles. DatabaseSeeder runs with
        // WithoutModelEvents, which suppresses the cache invalidation Spatie
        // normally fires on save — without this, syncPermissions() reads the
        // registrar's pre-seed cache and cannot find the permissions just
        // created.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (RoleEnum::all() as $roleEnum) {
            $role = Role::findOrCreate($roleEnum->value, 'web');

            $role->syncPermissions(
                array_map(fn (PermissionEnum $p) => $p->value, $roleEnum->permissions())
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
