<?php

namespace App\Livewire\Concerns;

use App\Enums\ReceiptMethod;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Services\DocumentRoutingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The receipt form, shared by the document page and the scanned routing slip.
 *
 * Two screens record receipts: a desktop workbench and a phone held in a
 * corridor. They look nothing alike and should, but they must agree exactly on
 * what a receipt is and who may record one — a second copy of those rules would
 * eventually contradict the first, and the disagreement would show up as a
 * receipt that exists on one screen and not the other.
 *
 * The rules themselves live in DocumentRoutingService. This only carries the
 * form.
 */
trait RecordsReceipt
{
    public string $receipt_method = 'system';

    public string $received_by_name = '';

    public string $received_at = '';

    /**
     * Offer digital receipt only when it would actually be accepted: the person
     * is standing in the destination office, and that office has onboarded.
     * Anything else can only be a paper receipt, so do not present a choice
     * that would just be refused.
     */
    public function defaultReceiptMethod(Document $document): string
    {
        $destination = $document->openRoute?->toDepartment;

        return $destination?->acceptsDigitalReceipt()
            && $destination->getKey() === Auth::user()?->department_id
                ? ReceiptMethod::System->value
                : ReceiptMethod::Manual->value;
    }

    public function mayReceiveDigitally(Document $document): bool
    {
        $destination = $document->openRoute?->toDepartment;

        return (bool) $destination?->acceptsDigitalReceipt()
            && $destination->getKey() === Auth::user()?->department_id;
    }

    /**
     * Validate the form and record the receipt.
     *
     * Throws RoutingException if the engine refuses; the calling component
     * decides how to show that.
     */
    public function recordReceipt(Document $document, DocumentRoutingService $routing): DocumentRoute
    {
        $method = ReceiptMethod::tryFrom($this->receipt_method) ?? ReceiptMethod::Manual;

        $data = $this->validate([
            'receipt_method' => ['required', Rule::enum(ReceiptMethod::class)],
            'received_by_name' => [
                Rule::requiredIf($method === ReceiptMethod::Manual), 'nullable', 'string', 'max:255',
            ],
            'received_at' => ['nullable', 'date'],
        ], [
            'received_by_name.required' => 'Enter the name signed on the transmittal.',
        ]);

        return $routing->receive(
            document: $document,
            by: Auth::user(),
            method: $method,
            receivedByName: $data['received_by_name'] ?: null,
            receivedAt: $data['received_at'] ? now()->parse($data['received_at']) : null,
        );
    }
}
