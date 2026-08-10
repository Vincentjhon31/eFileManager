<?php

namespace App\Services;

use App\Enums\ActionRequested;
use App\Enums\DocumentEvent;
use App\Enums\DocumentStatus;
use App\Enums\ReceiptMethod;
use App\Enums\RouteStatus;
use App\Exceptions\RoutingException;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentAction;
use App\Models\DocumentRoute;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Every legitimate change to a document's position, in one place.
 *
 * Nothing outside this class should write `status`, `current_holder_*` or any
 * column on document_routes. That is not a style preference — a document whose
 * status disagrees with its transmittal ledger is exactly the failure this
 * system exists to prevent, and there is no way to keep the two in step if the
 * writes are scattered across controllers.
 *
 * Two invariants hold throughout, and the tests exist to prove they hold under
 * concurrency, not merely in the happy path:
 *
 *  1. **One holder at a time.** Every act runs inside a transaction with the
 *     document row locked (SELECT ... FOR UPDATE). Two clerks pressing Release
 *     at the same instant cannot open two transmittals; the second waits, sees
 *     the document is already in transit, and is refused.
 *
 *  2. **Receiving is written once.** A receipt timestamp is never updated. The
 *     model refuses it outright. A correction is a new entry with a remark,
 *     which is what makes the ledger evidence rather than notes.
 *
 * The holder deliberately does *not* move when a document is released. Until
 * the destination signs, the sending office is still the one who has to answer
 * for where the paper is — which is both how the paper works and what makes
 * invariant 1 a true statement rather than an aspiration.
 */
class DocumentRoutingService
{
    public function __construct(
        private readonly TrackingNumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly Request $request,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    /**
     * Take a document into the system and issue its tracking number.
     *
     * @param  array<string, mixed>  $attributes  reference_no, document_type_id, subject,
     *                                            description, origin_department_id,
     *                                            origin_external_name, confidentiality, due_at
     */
    public function register(array $attributes, User $by, ?Department $office = null): Document
    {
        $office ??= $by->department;

        if (! $office) {
            throw RoutingException::noOffice();
        }

        if ($office->is_external) {
            throw RoutingException::externalOfficeCannotRegister($office);
        }

        // Retried on deadlock: the counter row for a brand-new month is created
        // by whichever registration gets there first, and the loser can collide.
        return DB::transaction(function () use ($attributes, $by, $office) {
            $document = new Document($attributes);

            $document->tracking_no = $this->numbers->next($office);
            $document->registering_department_id = $office->getKey();
            $document->status = DocumentStatus::Draft;
            $document->current_holder_department_id = $office->getKey();
            $document->current_holder_user_id = $by->getKey();
            $document->created_by = $by->getKey();
            $document->save();

            $this->record(
                document: $document,
                event: DocumentEvent::Registered,
                actor: $by,
                meta: ['office' => $office->code, 'origin' => $document->originLabel()],
                description: "Registered {$document->tracking_no} — {$document->subject}",
            );

            return $document;
        }, 3);
    }

    /*
    |--------------------------------------------------------------------------
    | Movement
    |--------------------------------------------------------------------------
    */

    /**
     * Send the document to another office, opening a transmittal.
     *
     * This is the same operation whether it is the first release or the tenth
     * forward — a leg is closed by receipt, not by the next release — so there
     * is one method rather than two that could drift apart. forward() and
     * returnToSender() are named wrappers over it.
     */
    public function release(
        Document $document,
        Department $to,
        ActionRequested $action,
        User $by,
        ?string $remarks = null,
        ?CarbonInterface $dueAt = null,
        ?User $toUser = null,
        bool $isReturn = false,
    ): DocumentRoute {
        return $this->withLockedDocument($document, function (Document $doc) use (
            $to, $action, $by, $remarks, $dueAt, $toUser, $isReturn
        ): DocumentRoute {
            if (! $doc->status->allowsRelease()) {
                throw RoutingException::expected($doc, [DocumentStatus::Draft, DocumentStatus::Received]);
            }

            $office = $this->officeOf($by);
            $this->assertHolder($doc, $office);

            if ($to->is($doc->currentHolderDepartment)) {
                throw RoutingException::sameOffice($to);
            }

            $leg = DocumentRoute::create([
                'document_id' => $doc->getKey(),
                // Safe under the document lock, and backed by the unique key on
                // (document_id, seq) if the lock is ever bypassed.
                'seq' => ((int) $doc->routes()->max('seq')) + 1,
                'from_department_id' => $office->getKey(),
                'from_user_id' => $by->getKey(),
                'from_actor_name' => $by->name,
                'to_department_id' => $to->getKey(),
                'to_user_id' => $toUser?->getKey(),
                'action_requested' => $action,
                'remarks' => $remarks,
                'is_return' => $isReturn,
                'due_at' => $dueAt,
                'sent_at' => now(),
            ]);

            $doc->status = DocumentStatus::InTransit;

            // The deadline the receiving office is being held to becomes the
            // document's live deadline, so "what is overdue" stays one indexed
            // comparison rather than a walk of the ledger.
            if ($dueAt) {
                $doc->due_at = $dueAt;
            }

            $doc->save();

            $verb = $isReturn ? DocumentEvent::Returned : DocumentEvent::Released;

            $this->record(
                document: $doc,
                event: $verb,
                actor: $by,
                route: $leg,
                remarks: $remarks,
                meta: [
                    'to' => $to->code,
                    'action_requested' => $action->value,
                    'due_at' => $dueAt?->toIso8601String(),
                    'addressed_to' => $toUser?->name,
                ],
                description: sprintf(
                    '%s %s to %s — %s',
                    $isReturn ? 'Returned' : 'Released',
                    $doc->tracking_no,
                    $to->displayName(),
                    mb_strtolower($action->label()),
                ),
            );

            return $leg;
        });
    }

    /** Send an already-received document onward. Reads better at call sites. */
    public function forward(
        Document $document,
        Department $to,
        ActionRequested $action,
        User $by,
        ?string $remarks = null,
        ?CarbonInterface $dueAt = null,
        ?User $toUser = null,
    ): DocumentRoute {
        return $this->release($document, $to, $action, $by, $remarks, $dueAt, $toUser);
    }

    /**
     * Send the document back to the office that last gave it to us.
     *
     * Recorded as a return rather than a forward because the distinction is one
     * staff act on: "it came back to me" means something was wrong with it.
     */
    public function returnToSender(
        Document $document,
        User $by,
        ?string $remarks = null,
        ActionRequested $action = ActionRequested::ForAppropriateAction,
        ?CarbonInterface $dueAt = null,
    ): DocumentRoute {
        $inbound = $document->routes()
            ->where('status', RouteStatus::Received->value)
            ->reorder('seq', 'desc')
            ->first();

        if (! $inbound) {
            throw RoutingException::nowhereToReturn($document);
        }

        return $this->release(
            document: $document,
            to: $inbound->fromDepartment,
            action: $action,
            by: $by,
            remarks: $remarks,
            dueAt: $dueAt,
            isReturn: true,
        );
    }

    /**
     * Sign for a document.
     *
     * Two ways in, and the difference is the whole reason one office can run
     * this system while the rest of the hall is on paper:
     *
     *  - **Digital** — the destination is onboarded, signed in, and pressed
     *    Receive. The system witnessed it and stamps its own clock.
     *  - **Paper** — the destination has no accounts. A clerk in the *sending*
     *    office records the name and time written on the signed transmittal.
     *    Weaker evidence, recorded honestly and labelled as such.
     */
    public function receive(
        Document $document,
        User $by,
        ReceiptMethod $method = ReceiptMethod::System,
        ?string $receivedByName = null,
        ?CarbonInterface $receivedAt = null,
    ): DocumentRoute {
        return $this->withLockedDocument($document, function (Document $doc) use (
            $by, $method, $receivedByName, $receivedAt
        ): DocumentRoute {
            if ($doc->status !== DocumentStatus::InTransit) {
                throw RoutingException::expected($doc, [DocumentStatus::InTransit]);
            }

            // reorder(), not orderByDesc(): the routes() relation is already
            // sorted ascending for the timeline, and appending a second clause
            // would leave the ascending one in charge and hand back the oldest
            // leg instead of the newest.
            $leg = $doc->routes()->where('status', RouteStatus::Pending->value)
                ->reorder('seq', 'desc')->lockForUpdate()->first();

            if (! $leg) {
                throw RoutingException::noOpenTransmittal($doc);
            }

            $office = $this->officeOf($by);
            $destination = $leg->toDepartment;

            if ($method->isWitnessed()) {
                if (! $office->is($destination)) {
                    throw RoutingException::notTheRecipient($destination);
                }

                if (! $destination->acceptsDigitalReceipt()) {
                    throw RoutingException::cannotReceiveDigitally($destination);
                }

                $receivedByName = $by->name;
                $receivedAt = now();
            } else {
                // The sender holds the signed transmittal, so either end may
                // record it. Nobody else may.
                if (! $office->is($destination) && $office->getKey() !== $leg->from_department_id) {
                    throw RoutingException::notTheRecipient($destination);
                }

                $receivedByName = trim((string) $receivedByName) ?: null;

                if (! $receivedByName) {
                    throw RoutingException::receiptNeedsASignatory();
                }

                $receivedAt ??= now();

                // A backdated receipt is the obvious way to make a missed
                // deadline look met. These guards cannot stop a plausible lie,
                // but they refuse an impossible one — and every paper receipt
                // is labelled unwitnessed wherever it is shown.
                if ($receivedAt->lt($leg->sent_at)) {
                    throw RoutingException::receiptBeforeRelease();
                }

                if ($receivedAt->gt(now())) {
                    throw RoutingException::receiptInTheFuture();
                }
            }

            $leg->forceFill([
                'received_at' => $receivedAt,
                'received_by' => $by->getKey(),
                'received_by_name' => $receivedByName,
                'receipt_method' => $method,
                'status' => RouteStatus::Received,
            ])->save();

            $doc->status = DocumentStatus::Received;
            $doc->current_holder_department_id = $leg->to_department_id;
            // The office signs for it; a named addressee keeps it, otherwise it
            // sits with the office until somebody is assigned.
            $doc->current_holder_user_id = $leg->to_user_id;
            $doc->save();

            $this->record(
                document: $doc,
                event: DocumentEvent::Received,
                actor: $by,
                route: $leg,
                meta: [
                    'method' => $method->value,
                    'signed_by' => $receivedByName,
                    'witnessed' => $method->isWitnessed(),
                    'received_at' => $receivedAt->toIso8601String(),
                ],
                description: sprintf(
                    '%s received by %s at %s (%s)',
                    $doc->tracking_no,
                    $destination->displayName(),
                    ph_datetime($receivedAt),
                    mb_strtolower($method->label()),
                ),
            );

            return $leg;
        });
    }

    /**
     * Take back a transmittal that has not been signed for.
     *
     * Misrouting is constant in a records office. Without this, a clerk who
     * sends to the wrong office has to ask them to receive it and send it back,
     * which puts a lie in the ledger. The recalled leg stays on the record.
     */
    public function recall(Document $document, User $by, string $reason): DocumentRoute
    {
        return $this->withLockedDocument($document, function (Document $doc) use ($by, $reason): DocumentRoute {
            if ($doc->status !== DocumentStatus::InTransit) {
                throw RoutingException::expected($doc, [DocumentStatus::InTransit]);
            }

            $leg = $doc->routes()->where('status', RouteStatus::Pending->value)
                ->reorder('seq', 'desc')->lockForUpdate()->first();

            if (! $leg) {
                throw RoutingException::noOpenTransmittal($doc);
            }

            $office = $this->officeOf($by);

            if ($office->getKey() !== $leg->from_department_id) {
                throw RoutingException::notTheSender($leg->fromDepartment);
            }

            $leg->forceFill(['status' => RouteStatus::Cancelled])->save();

            // The holder never moved during transit, so there is nothing to put
            // back — only the status to restore.
            $doc->status = $this->hasBeenReceived($doc)
                ? DocumentStatus::Received
                : DocumentStatus::Draft;
            $doc->save();

            $this->record(
                document: $doc,
                event: DocumentEvent::Recalled,
                actor: $by,
                route: $leg,
                remarks: $reason,
                meta: ['to' => $leg->toDepartment->code],
                description: sprintf(
                    'Recalled %s from %s before receipt',
                    $doc->tracking_no,
                    $leg->toDepartment->displayName(),
                ),
            );

            return $leg;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Working on it
    |--------------------------------------------------------------------------
    */

    /** Put the document on a named person's desk within the holding office. */
    public function assign(Document $document, ?User $to, User $by): Document
    {
        return $this->withLockedDocument($document, function (Document $doc) use ($to, $by): Document {
            if (! in_array($doc->status, [DocumentStatus::Draft, DocumentStatus::Received], true)) {
                throw RoutingException::expected($doc, [DocumentStatus::Draft, DocumentStatus::Received]);
            }

            $office = $this->officeOf($by);
            $this->assertHolder($doc, $office);

            if ($to && $to->department_id !== $doc->current_holder_department_id) {
                throw RoutingException::assigneeOutsideHoldingOffice($doc->currentHolderDepartment);
            }

            $doc->current_holder_user_id = $to?->getKey();
            $doc->save();

            $this->record(
                document: $doc,
                event: DocumentEvent::Assigned,
                actor: $by,
                meta: ['assigned_to' => $to?->name],
                description: $to
                    ? "Assigned {$doc->tracking_no} to {$to->displayName()}"
                    : "Returned {$doc->tracking_no} to the office pool",
            );

            return $doc;
        });
    }

    /** Add a note to the timeline without moving anything. */
    public function addRemarks(Document $document, User $by, string $remarks): DocumentAction
    {
        return $this->record(
            document: $document,
            event: DocumentEvent::Remarked,
            actor: $by,
            remarks: $remarks,
            description: "Remarks added to {$document->tracking_no}",
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Closing
    |--------------------------------------------------------------------------
    */

    /**
     * The work is done and no further routing is expected.
     *
     * Reachable from Draft as well as Received: an office that registers an
     * incoming letter and deals with it itself never sends it anywhere.
     */
    public function complete(Document $document, User $by, ?string $remarks = null): Document
    {
        return $this->withLockedDocument($document, function (Document $doc) use ($by, $remarks): Document {
            if (! in_array($doc->status, [DocumentStatus::Draft, DocumentStatus::Received], true)) {
                throw RoutingException::expected($doc, [DocumentStatus::Draft, DocumentStatus::Received]);
            }

            $this->assertHolder($doc, $this->officeOf($by));

            $doc->status = DocumentStatus::Completed;
            $doc->closed_at = now();
            $doc->save();

            $this->record(
                document: $doc,
                event: DocumentEvent::Completed,
                actor: $by,
                remarks: $remarks,
                description: "Completed {$doc->tracking_no}",
            );

            return $doc;
        });
    }

    /** File it away. Retained under RA 9470; nothing is ever auto-deleted. */
    public function archive(Document $document, User $by, ?string $remarks = null): Document
    {
        return $this->withLockedDocument($document, function (Document $doc) use ($by, $remarks): Document {
            if ($doc->status !== DocumentStatus::Completed) {
                throw RoutingException::expected($doc, [DocumentStatus::Completed]);
            }

            $this->assertHolder($doc, $this->officeOf($by));

            $doc->status = DocumentStatus::Archived;
            $doc->save();

            $this->record(
                document: $doc,
                event: DocumentEvent::Archived,
                actor: $by,
                remarks: $remarks,
                description: "Archived {$doc->tracking_no}",
            );

            return $doc;
        });
    }

    /** Put a closed document back on the desk. The trail keeps both facts. */
    public function reopen(Document $document, User $by, string $reason): Document
    {
        return $this->withLockedDocument($document, function (Document $doc) use ($by, $reason): Document {
            if (! in_array($doc->status, [DocumentStatus::Completed, DocumentStatus::Archived], true)) {
                throw RoutingException::expected($doc, [DocumentStatus::Completed, DocumentStatus::Archived]);
            }

            $this->assertHolder($doc, $this->officeOf($by));

            $doc->status = $this->hasBeenReceived($doc) ? DocumentStatus::Received : DocumentStatus::Draft;
            $doc->closed_at = null;
            $doc->save();

            $this->record(
                document: $doc,
                event: DocumentEvent::Reopened,
                actor: $by,
                remarks: $reason,
                description: "Reopened {$doc->tracking_no}",
            );

            return $doc;
        });
    }

    /**
     * Withdraw a document registered in error.
     *
     * The row and its whole trail stay. A tracking number is never reissued,
     * and a gap in the sequence is the correct record of a mistake.
     */
    public function cancel(Document $document, User $by, string $reason): Document
    {
        return $this->withLockedDocument($document, function (Document $doc) use ($by, $reason): Document {
            if (! $doc->status->isOpen()) {
                throw RoutingException::expected($doc, DocumentStatus::open());
            }

            $this->assertHolder($doc, $this->officeOf($by));

            // A transmittal nobody signed for dies with the document.
            $open = $doc->routes()->where('status', RouteStatus::Pending->value)->get();

            foreach ($open as $leg) {
                $leg->forceFill(['status' => RouteStatus::Cancelled])->save();
            }

            $doc->status = DocumentStatus::Cancelled;
            $doc->closed_at = now();
            $doc->save();

            $this->record(
                document: $doc,
                event: DocumentEvent::Cancelled,
                actor: $by,
                remarks: $reason,
                meta: ['cancelled_transmittals' => $open->pluck('seq')->all()],
                description: "Cancelled {$doc->tracking_no}",
            );

            return $doc;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /**
     * Run an act with the document row locked, then bring the caller's copy up
     * to date. Retried on deadlock, which is what a genuine collision looks
     * like to InnoDB.
     */
    private function withLockedDocument(Document $document, Closure $callback): mixed
    {
        $result = DB::transaction(function () use ($document, $callback) {
            $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            return $callback($locked);
        }, 3);

        $document->refresh();

        return $result;
    }

    private function officeOf(User $user): Department
    {
        return $user->department ?? throw RoutingException::noOffice();
    }

    private function assertHolder(Document $document, Department $office): void
    {
        // No administrative override, deliberately. In a records system you
        // cannot act on a document your office is not holding, and an exception
        // for system administrators would be the first thing a lawyer asked
        // about. If a document is genuinely stuck, the holding office moves it.
        if ($document->current_holder_department_id !== $office->getKey()) {
            throw RoutingException::notTheHolder($document, $office);
        }
    }

    private function hasBeenReceived(Document $document): bool
    {
        return $document->routes()->where('status', RouteStatus::Received->value)->exists();
    }

    /**
     * Write the act to the document's timeline and to the system audit trail,
     * in that order, from one place — so the two can never disagree about what
     * happened.
     *
     * @param  array<string, mixed>  $meta
     */
    private function record(
        Document $document,
        DocumentEvent $event,
        ?User $actor,
        ?DocumentRoute $route = null,
        ?string $remarks = null,
        array $meta = [],
        ?string $description = null,
    ): DocumentAction {
        $action = DocumentAction::create([
            'document_id' => $document->getKey(),
            'document_route_id' => $route?->getKey(),
            'user_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'department_id' => $actor?->department_id,
            'action' => $event,
            'remarks' => $remarks,
            'meta' => $meta ?: null,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);

        $this->audit->log(
            event: $event->auditEvent(),
            subject: $document,
            properties: array_filter([
                'tracking_no' => $document->tracking_no,
                'status' => $document->status->value,
                'route_seq' => $route?->seq,
                'remarks' => $remarks,
            ] + $meta, fn ($value) => $value !== null),
            description: $description,
            actor: $actor,
        );

        return $action;
    }
}
