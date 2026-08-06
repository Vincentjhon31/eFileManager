<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Single entry point for writing the audit trail.
 *
 * Every call captures the actor and their office as they were at the moment of
 * the act. Actor name and department are denormalised onto the row on purpose:
 * when an employee later transfers to another office, the trail must still say
 * where they were standing when they received the document.
 */
class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * Record an event.
     *
     * @param  string  $event  Dotted event name, e.g. 'user.login', 'document.received'.
     * @param  Model|null  $subject  The thing acted upon, if any.
     * @param  array<string, mixed>  $properties  Context worth keeping (remarks, before/after).
     */
    public function log(
        string $event,
        ?Model $subject = null,
        array $properties = [],
        ?string $description = null,
        ?User $actor = null,
    ): AuditLog {
        $actor ??= Auth::user();

        return AuditLog::create([
            'user_id' => $actor?->getKey(),
            'department_id' => $actor?->department_id,
            'actor_name' => $actor?->name,
            'event' => $event,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }

    /**
     * Record an event with no authenticated actor — a failed sign-in, or a
     * scheduled job. Kept separate so an accidental null actor on a normal
     * call is visible rather than silently logged as anonymous.
     */
    public function logAnonymous(
        string $event,
        array $properties = [],
        ?string $description = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => null,
            'department_id' => null,
            'actor_name' => null,
            'event' => $event,
            'description' => $description,
            'properties' => $properties ?: null,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
        ]);
    }
}
