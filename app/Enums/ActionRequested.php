<?php

namespace App\Enums;

/**
 * What the sending office is asking the receiving office to do.
 *
 * These are the endorsements written on a routing slip in a Philippine LGU. The
 * wording is kept exactly as staff already use it on paper, so the screen and
 * the slip in their hand say the same thing.
 */
enum ActionRequested: string
{
    case ForApproval = 'for_approval';
    case ForSignature = 'for_signature';
    case ForComment = 'for_comment';
    case ForInformation = 'for_information';
    case ForCompliance = 'for_compliance';
    case ForAppropriateAction = 'for_appropriate_action';
    case ForFiling = 'for_filing';

    public function label(): string
    {
        return match ($this) {
            self::ForApproval => 'For approval',
            self::ForSignature => 'For signature',
            self::ForComment => 'For comment',
            self::ForInformation => 'For information',
            self::ForCompliance => 'For compliance',
            self::ForAppropriateAction => 'For appropriate action',
            self::ForFiling => 'For filing',
        };
    }

    /**
     * Whether the receiving office is expected to send the document onward or
     * back. "For information" and "for filing" end the journey; the rest do not,
     * which is what makes an untouched document genuinely overdue.
     */
    public function expectsAResponse(): bool
    {
        return ! in_array($this, [self::ForInformation, self::ForFiling], true);
    }

    /** @return array<int, self> */
    public static function all(): array
    {
        return self::cases();
    }
}
