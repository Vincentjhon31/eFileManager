<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Exceptions\BackupException;
use App\Models\Backup;
use App\Services\BackupService;
use Livewire\Component;

/**
 * Where storage is spent and how to get it back if something goes wrong.
 *
 * Two questions in one screen because they are the same worry seen from two
 * angles: "who is filling the drive" and "if that goes badly, what do we have
 * to fall back on." One permission gates the whole thing — a backup is the
 * whole database or the whole documents disk, so there is no "own office"
 * variant the way there is for documents.
 */
class Storage extends Component
{
    public function mount(): void
    {
        $this->authorize(Permission::SettingsManage->value);
    }

    public function backupDatabase(BackupService $backups): void
    {
        $this->authorize(Permission::SettingsManage->value);

        $this->attempt(fn () => $backups->createDatabaseBackup(auth()->user()), 'Database backup created.');
    }

    public function backupFiles(BackupService $backups): void
    {
        $this->authorize(Permission::SettingsManage->value);

        $this->attempt(fn () => $backups->createFilesBackup(auth()->user()), 'Files backup created.');
    }

    public function delete(int $id, BackupService $backups): void
    {
        $this->authorize(Permission::SettingsManage->value);

        $backups->delete(Backup::findOrFail($id), auth()->user());

        session()->flash('status', 'Backup deleted.');
    }

    private function attempt(callable $act, string $success): void
    {
        try {
            $act();
        } catch (BackupException $e) {
            $this->addError('backup', $e->getMessage());

            return;
        }

        session()->flash('status', $success);
    }

    public function render(BackupService $backups)
    {
        return view('livewire.admin.storage', [
            'usage' => $backups->storageUsageByDepartment(),
            'databaseSize' => $backups->databaseSizeBytes(),
            'freeDiskSpace' => $backups->freeDiskSpaceBytes(),
            'backupsList' => Backup::query()->with('creator')->latest('created_at')->get(),
            'keepPerType' => (int) config('backups.keep_per_type', 5),
        ])->layout('components.layouts.app', ['title' => 'Storage & Backups']);
    }
}
