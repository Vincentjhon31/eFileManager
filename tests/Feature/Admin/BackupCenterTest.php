<?php

namespace Tests\Feature\Admin;

use App\Enums\BackupType;
use App\Enums\Role as RoleEnum;
use App\Livewire\Admin\Storage;
use App\Models\Backup;
use App\Models\Department;
use App\Models\File;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Livewire\Livewire;
use Tests\TestCase;

class BackupCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        StorageFacade::fake('backups');
        StorageFacade::fake('documents');
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::SuperAdmin->value);

        return $user;
    }

    public function test_only_settings_manage_may_open_the_storage_screen(): void
    {
        $deptAdmin = User::factory()->create();
        $deptAdmin->assignRole(RoleEnum::DepartmentAdmin->value);

        $this->actingAs($deptAdmin)->get('/admin/storage')->assertForbidden();
        $this->actingAs($this->superAdmin())->get('/admin/storage')->assertOk();
    }

    public function test_creating_a_database_backup_records_a_row_and_writes_a_file(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(Storage::class)
            ->call('backupDatabase')
            ->assertHasNoErrors();

        $backup = Backup::sole();

        $this->assertSame(BackupType::Database, $backup->type);
        StorageFacade::disk('backups')->assertExists($backup->disk_path);
        $this->assertGreaterThan(0, $backup->size);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'backup.created',
            'auditable_id' => $backup->id,
        ]);
    }

    public function test_creating_a_files_backup_records_a_row_and_writes_a_file(): void
    {
        Livewire::actingAs($this->superAdmin())
            ->test(Storage::class)
            ->call('backupFiles')
            ->assertHasNoErrors();

        $backup = Backup::sole();

        $this->assertSame(BackupType::Files, $backup->type);
        StorageFacade::disk('backups')->assertExists($backup->disk_path);
    }

    public function test_only_the_newest_backups_of_each_type_are_kept(): void
    {
        config(['backups.keep_per_type' => 2]);

        $admin = $this->superAdmin();

        for ($i = 0; $i < 4; $i++) {
            Livewire::actingAs($admin)->test(Storage::class)->call('backupDatabase');
        }

        $this->assertSame(2, Backup::where('type', BackupType::Database)->count());
    }

    public function test_downloading_a_backup_is_authorised_and_logged(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)->test(Storage::class)->call('backupDatabase');
        $backup = Backup::sole();

        $deptAdmin = User::factory()->create();
        $deptAdmin->assignRole(RoleEnum::DepartmentAdmin->value);

        $this->actingAs($deptAdmin)
            ->get(route('admin.storage.download', $backup))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('admin.storage.download', $backup))
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'backup.downloaded',
            'auditable_id' => $backup->id,
        ]);
    }

    public function test_deleting_a_backup_removes_the_row_and_the_file(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)->test(Storage::class)->call('backupDatabase');
        $backup = Backup::sole();

        Livewire::actingAs($admin)
            ->test(Storage::class)
            ->call('delete', $backup->id);

        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
        StorageFacade::disk('backups')->assertMissing($backup->disk_path);
    }

    public function test_storage_usage_is_totalled_per_office(): void
    {
        $office = Department::factory()->create();
        File::factory()->count(3)->create(['department_id' => $office->id, 'size' => 100]);

        $component = Livewire::actingAs($this->superAdmin())->test(Storage::class);

        $usage = collect($component->viewData('usage'))
            ->firstWhere(fn ($row) => $row['department']->is($office));

        $this->assertNotNull($usage);
        $this->assertSame(3, $usage['file_count']);
        $this->assertSame(300, $usage['total_size']);
    }
}
