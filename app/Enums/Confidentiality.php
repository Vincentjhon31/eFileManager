<?php

namespace App\Enums;

/**
 * How widely a document may be read.
 *
 * Read this carefully, because the naming invites the wrong assumption:
 * **confidentiality never widens access, it only narrows it.**
 *
 * Baseline visibility is always the same — a document is visible to the offices
 * it has actually passed through, and to nobody else. Marking a document Public
 * does not make it readable across the municipal hall; it marks it as *eligible*
 * for the public portal, where publishing is a separate, deliberate act.
 *
 * Confidential subtracts from the baseline: within an office that would
 * otherwise see it, only the office head, the department administrator and the
 * person currently holding it may open it.
 */
enum Confidentiality: string
{
    /** Ordinary official business. Eligible for nothing in particular. */
    case Internal = 'internal';

    /** Eligible for publication on the public portal (Phase 6). */
    case Public = 'public';

    /** Restricted within the holding office. Personnel, legal, disciplinary. */
    case Confidential = 'confidential';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal',
            self::Public => 'Public disclosure',
            self::Confidential => 'Confidential',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Internal => 'Normal handling. Visible to the offices it passes through.',
            self::Public => 'May be published on the public portal after a separate approval.',
            self::Confidential => 'Restricted to the office head and the person holding it.',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Internal => 'slate',
            self::Public => 'green',
            self::Confidential => 'red',
        };
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
