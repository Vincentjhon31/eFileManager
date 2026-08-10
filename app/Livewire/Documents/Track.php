<?php

namespace App\Livewire\Documents;

use App\Enums\ReceiptMethod;
use App\Exceptions\RoutingException;
use App\Livewire\Concerns\RecordsReceipt;
use App\Models\Document;
use App\Services\DocumentRoutingService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Where the scanned routing slip lands.
 *
 * This is a phone screen, held one-handed in a corridor by someone who has just
 * been handed a piece of paper. It answers one question and offers one action:
 * *this is what you are holding, press here to sign for it.*
 *
 * It is deliberately not the document page. That screen is a desktop workbench
 * with every action on it, and reading it on a phone while carrying a folder is
 * exactly the friction that makes people stop using a system. The receipt rules
 * are shared with it through RecordsReceipt so the two can never disagree.
 */
class Track extends Component
{
    use RecordsReceipt;

    public Document $document;

    public bool $confirming = false;

    public function mount(Document $document): void
    {
        // The signature proves the link was issued by us, not that whoever
        // scanned it is entitled to read the document. That is still the
        // policy's decision, and it is made here.
        $this->authorize('view', $document);

        $this->document = $document;
        $this->receipt_method = $this->defaultReceiptMethod($document);
    }

    public function startReceiving(): void
    {
        $this->authorize('receive', $this->document);

        $this->receipt_method = $this->defaultReceiptMethod($this->document);
        $this->confirming = true;
        $this->resetValidation();
    }

    public function cancelReceiving(): void
    {
        $this->reset(['confirming', 'received_by_name', 'received_at']);
        $this->resetValidation();
    }

    public function receive(DocumentRoutingService $routing): void
    {
        $this->authorize('receive', $this->document);

        try {
            $leg = $this->recordReceipt($this->document, $routing);
        } catch (RoutingException $e) {
            $this->addError('routing', $e->getMessage());

            return;
        }

        $this->document->refresh();
        $this->cancelReceiving();

        session()->flash('status', sprintf(
            'Received at %s. %s',
            ph_datetime($leg->received_at),
            $leg->receipt_method->isWitnessed()
                ? 'Recorded in your name.'
                : "Recorded from {$leg->received_by_name}'s signature.",
        ));
    }

    public function render()
    {
        $document = $this->document->load([
            'type', 'originDepartment', 'currentHolderDepartment', 'currentHolderUser',
            'openRoute.fromDepartment', 'openRoute.toDepartment', 'openRoute.toUser',
        ]);

        return view('livewire.documents.track', [
            'document' => $document,
            'leg' => $document->openRoute,
            'canReceive' => Auth::user()->can('receive', $document),
            'digital' => $this->mayReceiveDigitally($document),
            'methods' => [ReceiptMethod::System, ReceiptMethod::Manual],
            'recent' => $document->actions()->reorder('id', 'desc')->with('department')->limit(4)->get(),
        ])->layout('components.layouts.app', ['title' => $document->tracking_no]);
    }
}
