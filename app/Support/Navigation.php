<?php

namespace App\Support;

use App\Enums\Permission;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * The main navigation, filtered by what the signed-in user may actually do.
 *
 * Hiding a link is presentation only — it is never the access control. Every
 * destination is independently guarded by middleware and policies. A user who
 * types a URL directly is stopped there, not here.
 */
class Navigation
{
    /**
     * Two groups, not twelve flat links: the sidebar reflects the same line
     * every permission check already draws. "work" is what any onboarded
     * employee touches; "admin" only ever appears for someone who can
     * actually act on it, so an ordinary clerk never sees a section heading
     * for screens that would refuse them anyway.
     *
     * @return array<int, array{label: string, url: string, active: bool, group: string, icon: string}>
     */
    public static function forCurrentUser(): array
    {
        $user = Auth::user();

        if (! $user) {
            return [];
        }

        $items = [
            [
                'label' => 'Dashboard',
                'route' => 'dashboard',
                'visible' => true,
                'group' => 'work',
                'icon' => 'dashboard',
            ],
            [
                'label' => 'My Desk',
                'route' => 'desk',
                'visible' => $user->canAny([
                    Permission::DocumentsViewOwnDepartment->value,
                    Permission::DocumentsViewAllDepartments->value,
                ]),
                'group' => 'work',
                'icon' => 'desk',
            ],
            [
                'label' => 'Documents',
                'route' => 'documents.index',
                // Registering and opening a document both belong under this
                // tab, so it stays lit across the whole documents.* family.
                'active' => 'documents.*',
                'visible' => $user->canAny([
                    Permission::DocumentsViewOwnDepartment->value,
                    Permission::DocumentsViewAllDepartments->value,
                ]),
                'group' => 'work',
                'icon' => 'documents',
            ],
            [
                'label' => 'Workspace',
                'route' => 'workspace',
                'visible' => $user->department_id !== null,
                'group' => 'work',
                'icon' => 'workspace',
            ],
            [
                'label' => 'Drive',
                'route' => 'drive',
                'visible' => $user->department_id !== null,
                'group' => 'work',
                'icon' => 'drive',
            ],
            [
                'label' => 'Offices',
                'route' => 'admin.departments.index',
                'visible' => $user->can(Permission::DepartmentsManage->value),
                'group' => 'admin',
                'icon' => 'offices',
            ],
            [
                'label' => 'Users',
                'route' => 'admin.users.index',
                'visible' => $user->canAny([
                    Permission::UsersManageAll->value,
                    Permission::UsersManageOwnDepartment->value,
                ]),
                'group' => 'admin',
                'icon' => 'users',
            ],
            [
                'label' => 'Workspace apps',
                'route' => 'admin.apps.index',
                'visible' => $user->can(Permission::AppsManage->value),
                'group' => 'admin',
                'icon' => 'apps',
            ],
            [
                'label' => 'Notices',
                'route' => 'admin.announcements.index',
                'visible' => $user->can(Permission::PublicPublish->value),
                'group' => 'admin',
                'icon' => 'notices',
            ],
            [
                'label' => 'Disclosure board',
                'route' => 'admin.disclosures.index',
                'visible' => $user->can(Permission::PublicPublish->value),
                'group' => 'admin',
                'icon' => 'disclosure',
            ],
            [
                'label' => 'Audit trail',
                'route' => 'admin.audit.index',
                'visible' => $user->canAny([
                    Permission::AuditViewAllDepartments->value,
                    Permission::AuditViewOwnDepartment->value,
                ]),
                'group' => 'admin',
                'icon' => 'audit',
            ],
            [
                'label' => 'Storage & Backups',
                'route' => 'admin.storage.index',
                'visible' => $user->can(Permission::SettingsManage->value),
                'group' => 'admin',
                'icon' => 'storage',
            ],
        ];

        return collect($items)
            ->filter(fn (array $item) => $item['visible'] && Route::has($item['route']))
            ->map(fn (array $item) => [
                'label' => $item['label'],
                'url' => route($item['route']),
                'active' => request()->routeIs($item['active'] ?? $item['route'].'*'),
                'group' => $item['group'],
                'icon' => $item['icon'],
            ])
            ->values()
            ->all();
    }
}
