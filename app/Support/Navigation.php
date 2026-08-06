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
    /** @return array<int, array{label: string, url: string, active: bool}> */
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
            ],
            [
                'label' => 'Offices',
                'route' => 'admin.departments.index',
                'visible' => $user->can(Permission::DepartmentsManage->value),
            ],
            [
                'label' => 'Users',
                'route' => 'admin.users.index',
                'visible' => $user->canAny([
                    Permission::UsersManageAll->value,
                    Permission::UsersManageOwnDepartment->value,
                ]),
            ],
            [
                'label' => 'Audit trail',
                'route' => 'admin.audit.index',
                'visible' => $user->canAny([
                    Permission::AuditViewAllDepartments->value,
                    Permission::AuditViewOwnDepartment->value,
                ]),
            ],
        ];

        return collect($items)
            ->filter(fn (array $item) => $item['visible'] && Route::has($item['route']))
            ->map(fn (array $item) => [
                'label' => $item['label'],
                'url' => route($item['route']),
                'active' => request()->routeIs($item['route'].'*'),
            ])
            ->values()
            ->all();
    }
}
