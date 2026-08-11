<?php

namespace App\Enums;

/**
 * The shelves of the Full Disclosure Policy board.
 *
 * The DILG requires LGUs to post certain documents where the public can read
 * them. That makes this board a compliance deliverable rather than a courtesy —
 * which is worth knowing, because it is the argument that gets this part of the
 * system funded and kept up to date.
 *
 * ────────────────────────────────────────────────────────────────────────────
 *  NOTE FOR VERIFICATION — posting titles
 *
 *  These are sensible groupings, not a transcription of the current DILG
 *  memorandum circular's list of required postings. Nothing here is used to
 *  claim compliance; it only decides which heading a document appears under.
 *  Before the LGU points DILG at this page, the headings and the list of what
 *  must be posted should be checked against the circular in force with the
 *  Municipal Budget Officer or the Local Finance Committee.
 * ────────────────────────────────────────────────────────────────────────────
 */
enum DisclosureCategory: string
{
    case AnnualBudget = 'annual_budget';
    case Procurement = 'procurement';
    case Financial = 'financial';
    case FundUtilisation = 'fund_utilisation';
    case Ordinance = 'ordinance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AnnualBudget => 'Annual budget',
            self::Procurement => 'Procurement and bidding',
            self::Financial => 'Financial statements',
            self::FundUtilisation => 'Special fund utilisation',
            self::Ordinance => 'Ordinances and resolutions',
            self::Other => 'Other disclosures',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AnnualBudget => 'The annual and supplemental budgets as enacted.',
            self::Procurement => 'Annual procurement plan, invitations to bid, notices of award.',
            self::Financial => 'Statements of receipts and expenditures, and of debt service.',
            self::FundUtilisation => 'Special Education Fund, disaster risk reduction fund, gender and development.',
            self::Ordinance => 'Ordinances and resolutions enacted by the Sangguniang Bayan.',
            self::Other => 'Anything the municipality has chosen to disclose.',
        };
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
