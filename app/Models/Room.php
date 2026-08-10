<?php

namespace App\Models;

use App\Enums\RoomType;
use Database\Factories\RoomFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A room on a floor, and the office that works in it.
 *
 * The building map is a *view* of the document system, not a second model of
 * it. A room holds no documents; it points at a department, and the department
 * holds the caseload. Deleting every room on this floor would take the picture
 * away and change nothing about what the LGU can do — which is the property
 * that keeps a decorative idea from becoming a maintenance trap.
 */
#[Fillable([
    'floor_id', 'room_no', 'name', 'type', 'department_id',
    'svg_shape_id', 'centroid_x', 'centroid_y', 'sort_order',
])]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => RoomType::Office->value,
    ];

    protected function casts(): array
    {
        return [
            'type' => RoomType::class,
            'centroid_x' => 'float',
            'centroid_y' => 'float',
            'sort_order' => 'integer',
        ];
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(Floor::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Rooms that stand for an office and can therefore light up. */
    public function scopeOccupied(Builder $query): Builder
    {
        return $query->whereNotNull('department_id');
    }

    public function scopeOfType(Builder $query, RoomType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    /** Rooms that are drawn and can be coloured. */
    public function scopeOnTheMap(Builder $query): Builder
    {
        return $query->whereNotNull('svg_shape_id');
    }

    public function isOccupied(): bool
    {
        return $this->department_id !== null;
    }

    public function hasBadgePosition(): bool
    {
        return $this->centroid_x !== null && $this->centroid_y !== null;
    }

    /** Room number and name, the way it would be said out loud. */
    public function displayName(): string
    {
        return $this->room_no ? "{$this->room_no} — {$this->name}" : $this->name;
    }
}
