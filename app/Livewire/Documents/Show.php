<?php

namespace App\Livewire\Documents;

use App\Enums\ActionRequested;
use App\Enums\ReceiptMethod;
use App\Exceptions\RoutingException;
use App\Livewire\Concerns\RecordsReceipt;
use App\Models\Department;
use App\Models\Document;
use App\Models\User;
use App\Services\DocumentRoutingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * One document: where it is, where it has been, and what can be done with it.
 *
 * Every action here delegates to DocumentRoutingService and reports whatever it
 * refuses, verbatim. The service's rules are the real ones — this screen must
 * never decide for itself that something is allowed, because a second copy of
 * the state machine is a second chance to get it wrong.
 */
class Show extends Component
{
    use RecordsReceipt;

    public Document $document;

    /** Which action form is open: release, receive, recall, return, assign, remarks, close. */
    public string $panel = '';

    // Release / return
    public ?int $to_department_id = null;

    public ?int $to_user_id = null;

    public string $action_requested = '';

    public string $route_remarks = '';

    public string $route_due_at = '';

    // Assign, remarks, closing
    public ?int $assignee_id = null;

    public string $note = '';

    public function mount(Document $document): void
    {
        $this->authorize('view', $document);

        $this->document = $document;
        $this->action_requested = ($document->type->default_action ?? ActionRequested::ForAppropriateAction)->value;

        // Arriving from the counter's tracking-number lookup, where the clerk
        // is already holding the document and wants one thing.
        if (request('do') === 'receive' && Auth::user()->can('receive', $document)) {
            $this->open('receive');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Panels
    |--------------------------------------------------------------------------
    */

    public function open(string $panel): void
    {
        $this->resetValidation();
        $this->panel = $panel;

        if ($panel === 'receive') {
            $this->receipt_method = $this->defaultReceiptMethod($this->document);
        }
    }

    public function closePanel(): void
    {
        $this->reset([
            'panel', 'to_department_id', 'to_user_id', 'route_remarks', 'route_due_at',
            'received_by_name', 'received_at', 'assignee_id', 'note',
        ]);
        $this->resetValidation();
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    public function release(DocumentRoutingService $routing): void
    {
        $this->authorize('release', $this->document);

        $data = $this->validate([
            'to_department_id' => ['required', 'exists:departments,id'],
            'to_user_id' => ['nullable', 'exists:users,id'],
            'action_requested' => ['required', Rule::enum(ActionRequested::class)],
            'route_remarks' => ['nullable', 'string', 'max:2000'],
            'route_due_at' => ['nullable', 'date'],
        ]);

        $this->attempt(fn () => $routing->release(
            document: $this->document,
            to: Department::findOrFail($data['to_department_id']),
            action: ActionRequested::from($data['action_requested']),
            by: Auth::user(),
            remarks: $data['route_remarks'] ?: null,
            dueAt: $data['route_due_at'] ? now()->parse($data['route_due_at']) : null,
            toUser: $data['to_user_id'] ? User::find($data['to_user_id']) : null,
        ), 'Released.');
    }

    public function returnToSender(DocumentRoutingService $routing): void
    {
        $this->authorize('release', $this->document);

        $data = $this->validate([
            'route_remarks' => ['required', 'string', 'max:2000'],
            'action_requested' => ['required', Rule::enum(ActionRequested::class)],
        ]);

        $this->attempt(fn () => $routing->returnToSender(
            document: $this->document,
            by: Auth::user(),
            remarks: $data['route_remarks'],
            action: ActionRequested::from($data['action_requested']),
        ), 'Returned to the sending office.');
    }

    public function receive(DocumentRoutingService $routing): void
    {
        $this->authorize('receive', $this->document);

        $this->attempt(
            fn () => $this->recordReceipt($this->document, $routing),
            'Receipt recorded.',
        );
    }

    public function recall(DocumentRoutingService $routing): void
    {
        $this->authorize('recall', $this->document);

        $data = $this->validate(['note' => ['required', 'string', 'max:2000']], [
            'note.required' => 'Say why it is being recalled. This goes on the record.',
        ]);

        $this->attempt(
            fn () => $routing->recall($this->document, Auth::user(), $data['note']),
            'Transmittal recalled.',
        );
    }

    public function assign(DocumentRoutingService $routing): void
    {
        $this->authorize('act', $this->document);

        $data = $this->validate(['assignee_id' => ['nullable', 'exists:users,id']]);

        $this->attempt(fn () => $routing->assign(
            $this->document,
            $data['assignee_id'] ? User::find($data['assignee_id']) : null,
            Auth::user(),
        ), 'Assignment updated.');
    }

    public function addRemarks(DocumentRoutingService $routing): void
    {
        $this->authorize('comment', $this->document);

        $data = $this->validate(['note' => ['required', 'string', 'max:2000']]);

        $this->attempt(
            fn () => $routing->addRemarks($this->document, Auth::user(), $data['note']),
            'Remarks added.',
        );
    }

    public function complete(DocumentRoutingService $routing): void
    {
        $this->authorize('act', $this->document);

        $this->validate(['note' => ['nullable', 'string', 'max:2000']]);

        $this->attempt(
            fn () => $routing->complete($this->document, Auth::user(), $this->note ?: null),
            'Marked complete.',
        );
    }

    public function archive(DocumentRoutingService $routing): void
    {
        $this->authorize('act', $this->document);

        $this->attempt(
            fn () => $routing->archive($this->document, Auth::user(), $this->note ?: null),
            'Archived.',
        );
    }

    public function reopen(DocumentRoutingService $routing): void
    {
        $this->authorize('act', $this->document);

        $data = $this->validate(['note' => ['required', 'string', 'max:2000']], [
            'note.required' => 'Say why it is being reopened.',
        ]);

        $this->attempt(
            fn () => $routing->reopen($this->document, Auth::user(), $data['note']),
            'Reopened.',
        );
    }

    public function cancel(DocumentRoutingService $routing): void
    {
        $this->authorize('act', $this->document);

        $data = $this->validate(['note' => ['required', 'string', 'max:2000']], [
            'note.required' => 'Say why it is being withdrawn. The record is kept either way.',
        ]);

        $this->attempt(
            fn () => $routing->cancel($this->document, Auth::user(), $data['note']),
            'Withdrawn.',
        );
    }

    /**
     * Run a routing act and put whatever the service refuses in front of the
     * user unchanged. Those messages are written for a records clerk, so
     * rephrasing them here would only make them worse.
     */
    private function attempt(callable $act, string $success): void
    {
        try {
            $act();
        } catch (RoutingException $e) {
            $this->addError('routing', $e->getMessage());

            return;
        }

        $this->document->refresh();
        $this->closePanel();
        session()->flash('status', $success);
    }

    public function render()
    {
        $document = $this->document->load([
            'type', 'originDepartment', 'registeringDepartment',
            'currentHolderDepartment', 'currentHolderUser', 'creator',
            'routes.fromDepartment', 'routes.toDepartment', 'routes.receivedBy',
            'actions.department', 'actions.route.toDepartment',
        ]);

        $holder = $document->currentHolderDepartment;

        return view('livewire.documents.show', [
            'document' => $document,
            'timeline' => $document->actions()->oldestFirst()->with(['department', 'route.toDepartment'])->get(),
            'destinations' => Department::routable()->get()->reject(fn ($d) => $d->is($holder))->values(),
            'colleagues' => $holder
                ? User::active()->where('department_id', $holder->getKey())->orderBy('name')->get()
                : collect(),
            'actions' => ActionRequested::all(),
            'methods' => ReceiptMethod::all(),
            'can' => [
                'release' => Auth::user()->can('release', $document),
                'receive' => Auth::user()->can('receive', $document),
                'recall' => Auth::user()->can('recall', $document),
                'act' => Auth::user()->can('act', $document),
                'comment' => Auth::user()->can('comment', $document),
            ],
        ])->layout('components.layouts.app', ['title' => $document->tracking_no]);
    }
}
