<?php

namespace App\Policies;

use App\Enums\DocumentStatus;
use App\Enums\Permission;
use App\Enums\RouteStatus;
use App\Models\Document;
use App\Models\User;

/**
 * Who may do what to a document.
 *
 * The policy answers "may this person press this button". It is not the only
 * guard: listings are confined by Document::scopeVisibleTo at the query layer,
 * and the state machine's own rules live in DocumentRoutingService, which
 * refuses an illegal act regardless of what the policy said. Three layers,
 * because the cost of getting this wrong is a personnel file read by the wrong
 * office, which under RA 10173 is a reportable breach.
 */
class DocumentPolicy
{
    /**
     * Deliberately does not require an office. Someone who has not been
     * assigned one sees an empty desk and a line telling them why, which is
     * more use than a 403 they can do nothing about — and safe, because
     * scopeVisibleTo returns nothing for them regardless.
     */
    public function viewAny(User $user): bool
    {
        return $user->canAny([
            Permission::DocumentsViewOwnDepartment->value,
            Permission::DocumentsViewAllDepartments->value,
        ]);
    }

    /**
     * Deliberately implemented by asking the same scope the listings use,
     * rather than restating its conditions. Two copies of a visibility rule
     * drift, and the drift is silent until someone reads a document they
     * should not have.
     */
    public function view(User $user, Document $document): bool
    {
        return Document::query()
            ->visibleTo($user)
            ->whereKey($document->getKey())
            ->exists();
    }

    public function create(User $user): bool
    {
        return $user->department_id !== null
            && ! $user->department->is_external
            && $user->can(Permission::DocumentsCreate->value);
    }

    /** Send it onward. Only the office holding it, and only while it is here. */
    public function release(User $user, Document $document): bool
    {
        return $this->holds($user, $document)
            && $document->status->allowsRelease()
            && $user->can(Permission::DocumentsRoute->value)
            && $this->view($user, $document);
    }

    /**
     * Sign for it.
     *
     * The destination office receives it in the system. The *sending* office
     * may also record a receipt, because when the destination has not onboarded
     * the only evidence is the signed transmittal sitting on the sender's desk.
     */
    public function receive(User $user, Document $document): bool
    {
        if ($document->status !== DocumentStatus::InTransit
            || ! $user->can(Permission::DocumentsReceive->value)) {
            return false;
        }

        $leg = $document->routes()
            ->where('status', RouteStatus::Pending->value)
            ->reorder('seq', 'desc')
            ->first();

        if (! $leg) {
            return false;
        }

        return in_array(
            $user->department_id,
            [$leg->to_department_id, $leg->from_department_id],
            true,
        );
    }

    /** Take back a transmittal nobody has signed for. Sender only. */
    public function recall(User $user, Document $document): bool
    {
        if ($document->status !== DocumentStatus::InTransit
            || ! $user->can(Permission::DocumentsRoute->value)) {
            return false;
        }

        $leg = $document->routes()
            ->where('status', RouteStatus::Pending->value)
            ->reorder('seq', 'desc')
            ->first();

        return $leg !== null && $leg->from_department_id === $user->department_id;
    }

    /** Assign, remark, complete, archive, cancel, reopen. */
    public function act(User $user, Document $document): bool
    {
        return $this->holds($user, $document)
            && $user->can(Permission::DocumentsAct->value)
            && $this->view($user, $document);
    }

    /** Add a note. Anyone who can see it and holds it. */
    public function comment(User $user, Document $document): bool
    {
        return $this->holds($user, $document) && $this->view($user, $document);
    }

    private function holds(User $user, Document $document): bool
    {
        return $user->department_id !== null
            && $user->department_id === $document->current_holder_department_id;
    }
}
