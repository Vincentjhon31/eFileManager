<?php

namespace App\Models;

use App\Enums\DocumentEvent;
use App\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a document's chain of custody.
 *
 * Write through App\Services\DocumentRoutingService, never here directly — the
 * service records the matching audit-trail entry in the same breath, and a
 * timeline entry without its audit counterpart is worse than neither.
 */
#[Fillable([
    'document_id', 'document_route_id', 'user_id', 'actor_name', 'department_id',
    'action', 'remarks', 'meta', 'ip_address', 'user_agent',
])]
class DocumentAction extends Model
{
    use AppendOnly;

    /** Only created_at exists; there is no updated_at by design. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => DocumentEvent::class,
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(DocumentRoute::class, 'document_route_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function scopeOldestFirst(Builder $query): Builder
    {
        return $query->orderBy('created_at')->orderBy('id');
    }

    /** Who did this, phrased for the timeline. */
    public function actorLabel(): string
    {
        $name = $this->actor_name ?? 'System';
        $office = $this->department?->displayName();

        return $office ? "{$name}, {$office}" : $name;
    }
}
