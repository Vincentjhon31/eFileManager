<?php

namespace App\Enums;

/**
 * Named permissions.
 *
 * Scope is part of the name, not an afterthought: *OwnDepartment permissions
 * are still constrained by policy to the acting user's office. A permission
 * alone never grants cross-office visibility — that requires the explicit
 * *AllDepartments variant, which only the system administrator holds.
 *
 * This matters under RA 10173: a Budget clerk having "view documents" must not
 * imply they can read the Mayor's confidential correspondence.
 */
enum Permission: string
{
    // Organisation
    case DepartmentsManage = 'departments.manage';
    case UsersManageAll = 'users.manage.all';
    case UsersManageOwnDepartment = 'users.manage.own_department';

    // Documents
    case DocumentsCreate = 'documents.create';
    case DocumentsRoute = 'documents.route';
    case DocumentsReceive = 'documents.receive';
    case DocumentsAct = 'documents.act';
    case DocumentsViewOwnDepartment = 'documents.view.own_department';
    case DocumentsViewAllDepartments = 'documents.view.all_departments';

    // Audit trail — read only, always. There is no write, edit or delete
    // permission for audit logs anywhere in this system.
    case AuditViewOwnDepartment = 'audit.view.own_department';
    case AuditViewAllDepartments = 'audit.view.all_departments';

    // Settings
    case SettingsManage = 'settings.manage';

    public function label(): string
    {
        return match ($this) {
            self::DepartmentsManage => 'Manage offices',
            self::UsersManageAll => 'Manage all users',
            self::UsersManageOwnDepartment => 'Manage users in own office',
            self::DocumentsCreate => 'Register documents',
            self::DocumentsRoute => 'Route documents to other offices',
            self::DocumentsReceive => 'Receive incoming documents',
            self::DocumentsAct => 'Act on documents (approve, sign, return)',
            self::DocumentsViewOwnDepartment => 'View own office documents',
            self::DocumentsViewAllDepartments => 'View all offices\' documents',
            self::AuditViewOwnDepartment => 'View own office audit trail',
            self::AuditViewAllDepartments => 'View full audit trail',
            self::SettingsManage => 'Manage system settings',
        };
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
