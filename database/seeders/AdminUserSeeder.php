<?php

namespace Database\Seeders;

use App\Enums\Role as RoleEnum;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the first system administrator, who then creates everyone else.
 *
 * No password is committed to the repository. Outside local development the
 * credentials must be supplied through the environment, and if a password is
 * not supplied a strong random one is generated and printed once. This runs on
 * a gov.ph domain — a well-known default administrator password would be the
 * single worst thing in the codebase.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@bongabong.gov.ph');
        $generated = false;

        $password = env('ADMIN_PASSWORD');

        if (blank($password)) {
            if (app()->environment('local', 'testing')) {
                $password = 'password';
            } else {
                $password = Str::password(20);
                $generated = true;
            }
        }

        if (! app()->environment('local', 'testing') && $password === 'password') {
            throw new RuntimeException(
                'Refusing to seed the default administrator password outside local development. '
                .'Set ADMIN_PASSWORD in the environment, or leave it unset to have one generated.'
            );
        }

        $mayorsOffice = Department::where('code', 'MO')->first();

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'System Administrator'),
                'password' => $password,
                'department_id' => Department::where('code', 'MIS')->value('id') ?? $mayorsOffice?->id,
                'position' => 'System Administrator',
                'is_active' => true,
            ],
        );

        $admin->syncRoles([RoleEnum::SuperAdmin->value]);

        $this->command?->info("Administrator ready: {$email}");

        if ($generated) {
            $this->command?->warn("Generated password (shown once): {$password}");
            $this->command?->warn('Record it now and change it after first sign-in.');
        }
    }
}
