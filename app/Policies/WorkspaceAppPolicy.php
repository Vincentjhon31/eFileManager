<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\WorkspaceAppScope;
use App\Models\User;
use App\Models\WorkspaceApp;

/**
 * Who can see, and who can publish, an app in the workspace catalog.
 *
 * Reading asks the same scope the listing uses, for the same reason
 * FolderPolicy does: a second, hand-written copy of the visibility rule is a
 * second place for it to quietly drift from the first.
 *
 * Writing draws one more line. AppsManage lets an office list what it runs —
 * the office that operates a system is the one that knows its URL is right.
 * Reaching *past* that office, to every office or to the public, additionally
 * needs SettingsManage, because a public entry is a link the municipality is
 * putting its name to and an org-wide one appears on everybody's workspace.
 */
class WorkspaceAppPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->department_id !== null;
    }

    public function view(User $user, WorkspaceApp $app): bool
    {
        return WorkspaceApp::query()->visibleTo($user)->whereKey($app->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::AppsManage->value)
            && $user->department_id !== null;
    }

    public function update(User $user, WorkspaceApp $app): bool
    {
        if (! $user->can(Permission::AppsManage->value)) {
            return false;
        }

        /*
         * Whoever runs the system as a whole may edit anything in the catalog,
         * including another office's entry.
         *
         * This branch was missing at first, and the effect was a screen that
         * listed every app to a system administrator and then refused to open
         * any of them that belonged to somebody else. If a row is offered, it
         * has to be actionable — a 403 on a button the screen itself drew is a
         * bug in the screen, not a security decision.
         */
        if ($user->can(Permission::SettingsManage->value)) {
            return true;
        }

        // Everyone else: their own office's entries, and only office-scoped
        // ones. Anything reaching further belongs to the municipality.
        return $app->scope === WorkspaceAppScope::Department
            && $app->department_id !== null
            && $app->department_id === $user->department_id;
    }

    public function delete(User $user, WorkspaceApp $app): bool
    {
        return $this->update($user, $app);
    }

    /**
     * Whether this user may publish at a given reach.
     *
     * Asked by the form before it offers the choice, and again by the component
     * before it saves — the second time is the one that counts.
     */
    public function publishAt(User $user, WorkspaceAppScope $scope): bool
    {
        if (! $user->can(Permission::AppsManage->value)) {
            return false;
        }

        return $scope === WorkspaceAppScope::Department
            || $user->can(Permission::SettingsManage->value);
    }
}
