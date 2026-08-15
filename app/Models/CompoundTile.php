<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cell of the compound with something laid on it.
 *
 * Grass is the absence of a row — see the migration for why it is stored that
 * way round.
 */
#[Fillable(['x', 'y', 'kind', 'updated_by'])]
class CompoundTile extends Model
{
    protected function casts(): array
    {
        return [
            'x' => 'integer',
            'y' => 'integer',
        ];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
