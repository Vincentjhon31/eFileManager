<?php

namespace App\Policies;

use App\Models\Folder;
use App\Models\User;

/**
 * Who can open, change and remove folders.
 *
 * As with documents, the questions here are answered by asking the same scopes
 * the listings use rather than restating their conditions. Two copies of a
 * visibility rule drift, and the drift is silent until somebody reads a folder
 * they should not have.
 */
class FolderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->department_id !== null;
    }

    public function view(User $user, Folder $folder): bool
    {
        return Folder::query()->visibleTo($user)->whereKey($folder->getKey())->exists();
    }

    /** Creating a folder is always an act of the user's own office. */
    public function create(User $user): bool
    {
        return $user->department_id !== null
            && $user->department !== null
            && ! $user->department->is_external;
    }

    public function update(User $user, Folder $folder): bool
    {
        return ! $folder->is_system && $this->writable($user, $folder);
    }

    public function delete(User $user, Folder $folder): bool
    {
        return ! $folder->is_system && $this->writable($user, $folder);
    }

    /** Add, rename, move or remove things inside this folder. */
    public function store(User $user, Folder $folder): bool
    {
        return $this->writable($user, $folder);
    }

    private function writable(User $user, Folder $folder): bool
    {
        return Folder::query()->writableBy($user)->whereKey($folder->getKey())->exists();
    }
}
