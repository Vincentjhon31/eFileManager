<?php

namespace App\Livewire\Documents;

use App\Enums\Confidentiality;
use App\Exceptions\RoutingException;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\DocumentRoutingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Take a document into the system.
 *
 * The tracking number is issued on save and shown immediately, because the very
 * next thing the clerk does is write it on the paper in front of them. Routing
 * is deliberately a separate step on the document's own page — a number first,
 * then a decision about where it goes.
 */
class Register extends Component
{
    public ?int $document_type_id = null;

    public string $reference_no = '';

    public string $subject = '';

    public string $description = '';

    public ?int $origin_department_id = null;

    public string $origin_external_name = '';

    public string $confidentiality = 'internal';

    public string $due_at = '';

    public function mount(): void
    {
        $this->authorize('create', Document::class);
    }

    public function rules(): array
    {
        return [
            'document_type_id' => ['required', 'exists:document_types,id'],
            'reference_no' => ['nullable', 'string', 'max:64'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            // No default. Where a document came from is part of the record, and
            // a pre-filled origin is the kind of field that gets left wrong.
            'origin_department_id' => ['required', 'exists:departments,id'],
            'origin_external_name' => ['nullable', 'string', 'max:255'],
            'confidentiality' => ['required', Rule::enum(Confidentiality::class)],
            'due_at' => ['nullable', 'date'],
        ];
    }

    protected function messages(): array
    {
        return [
            'origin_department_id.required' => 'Choose where this document came from.',
            'document_type_id.required' => 'Choose what kind of document this is.',
        ];
    }

    public function save(DocumentRoutingService $routing)
    {
        $this->authorize('create', Document::class);

        $data = $this->validate();

        try {
            $document = $routing->register([
                'document_type_id' => $data['document_type_id'],
                'reference_no' => $data['reference_no'] ?: null,
                'subject' => $data['subject'],
                'description' => $data['description'] ?: null,
                'origin_department_id' => $data['origin_department_id'],
                'origin_external_name' => $data['origin_external_name'] ?: null,
                'confidentiality' => $data['confidentiality'],
                'due_at' => $data['due_at'] ?: null,
            ], Auth::user());
        } catch (RoutingException $e) {
            $this->addError('subject', $e->getMessage());

            return null;
        }

        session()->flash('status', "Registered as {$document->tracking_no}. Write this on the document.");

        return $this->redirectRoute('documents.show', $document, navigate: true);
    }

    public function render()
    {
        return view('livewire.documents.register', [
            'types' => DocumentType::active()->inMenuOrder()->get(),
            'origins' => Department::routable()->get(),
            'levels' => Confidentiality::all(),
        ])->layout('components.layouts.app', ['title' => 'Register a document']);
    }
}
