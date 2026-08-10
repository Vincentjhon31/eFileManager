<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\File;
use App\Models\Folder;
use App\Models\User;

/**
 * Who can read and change stored files.
 *
 * A file has no permissions of its own — it inherits the folder it sits in.
 * One rule, in one place, that an office administrator can explain to a clerk
 * in a sentence: *if you can open the folder, you can open what is in it.*
 */
class FilePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->department_id !== null;
    }

    public function view(User $user, File $file): bool
    {
        return File::query()->visibleTo($user)->whereKey($file->getKey())->exists();
    }

    /** Reading the bytes is the same decision as seeing the row. */
    public function download(User $user, File $file): bool
    {
        return $this->view($user, $file);
    }

    public function update(User $user, File $file): bool
    {
        return $this->writable($user, $file);
    }

    public function delete(User $user, File $file): bool
    {
        return $this->writable($user, $file);
    }

    public function restore(User $user, File $file): bool
    {
        return $this->writable($user, $file);
    }

    /**
     * Destroying the bytes for good.
     *
     * Deliberately narrower than everything else: the owning office plus the
     * system administrator's permission. Anything a clerk can do by accident on
     * a busy Friday should be undoable, and this is the one thing that is not.
     */
    public function forceDelete(User $user, File $file): bool
    {
        return $this->writable($user, $file)
            && $user->can(Permission::SettingsManage->value);
    }

    private function writable(User $user, File $file): bool
    {
        return $file->folder !== null
            && Folder::query()->writableBy($user)->whereKey($file->folder_id)->exists();
    }
}
