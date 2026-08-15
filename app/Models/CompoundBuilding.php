<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing standing in the compound.
 *
 * With a department it is that office's building; without one it is scenery —
 * the gate, the flagpole, a tree. Both are placed and dragged the same way,
 * because from the point of view of somebody arranging the compound there is no
 * difference between them.
 *
 * Coordinates are grid cells, never pixels. See the migration for why.
 */
#[Fillable([
    'department_id', 'sprite', 'style', 'gx', 'gy', 'w', 'h', 'height',
    'wall', 'roof', 'updated_by',
])]
class CompoundBuilding extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'sprite' => 'office',
        'style' => 'plain',
        'w' => 2,
        'h' => 2,
        'height' => 26,
    ];

    protected function casts(): array
    {
        return [
            'gx' => 'integer',
            'gy' => 'integer',
            'w' => 'integer',
            'h' => 'integer',
            'height' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Buildings, nearest the front of the map first. */
    public function scopeInDrawingOrder(Builder $query): Builder
    {
        return $query->orderBy('gy')->orderBy('gx');
    }

    /*
    |--------------------------------------------------------------------------
    | Placement
    |--------------------------------------------------------------------------
    */

    /** Every cell this building stands on. */
    public function cells(): array
    {
        $cells = [];

        for ($x = $this->gx; $x < $this->gx + $this->w; $x++) {
            for ($y = $this->gy; $y < $this->gy + $this->h; $y++) {
                $cells[] = $x.','.$y;
            }
        }

        return $cells;
    }

    /*
     * There is deliberately no fitsOnTheGrid() here any more.
     *
     * A building used to be asked whether it was inside a fixed twenty-eight
     * cell square, which the model could answer on its own because the square
     * never moved. The compound is now exactly as big as the ground taken into
     * it, so "does this fit" is a question about the land — and the land is not
     * something a single row knows about. App\Support\Compound::isBuildable()
     * is the one place that answers it.
     */

    public function overlaps(self $other): bool
    {
        return $this->gx < $other->gx + $other->w
            && $other->gx < $this->gx + $this->w
            && $this->gy < $other->gy + $other->h
            && $other->gy < $this->gy + $this->h;
    }
}
