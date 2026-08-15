<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A decision to show one photograph of one landmark to the public.
 *
 * The row is the capability, not the file — see the migration, and see
 * App\Models\PublicFile, which this deliberately mirrors. The welcome page asks
 * for photos by landmark id and gets back routes that name this row; nothing on
 * the public side ever names a file.
 *
 * Written by App\Livewire\Admin\Town and read by App\Support\World. The bytes
 * themselves belong to an office's drive like any other upload, and are put
 * there by FileStorageService, which is the only thing that writes files.
 */
#[Fillable(['landmark', 'file_id', 'caption', 'sort_order', 'created_by'])]
class LandmarkPhoto extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * What may be served without signing in.
     *
     * The one gate the public photo route passes through. It refuses a photo
     * whose file has been trashed for the same reason a disclosure does: a link
     * that quietly stops resolving looks like the municipality took something
     * down on purpose.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereHas('file', fn (Builder $f) => $f->whereNull('deleted_at'));
    }

    /** One landmark's photos, in the order somebody arranged them. */
    public function scopeForLandmark(Builder $query, string $landmark): Builder
    {
        return $query->where('landmark', $landmark)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /**
     * What the renderer needs, and nothing else.
     *
     * Deliberately not the model: this ends up inside a JSON script tag on a
     * public page, and everything in it is therefore published. A caption, a
     * size and a URL that names this row is the whole of it.
     *
     * @return array<string, mixed>
     */
    public function forThePayload(): array
    {
        return [
            'url' => route('public.photo', $this),
            'caption' => $this->caption,
            'alt' => $this->caption ?: 'A photograph of this place',
        ];
    }
}
