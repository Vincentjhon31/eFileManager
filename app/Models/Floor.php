<?php

namespace App\Models;

use Database\Factories\FloorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * One floor, and the drawing of it.
 */
#[Fillable(['building_id', 'level', 'name', 'slug', 'svg_path', 'has_map', 'sort_order'])]
class Floor extends Model
{
    /** @use HasFactory<FloorFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'has_map' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class)->orderBy('sort_order')->orderBy('name');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopeMapped(Builder $query): Builder
    {
        return $query->where('has_map', true)->whereNotNull('svg_path');
    }

    /**
     * The drawing itself, ready to be inlined.
     *
     * Read from disk and cached rather than stored in the database: it is an
     * asset that belongs in version control beside the rest of the interface,
     * where a draughtsman can replace it in a commit and a reviewer can see
     * what changed.
     *
     * Returns null rather than throwing when the file is absent, so a floor
     * whose drawing has not been made yet degrades to its room list instead of
     * taking the page down.
     */
    public function svg(): ?string
    {
        if (! $this->svg_path) {
            return null;
        }

        $path = resource_path('svg/'.ltrim($this->svg_path, '/'));

        // Guard against a path that climbs out of the assets directory. The
        // value comes from a seeder today, but this is one string away from
        // being editable in an admin screen.
        if (! str_starts_with(realpath($path) ?: '', realpath(resource_path('svg')) ?: "\0")) {
            return null;
        }

        return Cache::remember(
            "floor-svg:{$this->id}:".(@filemtime($path) ?: 0),
            now()->addDay(),
            fn () => is_readable($path) ? file_get_contents($path) : null,
        );
    }

    public function hasDrawing(): bool
    {
        return $this->has_map && $this->svg() !== null;
    }

    public function displayName(): string
    {
        return $this->building?->name
            ? "{$this->name}, {$this->building->name}"
            : $this->name;
    }
}
