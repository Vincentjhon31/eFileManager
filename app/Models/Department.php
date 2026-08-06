<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An office of the municipal government, or an outside party documents are
 * exchanged with.
 *
 * Three kinds of row live here:
 *
 *   onboarded  — staff log in and act in the system (Mayor's Office at pilot)
 *   internal   — a real municipal office not yet using the system; a valid
 *                routing target whose legs are recorded as received manually
 *   external   — provincial, national, barangay or private parties; origin and
 *                destination only, never gets logins
 *
 * Keeping non-onboarded offices as first-class rows is what allows a single
 * office to run the pilot while still recording a complete, honest trail.
 */
#[Fillable([
    'code', 'name', 'short_name', 'parent_id', 'head_user_id',
    'is_onboarded', 'is_external', 'sort_order',
])]
class Department extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_onboarded' => 'boolean',
            'is_external' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** Offices of the municipal government itself. */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('is_external', false);
    }

    /** Offices whose staff actually use the system. */
    public function scopeOnboarded(Builder $query): Builder
    {
        return $query->where('is_onboarded', true);
    }

    /**
     * Anywhere a document may legitimately be sent. Includes offices that are
     * not onboarded — the routing trail stays complete either way.
     */
    public function scopeRoutable(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** Short name where one is set, full name otherwise. */
    public function displayName(): string
    {
        return $this->short_name ?: $this->name;
    }

    /**
     * Whether documents held here can be acted on inside the system, or only
     * tracked as having been handed over on paper.
     */
    public function acceptsDigitalReceipt(): bool
    {
        return $this->is_onboarded && ! $this->is_external;
    }
}
