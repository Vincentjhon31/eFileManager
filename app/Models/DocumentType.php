<?php

namespace App\Models;

use App\Enums\ActionRequested;
use Database\Factories\DocumentTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of official document.
 *
 * Types are configuration, not code: an LGU will want to add its own over time,
 * and the retention period on each is what the RA 9470 disposal report is built
 * from. A type is retired by clearing is_active rather than deleted, because
 * documents already registered under it must keep their type.
 */
#[Fillable([
    'code', 'name', 'description', 'default_action',
    'retention_years', 'is_active', 'sort_order',
])]
class DocumentType extends Model
{
    /** @use HasFactory<DocumentTypeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'default_action' => ActionRequested::class,
            'retention_years' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInMenuOrder(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
