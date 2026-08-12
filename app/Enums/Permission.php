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

    // Subtracts nothing on its own — it only lifts the extra restriction on
    // documents marked confidential, and only within offices the holder can
    // already see. Held by office heads and department administrators, not by
    // every clerk, because these are personnel, legal and disciplinary papers.
    case DocumentsViewConfidential = 'documents.view.confidential';

    // Audit trail — read only, always. There is no write, edit or delete
    // permission for audit logs anywhere in this system.
    case AuditViewOwnDepartment = 'audit.view.own_department';
    case AuditViewAllDepartments = 'audit.view.all_departments';

    /*
     * Putting something on the municipality's public page.
     *
     * Deliberately attached to no operational role at seed time — not to
     * department administrators, not to approving officers. The system
     * administrator holds it because they hold everything, and their first job
     * with it is to grant it directly to the person the LGU has designated as
     * focal person for the Full Disclosure Policy.
     *
     * Every other permission here describes a job somebody does. This one
     * describes an authority the municipality confers on a named individual.
     *
     * The audience is the whole town, and there is no taking a disclosure back
     * once it has been read.
     */
    case PublicPublish = 'public.publish';

    /*
     * Publishing a system into the workspace catalog.
     *
     * Held by department administrators as well as MIS, because the catalog is
     * only useful if the office that runs a system can list it themselves. The
     * scope is still bounded: this permission publishes to one's own office.
     * Reaching every office, or the public, additionally requires
     * SettingsManage — see WorkspaceAppPolicy.
     */
    case AppsManage = 'apps.manage';

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
            self::DocumentsViewConfidential => 'Open confidential documents in own office',
            self::PublicPublish => 'Publish to the public portal',
            self::AppsManage => 'Publish apps to the workspace catalog',
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
