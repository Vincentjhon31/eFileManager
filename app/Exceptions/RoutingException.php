<?php

namespace App\Exceptions;

use App\Enums\DocumentStatus;
use App\Models\Department;
use App\Models\Document;
use RuntimeException;

/**
 * A routing act that the state machine refuses.
 *
 * Every message here is written to be read by a records clerk, not a developer,
 * because that is who will see it: these surface directly on screen. "You
 * cannot release a document your office is not holding" tells them what to do
 * next; "invalid state transition" does not.
 */
class RoutingException extends RuntimeException
{
    public static function wrongStatus(Document $document, string $attempted): self
    {
        return new self(sprintf(
            'This document is %s, so it cannot be %s. %s',
            mb_strtolower($document->status->label()),
            $attempted,
            $document->status->description(),
        ));
    }

    public static function notTheHolder(Document $document, ?Department $actorOffice): self
    {
        return new self(sprintf(
            'Only %s can do this — the document is charged to them, not to %s.',
            $document->currentHolderDepartment?->displayName() ?? 'the holding office',
            $actorOffice?->displayName() ?? 'your office',
        ));
    }

    public static function noOffice(): self
    {
        return new self(
            'Your account is not assigned to an office, so it cannot send or receive documents. '
            .'Ask your administrator to assign one.'
        );
    }

    public static function externalOfficeCannotRegister(Department $office): self
    {
        return new self(sprintf(
            '%s is an outside party, not a municipal office, so documents cannot be registered under it.',
            $office->displayName(),
        ));
    }

    public static function sameOffice(Department $office): self
    {
        return new self(sprintf(
            '%s already holds this document. To give it to a colleague, assign it instead of sending it.',
            $office->displayName(),
        ));
    }

    public static function noOpenTransmittal(Document $document): self
    {
        return new self(sprintf(
            'There is no transmittal awaiting receipt for %s.',
            $document->tracking_no,
        ));
    }

    public static function notTheRecipient(Department $destination): self
    {
        return new self(sprintf(
            'Only %s can receive this in the system. If they signed for it on paper, '
            .'record a paper receipt instead.',
            $destination->displayName(),
        ));
    }

    public static function cannotReceiveDigitally(Department $destination): self
    {
        return new self(sprintf(
            '%s is not yet using the system, so this can only be recorded as signed on paper.',
            $destination->displayName(),
        ));
    }

    public static function receiptNeedsASignatory(): self
    {
        return new self(
            'Enter the name of the person who signed the transmittal. '
            .'A paper receipt with nobody attached to it records nothing.'
        );
    }

    public static function receiptBeforeRelease(): self
    {
        return new self('A document cannot be received before it was sent. Check the date and time.');
    }

    public static function receiptInTheFuture(): self
    {
        return new self('A receipt cannot be dated in the future. Check the date and time.');
    }

    public static function notTheSender(Department $sender): self
    {
        return new self(sprintf(
            'Only %s can recall this transmittal, because they sent it.',
            $sender->displayName(),
        ));
    }

    public static function nowhereToReturn(Document $document): self
    {
        return new self(sprintf(
            '%s has not been received from another office, so there is nowhere to return it to.',
            $document->tracking_no,
        ));
    }

    public static function assigneeOutsideHoldingOffice(Department $holder): self
    {
        return new self(sprintf(
            'A document can only be assigned to someone in %s, the office holding it.',
            $holder->displayName(),
        ));
    }

    /** @param  array<int, DocumentStatus>  $allowed */
    public static function expected(Document $document, array $allowed): self
    {
        $labels = implode(' or ', array_map(fn (DocumentStatus $s) => mb_strtolower($s->label()), $allowed));

        return new self(sprintf(
            'This can only be done to a document that is %s. %s is %s.',
            $labels,
            $document->tracking_no,
            mb_strtolower($document->status->label()),
        ));
    }
}
