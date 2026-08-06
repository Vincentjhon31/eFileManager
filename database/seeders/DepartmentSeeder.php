<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

/**
 * Offices of the Municipality of Bongabong, Oriental Mindoro.
 *
 * NOTE FOR VERIFICATION: this is the standard office set for a Philippine
 * municipality, not a roster confirmed against Bongabong's own plantilla.
 * Names and codes should be checked with the HRMO or the Mayor's Office before
 * go-live. Codes appear inside tracking numbers, so correct them BEFORE any
 * real documents are registered — changing a code afterwards orphans the
 * numbering.
 *
 * Everything except the Mayor's Office is seeded is_onboarded = false. Those
 * offices are still valid routing destinations from day one; their legs are
 * recorded as manually received until they are onboarded, at which point the
 * flag is flipped and nothing migrates.
 */
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->internalOffices() as $index => $office) {
            Department::updateOrCreate(
                ['code' => $office['code']],
                [
                    'name' => $office['name'],
                    'short_name' => $office['short_name'],
                    'is_onboarded' => $office['onboarded'] ?? false,
                    'is_external' => false,
                    'sort_order' => ($index + 1) * 10,
                ],
            );
        }

        foreach ($this->externalParties() as $index => $party) {
            Department::updateOrCreate(
                ['code' => $party['code']],
                [
                    'name' => $party['name'],
                    'short_name' => $party['short_name'],
                    'is_onboarded' => false,
                    'is_external' => true,
                    'sort_order' => 1000 + ($index + 1) * 10,
                ],
            );
        }
    }

    /**
     * Offices of the municipal government. Order roughly follows how they are
     * listed on an organisational chart, which is also how staff expect to see
     * them in a dropdown.
     */
    private function internalOffices(): array
    {
        return [
            ['code' => 'MO',      'short_name' => "Mayor's Office",        'name' => 'Office of the Municipal Mayor', 'onboarded' => true],
            ['code' => 'MVO',     'short_name' => "Vice Mayor's Office",   'name' => 'Office of the Municipal Vice Mayor'],
            ['code' => 'SB',      'short_name' => 'Sangguniang Bayan',     'name' => 'Office of the Sangguniang Bayan'],
            ['code' => 'MPDO',    'short_name' => 'MPDO',                  'name' => 'Municipal Planning and Development Office'],
            ['code' => 'MBO',     'short_name' => 'Budget Office',         'name' => 'Municipal Budget Office'],
            ['code' => 'MACCO',   'short_name' => 'Accounting Office',     'name' => 'Municipal Accounting Office'],
            ['code' => 'MTO',     'short_name' => "Treasurer's Office",    'name' => 'Municipal Treasurer\'s Office'],
            ['code' => 'MASSO',   'short_name' => "Assessor's Office",     'name' => 'Municipal Assessor\'s Office'],
            ['code' => 'MSWDO',   'short_name' => 'MSWDO',                 'name' => 'Municipal Social Welfare and Development Office'],
            ['code' => 'MHO',     'short_name' => 'Health Office',         'name' => 'Municipal Health Office'],
            ['code' => 'MENRO',   'short_name' => 'MENRO',                 'name' => 'Municipal Environment and Natural Resources Office'],
            ['code' => 'MAGRO',   'short_name' => 'Agriculture Office',    'name' => 'Municipal Agriculture Office'],
            ['code' => 'MEO',     'short_name' => 'Engineering Office',    'name' => 'Municipal Engineering Office'],
            ['code' => 'LCR',     'short_name' => 'Civil Registrar',       'name' => 'Office of the Local Civil Registrar'],
            ['code' => 'HRMO',    'short_name' => 'HRMO',                  'name' => 'Human Resource Management Office'],
            ['code' => 'GSO',     'short_name' => 'General Services',      'name' => 'General Services Office'],
            ['code' => 'BPLO',    'short_name' => 'BPLO',                  'name' => 'Business Permits and Licensing Office'],
            ['code' => 'MDRRMO',  'short_name' => 'MDRRMO',                'name' => 'Municipal Disaster Risk Reduction and Management Office'],
            ['code' => 'MTO-TOU', 'short_name' => 'Tourism Office',        'name' => 'Municipal Tourism Office'],
            ['code' => 'LEGAL',   'short_name' => 'Legal Office',          'name' => 'Municipal Legal Office'],
            ['code' => 'MIS',     'short_name' => 'MIS',                   'name' => 'Management Information Systems Office'],
        ];
    }

    /**
     * Outside parties documents are exchanged with. These never get logins;
     * they exist so that an incoming letter from the Governor, or an
     * endorsement sent to a barangay, has a real origin and destination on the
     * trail instead of a free-text note.
     *
     * Individual barangays are represented by a single generic row at pilot
     * stage. Bongabong has 36 barangays; seed them individually only once the
     * volume of barangay correspondence justifies the dropdown length.
     */
    private function externalParties(): array
    {
        return [
            ['code' => 'EXT-PGOM',  'short_name' => 'Provincial Government', 'name' => 'Provincial Government of Oriental Mindoro'],
            ['code' => 'EXT-SPOM',  'short_name' => 'Sangguniang Panlalawigan', 'name' => 'Sangguniang Panlalawigan of Oriental Mindoro'],
            ['code' => 'EXT-DILG',  'short_name' => 'DILG',                  'name' => 'Department of the Interior and Local Government'],
            ['code' => 'EXT-COA',   'short_name' => 'COA',                   'name' => 'Commission on Audit'],
            ['code' => 'EXT-CSC',   'short_name' => 'CSC',                   'name' => 'Civil Service Commission'],
            ['code' => 'EXT-DBM',   'short_name' => 'DBM',                   'name' => 'Department of Budget and Management'],
            ['code' => 'EXT-BRGY',  'short_name' => 'Barangay',              'name' => 'Barangay (specify in document remarks)'],
            ['code' => 'EXT-OTHER', 'short_name' => 'Other party',           'name' => 'Other external party (specify in document remarks)'],
        ];
    }
}
