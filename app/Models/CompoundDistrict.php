<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A block of the compound's ground that the municipality has taken in.
 *
 * A row means unlocked; there are no rows for the rest. See the migration for
 * why it is stored that way round.
 */
#[Fillable(['dx', 'dy', 'unlocked_by'])]
class CompoundDistrict extends Model
{
    protected function casts(): array
    {
        return [
            'dx' => 'integer',
            'dy' => 'integer',
        ];
    }

    public function unlocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }

    /** How a district is named everywhere else — in the payload, in the audit. */
    public function key(): string
    {
        return $this->dx.','.$this->dy;
    }
}
