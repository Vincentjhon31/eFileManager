<?php

namespace App\Models;

use App\Enums\Confidentiality;
use App\Enums\DocumentStatus;
use App\Enums\Permission;
use App\Enums\RouteStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An official document under tracking.
 *
 * State changes belong to App\Services\DocumentRoutingService and nowhere else.
 * Setting `status` or `current_holder_department_id` by hand will produce a
 * document whose position disagrees with its transmittal ledger, which is the
 * one failure this system exists to prevent.
 */
#[Fillable([
    'reference_no', 'document_type_id', 'subject', 'description',
    'origin_department_id', 'origin_external_name', 'confidentiality', 'due_at',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * Mirrors the column defaults so a newly built instance is never in a
     * half-null state.
     *
     * Without these, a document created without an explicit confidentiality
     * gets the right value in the database and a null one in memory — and the
     * object handed back to the caller then blows up the moment anything asks
     * that enum a question. The database default alone is not enough.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => DocumentStatus::Draft->value,
        'confidentiality' => Confidentiality::Internal->value,
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'confidentiality' => Confidentiality::class,
            'due_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations
    |--------------------------------------------------------------------------
    */

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function originDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'origin_department_id');
    }

    public function registeringDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'registering_department_id');
    }

    public function currentHolderDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'current_holder_department_id');
    }

    public function currentHolderUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_holder_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(DocumentRoute::class)->orderBy('seq');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(DocumentAction::class)->orderBy('id');
    }

    /**
     * The leg that has been sent but not yet signed for. There is at most one,
     * enforced by the routing service inside a locked transaction and backed by
     * the unique key on (document_id, seq).
     */
    public function openRoute(): HasOne
    {
        return $this->hasOne(DocumentRoute::class)
            ->where('status', RouteStatus::Pending->value)
            ->latestOfMany('seq');
    }

    public function latestRoute(): HasOne
    {
        return $this->hasOne(DocumentRoute::class)->latestOfMany('seq');
    }

    /*
    |--------------------------------------------------------------------------
    | Visibility
    |--------------------------------------------------------------------------
    */

    /**
     * Confine a query to what this user is allowed to see.
     *
     * This is the access control, not a convenience — it runs at the query
     * layer so that no page, filter, sort or crafted parameter can reach past
     * it. Every listing and every lookup must go through it.
     *
     * The rule staff are taught is one sentence: *you can see a document if it
     * has passed through your office.* Confidential documents then narrow that
     * further, to the office head, the department administrator, the person
     * holding it and whoever registered it.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        // Deny by default. An unauthenticated or unassigned caller sees nothing
        // rather than everything, which is the failure mode that matters.
        if (! $user || ! $user->department_id) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can(Permission::DocumentsViewAllDepartments->value)) {
            return $query;
        }

        $officeId = $user->department_id;

        // Baseline: my office registered it, it came from my office, or it has
        // been on a transmittal to or from my office. Current custody needs no
        // separate clause — the office holding a document is always either its
        // registering office or the destination of a leg.
        $query->where(function (Builder $q) use ($officeId) {
            $q->where('registering_department_id', $officeId)
                ->orWhere('origin_department_id', $officeId)
                ->orWhereHas('routes', fn (Builder $leg) => $leg->where(
                    fn (Builder $side) => $side
                        ->where('from_department_id', $officeId)
                        ->orWhere('to_department_id', $officeId)
                ));
        });

        if (! $user->can(Permission::DocumentsViewConfidential->value)) {
            $query->where(function (Builder $q) use ($user) {
                $q->where('confidentiality', '!=', Confidentiality::Confidential->value)
                    ->orWhere('current_holder_user_id', $user->id)
                    ->orWhere('created_by', $user->id);
            });
        }

        return $query;
    }

    /*
    |--------------------------------------------------------------------------
    | Desk queries
    |--------------------------------------------------------------------------
    */

    /** Still moving through the LGU. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn(
            'status',
            array_map(fn (DocumentStatus $s) => $s->value, DocumentStatus::open()),
        );
    }

    /** Signed for by this office and awaiting action. */
    public function scopeOnDeskOf(Builder $query, int $departmentId): Builder
    {
        return $query
            ->where('current_holder_department_id', $departmentId)
            ->where('status', DocumentStatus::Received->value);
    }

    /** Past its deadline and nobody has closed it. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_at')->where('due_at', '<', now());
    }

    /**
     * Due by N days from now, already-late ones included.
     *
     * One condition rather than "overdue OR due soon", because that is the
     * actual question a reminder asks: what should this person be thinking
     * about today.
     */
    public function scopeDueBy(Builder $query, int $days): Builder
    {
        return $query->open()
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDays($days));
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    public function isOverdue(): bool
    {
        return $this->status->isOpen()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    public function isConfidential(): bool
    {
        return $this->confidentiality === Confidentiality::Confidential;
    }

    /** Where the paper came from, external sender included when there is one. */
    public function originLabel(): string
    {
        $office = $this->originDepartment?->displayName() ?? 'Unknown origin';

        return $this->origin_external_name
            ? "{$this->origin_external_name} ({$office})"
            : $office;
    }

    /** Where it is right now, phrased the way a clerk would answer out loud. */
    public function locationLabel(): string
    {
        return match ($this->status) {
            DocumentStatus::Draft => 'Not yet released — '
                .($this->currentHolderDepartment?->displayName() ?? 'unassigned'),

            DocumentStatus::InTransit => 'In transit to '
                .($this->openRoute?->toDepartment?->displayName() ?? 'another office')
                .' — still charged to '
                .($this->currentHolderDepartment?->displayName() ?? 'the sender'),

            DocumentStatus::Received => 'With '
                .($this->currentHolderDepartment?->displayName() ?? 'an office')
                .($this->currentHolderUser ? ' — '.$this->currentHolderUser->name : ''),

            DocumentStatus::Completed, DocumentStatus::Archived => 'Closed at '
                .($this->currentHolderDepartment?->displayName() ?? 'an office'),

            DocumentStatus::Cancelled => 'Withdrawn',
        };
    }
}
