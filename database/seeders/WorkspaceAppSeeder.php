<?php

namespace Database\Seeders;

use App\Enums\WorkspaceAppScope;
use App\Enums\WorkspaceAppStatus;
use App\Models\Department;
use App\Models\WorkspaceApp;
use Illuminate\Database\Seeder;

/**
 * The workspace catalog's starting set of apps.
 *
 * Sample entries only — real ones belong to whichever office actually runs
 * them, added once the LGU tells us what it wants published. Kept here,
 * rather than left to be created by hand in every environment, so a fresh
 * install shows a workspace that looks like the one people were shown, not
 * an empty grid.
 *
 * References real office codes from DepartmentSeeder. If an office is not
 * found — the roster is still pending HRMO verification — its app is simply
 * skipped rather than seeded against the wrong office.
 */
class WorkspaceAppSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->apps() as $app) {
            $office = $app['office_code'] ? Department::firstWhere('code', $app['office_code']) : null;

            if ($app['office_code'] && ! $office) {
                continue;
            }

            WorkspaceApp::updateOrCreate(
                ['slug' => $app['slug']],
                [
                    'name' => $app['name'],
                    'description' => $app['description'],
                    'url' => $app['url'],
                    'icon_glyph' => $app['icon_glyph'],
                    'status' => $app['status'],
                    'scope' => $app['scope'],
                    'department_id' => $office?->id,
                    'sort_order' => $app['sort_order'],
                ],
            );
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function apps(): array
    {
        return [
            [
                'slug' => 'ai-worklog',
                'name' => 'AI WorkLog',
                'description' => 'Daily accomplishment diary and CSC-style appraisal.',
                'url' => 'https://worklog.bongabong.gov.ph',
                'icon_glyph' => 'W',
                'status' => WorkspaceAppStatus::Live,
                'scope' => WorkspaceAppScope::Department,
                'office_code' => 'MO',
                'sort_order' => 10,
            ],
            [
                'slug' => 'e-calendar',
                'name' => 'e-Calendar',
                'description' => 'Shared calendar across every office and barangay.',
                'url' => 'https://calendar.bongabong.gov.ph',
                'icon_glyph' => 'C',
                'status' => WorkspaceAppStatus::Live,
                'scope' => WorkspaceAppScope::Organization,
                'office_code' => 'MO',
                'sort_order' => 20,
            ],
            [
                'slug' => 'patient-records',
                'name' => 'Patient Records',
                'description' => 'Consultation history for the health station network.',
                'url' => 'https://health.bongabong.gov.ph/records',
                'icon_glyph' => 'H',
                'status' => WorkspaceAppStatus::Live,
                'scope' => WorkspaceAppScope::Department,
                'office_code' => 'MHO',
                'sort_order' => 30,
            ],
            [
                'slug' => 'bpls',
                'name' => 'Business Permits (BPLS)',
                'description' => 'New and renewal applications with assessment.',
                'url' => 'https://bpls.bongabong.gov.ph',
                'icon_glyph' => 'B',
                'status' => WorkspaceAppStatus::Live,
                'scope' => WorkspaceAppScope::Department,
                'office_code' => 'BPLO',
                'sort_order' => 40,
            ],
            [
                'slug' => 'civil-registry-system',
                'name' => 'Civil Registry System',
                'description' => 'Birth, marriage, and death registration records.',
                'url' => 'https://lcr.bongabong.gov.ph',
                'icon_glyph' => 'R',
                'status' => WorkspaceAppStatus::Pilot,
                'scope' => WorkspaceAppScope::Department,
                'office_code' => 'LCR',
                'sort_order' => 50,
            ],
            [
                'slug' => 'schedule-borrowing-system',
                'name' => 'Schedule Borrowing System',
                'description' => 'Reserve vehicles, venues, and equipment.',
                'url' => 'https://sbs.bongabong.gov.ph',
                'icon_glyph' => 'S',
                'status' => WorkspaceAppStatus::Live,
                'scope' => WorkspaceAppScope::Organization,
                'office_code' => 'MEO',
                'sort_order' => 60,
            ],
            [
                'slug' => 'downloadable-forms',
                'name' => 'Downloadable Forms',
                'description' => 'Blank forms citizens can print at home — no login needed.',
                'url' => 'https://bongabong.gov.ph/forms',
                'icon_glyph' => 'F',
                'status' => WorkspaceAppStatus::Live,
                'scope' => WorkspaceAppScope::Public,
                'office_code' => 'GSO',
                'sort_order' => 70,
            ],
        ];
    }
}
