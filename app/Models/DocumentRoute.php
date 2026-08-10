<?php

namespace App\Models;

use App\Enums\ActionRequested;
use App\Enums\ReceiptMethod;
use App\Enums\RouteStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One leg of a document's journey — a transmittal from one office to another.
 *
 * Unlike the audit trail this row is not wholly immutable: it is created when
 * the document is released and completed when the destination signs for it.
 * But the receipt itself is written exactly once. A receiving timestamp is the
 * fact this system is most likely to be asked to defend — a deadline met or
 * missed, a document that arrived before or after a decision — so a later edit
 * is refused outright and a correction has to be recorded as its own entry.
 */
#[Fillable([
    'document_id', 'seq',
    'from_department_id', 'from_user_id', 'from_actor_name',
    'to_department_id', 'to_user_id',
    'action_requested', 'remarks', 'is_return', 'due_at', 'sent_at',
])]
class DocumentRoute extends Model
{
    /**
     * A leg starts pending and going forward. These mirror the column defaults
     * so that a freshly created instance reports the same state as the row —
     * relying on the database default alone leaves the in-memory model with a
     * null status until it is reloaded.
     *
     * They are set here rather than added to the fillable list on purpose: the
     * receipt columns and the status must only ever be written by
     * DocumentRoutingService, never by mass assignment.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => RouteStatus::Pending->value,
        'is_return' => false,
    ];

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'action_requested' => ActionRequested::class,
            'receipt_method' => ReceiptMethod::class,
            'status' => RouteStatus::class,
            'is_return' => 'boolean',
            'due_at' => 'datetime',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (DocumentRoute $route): void {
            if ($route->isDirty('received_at') && $route->getOriginal('received_at') !== null) {
                throw new RuntimeException(
                    'A receipt has already been recorded on this transmittal and cannot be changed. '
                    .'Record a correcting entry instead.'
                );
            }
        });

        // Recall a leg, do not erase it. A transmittal ledger that hides the
        // mistakes is worth less than a paper logbook, which cannot hide them.
        static::deleting(function (): never {
            throw new RuntimeException('Transmittal entries cannot be deleted. Recall the leg instead.');
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function fromDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'from_department_id');
    }

    public function toDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'to_department_id');
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(DocumentAction::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', RouteStatus::Pending->value);
    }

    /** Legs this office has been sent and has not signed for. */
    public function scopeAwaitingReceiptBy(Builder $query, int $departmentId): Builder
    {
        return $query->pending()->where('to_department_id', $departmentId);
    }

    /** Legs this office sent that nobody has signed for yet — the chase list. */
    public function scopeReleasedBy(Builder $query, int $departmentId): Builder
    {
        return $query->pending()->where('from_department_id', $departmentId);
    }

    public function isPending(): bool
    {
        return $this->status === RouteStatus::Pending;
    }

    public function isOverdue(): bool
    {
        return $this->isPending() && $this->due_at !== null && $this->due_at->isPast();
    }

    /** How long the destination took to sign for it, once they had. */
    public function daysInTransit(): ?int
    {
        return $this->received_at
            ? (int) $this->sent_at->diffInDays($this->received_at)
            : null;
    }
}
