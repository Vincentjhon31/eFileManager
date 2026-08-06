<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RuntimeException;

/**
 * An immutable record of something that happened.
 *
 * Write through App\Services\AuditLogger, never by calling create() here
 * directly — the service captures actor, department, IP and user agent
 * consistently.
 *
 * Updates and deletes are blocked at the model layer as well as being absent
 * from the UI, because an audit trail that can be edited is not evidence. If
 * something was logged wrongly, log a correcting event; do not rewrite history.
 */
#[Fillable([
    'user_id', 'department_id', 'actor_name', 'event',
    'auditable_type', 'auditable_id', 'description',
    'properties', 'ip_address', 'user_agent',
])]
class AuditLog extends Model
{
    /** Only created_at exists; there is no updated_at by design. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Audit logs are append-only and cannot be modified.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Audit logs are append-only and cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
