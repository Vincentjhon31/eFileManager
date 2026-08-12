<?php

namespace App\Enums;

/**
 * Roles in the municipal hall.
 *
 * ReceivingClerk is deliberately a role of its own. In a Philippine LGU,
 * receiving is a distinct clerical function with real legal weight — the person
 * who stamps and logs a document is usually not the person who acts on it, and
 * conflating the two would make the trail less trustworthy, not simpler.
 */
enum Role: string
{
    /** Full system administration. Held by MIS, not by any office head. */
    case SuperAdmin = 'superadmin';

    /** Manages users, folders and settings within their own office only. */
    case DepartmentAdmin = 'dept_admin';

    /** Logs documents in and out of an office. Cannot approve or sign. */
    case ReceivingClerk = 'receiving_clerk';

    /** Office head. Acts on documents: approves, signs, returns. */
    case Approver = 'approver';

    /** Ordinary employee. Creates and works on documents in their office. */
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'System Administrator',
            self::DepartmentAdmin => 'Department Administrator',
            self::ReceivingClerk => 'Receiving Clerk',
            self::Approver => 'Approving Officer',
            self::Staff => 'Staff',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Full access to every office, user and setting.',
            self::DepartmentAdmin => 'Manages users and folders within their own office.',
            self::ReceivingClerk => 'Receives and releases documents on behalf of their office.',
            self::Approver => 'Approves, signs and returns documents held by their office.',
            self::Staff => 'Creates and works on documents within their own office.',
        };
    }

    /** Permissions granted to this role. */
    public function permissions(): array
    {
        return match ($this) {
            // Granted every permission explicitly at seed time rather than
            // bypassing the gate, so an audit of "who can do what" reads the
            // same for every role.
            self::SuperAdmin => Permission::all(),

            self::DepartmentAdmin => [
                Permission::UsersManageOwnDepartment,
                Permission::AuditViewOwnDepartment,
                // Their own office's entries only; publishing to every office
                // or to the public needs SettingsManage on top.
                Permission::AppsManage,
                Permission::DocumentsCreate,
                Permission::DocumentsRoute,
                Permission::DocumentsReceive,
                Permission::DocumentsAct,
                Permission::DocumentsViewOwnDepartment,
                Permission::DocumentsViewConfidential,
            ],

            self::ReceivingClerk => [
                Permission::DocumentsCreate,
                Permission::DocumentsRoute,
                Permission::DocumentsReceive,
                Permission::DocumentsViewOwnDepartment,
            ],

            self::Approver => [
                Permission::DocumentsCreate,
                Permission::DocumentsRoute,
                Permission::DocumentsReceive,
                Permission::DocumentsAct,
                Permission::DocumentsViewOwnDepartment,
                Permission::DocumentsViewConfidential,
                Permission::AuditViewOwnDepartment,
            ],

            self::Staff => [
                Permission::DocumentsCreate,
                Permission::DocumentsViewOwnDepartment,
            ],
        };
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
