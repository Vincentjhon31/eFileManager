<?php

namespace Database\Seeders;

use App\Enums\ActionRequested;
use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    /**
     * The kinds of paper that move through a municipal hall.
     *
     * ────────────────────────────────────────────────────────────────────────
     *  NOTE FOR VERIFICATION — retention periods
     *
     *  The retention_years below are ordinary practice, not a transcription of
     *  the National Archives' General Records Disposition Schedule under
     *  RA 9470. They are safe to run on — nothing in this system ever deletes a
     *  document, and Phase 7 only *reports* on what is eligible for disposal —
     *  but they MUST be checked against the NAP schedule with the Records
     *  Officer before anyone acts on that report.
     *
     *  NULL means permanent: never disposed of.
     * ────────────────────────────────────────────────────────────────────────
     *
     * Idempotent: safe to re-run to pick up newly added types without
     * disturbing the ones an office has already used.
     */
    public function run(): void
    {
        $types = [
            // code, name, default action, retention years, description
            ['MEMO', 'Memorandum', ActionRequested::ForInformation, 5,
                'Internal instruction or advisory circulated within the LGU.'],
            ['OO', 'Office Order', ActionRequested::ForInformation, 10,
                'Directive issued by the Mayor or a department head.'],
            ['SO', 'Special Order', ActionRequested::ForInformation, 10,
                'Designation, detail or special assignment of personnel.'],
            ['TO', 'Travel Order', ActionRequested::ForSignature, 5,
                'Authority for official travel outside the municipality.'],
            ['ENDO', 'Endorsement', ActionRequested::ForAppropriateAction, 5,
                'Referral of a document to another office for action.'],
            ['LTR', 'Letter', ActionRequested::ForAppropriateAction, 5,
                'Incoming or outgoing correspondence.'],
            ['REQ', 'Request', ActionRequested::ForApproval, 5,
                'Request for supplies, services, repair or assistance.'],
            ['PR', 'Purchase Request', ActionRequested::ForApproval, 10,
                'Procurement request routed through Budget and Accounting.'],
            ['PO', 'Purchase Order', ActionRequested::ForSignature, 10,
                'Award to a supplier following procurement.'],
            ['DV', 'Disbursement Voucher', ActionRequested::ForApproval, 10,
                'Claim for payment routed through Accounting and Treasury.'],
            ['PAY', 'Payroll', ActionRequested::ForApproval, 10,
                'Personnel payroll for a pay period.'],
            ['ORD', 'Ordinance', ActionRequested::ForSignature, null,
                'Legislation enacted by the Sangguniang Bayan. Retained permanently.'],
            ['RES', 'Resolution', ActionRequested::ForSignature, null,
                'Resolution of the Sangguniang Bayan. Retained permanently.'],
            ['APPT', 'Appointment Paper', ActionRequested::ForSignature, null,
                'Personnel appointment. Part of the service record; retained permanently.'],
            ['CONT', 'Contract or Agreement', ActionRequested::ForSignature, 10,
                'Contract, MOA or MOU entered into by the municipality.'],
            ['CERT', 'Certification', ActionRequested::ForSignature, 5,
                'Certification issued to a citizen, employee or office.'],
            ['COMP', 'Complaint', ActionRequested::ForAppropriateAction, 10,
                'Complaint or grievance received from the public or an employee.'],
            ['RPT', 'Report', ActionRequested::ForInformation, 5,
                'Accomplishment, financial, inspection or narrative report.'],
        ];

        foreach ($types as $index => [$code, $name, $action, $retention, $description]) {
            DocumentType::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'description' => $description,
                    'default_action' => $action,
                    'retention_years' => $retention,
                    'is_active' => true,
                    'sort_order' => ($index + 1) * 10,
                ],
            );
        }
    }
}
