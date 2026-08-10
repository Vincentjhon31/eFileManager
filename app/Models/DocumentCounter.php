<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The last tracking-number sequence issued by one office in one month.
 *
 * Only App\Services\TrackingNumberGenerator should touch this. Incrementing it
 * anywhere else risks handing out a number that has already been written on a
 * piece of paper.
 */
#[Fillable(['department_id', 'year', 'month', 'last_seq'])]
class DocumentCounter extends Model
{
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'last_seq' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
