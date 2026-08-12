<?php

namespace App\Models;

use App\Enums\WorkspaceAppScope;
use App\Enums\WorkspaceAppStatus;
use Database\Factories\WorkspaceAppFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A system the LGU runs, published into the workspace catalog next to the
 * drive.
 *
 * Deliberately its own table, not a row in folders or files: an app has no
 * bytes, no version, nothing to download — it is a link and a few facts
 * about who may see it. Files and apps meet on the same page and answer to
 * the same search box; they never meet in the same table.
 */
#[Fillable([
    'name', 'slug', 'description', 'url', 'icon_glyph',
    'status', 'scope', 'department_id', 'sort_order', 'created_by',
])]
class WorkspaceApp extends Model
{
    /** @use HasFactory<WorkspaceAppFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => WorkspaceAppStatus::Pilot->value,
        'scope' => WorkspaceAppScope::Department->value,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkspaceAppStatus::class,
            'scope' => WorkspaceAppScope::class,
            'sort_order' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Confine a query to the apps this user may see: their own office's,
     * every office's, and the public ones — never another office's
     * department-scoped entry. Retired apps never show, for anyone, the same
     * way a withdrawn disclosure never shows on the public portal.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('status', '!=', WorkspaceAppStatus::Retired->value)
            ->where(function (Builder $q) use ($user) {
                $q->whereIn('scope', [WorkspaceAppScope::Organization->value, WorkspaceAppScope::Public->value]);

                if ($user->department_id) {
                    $q->orWhere(fn (Builder $mine) => $mine
                        ->where('scope', WorkspaceAppScope::Department->value)
                        ->where('department_id', $user->department_id));
                }
            });
    }

    /** Apps every office sees, regardless of who runs them. */
    public function scopeOrgWide(Builder $query): Builder
    {
        return $query->whereIn('scope', [WorkspaceAppScope::Organization->value, WorkspaceAppScope::Public->value]);
    }
}
