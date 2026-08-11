<?php

namespace App\Models;

use App\Enums\DisclosureCategory;
use Database\Factories\PublicFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A decision to show a file to the public.
 *
 * The row is the capability, not the file. Every public download names a
 * public_files id and is refused unless that row is published — so there is no
 * path from a guessed file id in the drive to a file on the public web, and
 * revoking a disclosure is one column, not a hunt for links.
 *
 * Nothing is copied. The same bytes serve the office and the public; what
 * changes is whether this row says they may be served without signing in.
 */
#[Fillable([
    'file_id', 'title', 'description', 'category',
    'fiscal_year', 'announcement_id', 'created_by',
])]
class PublicFile extends Model
{
    /** @use HasFactory<PublicFileFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => DisclosureCategory::Other->value,
        'download_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'category' => DisclosureCategory::class,
            'fiscal_year' => 'integer',
            'download_count' => 'integer',
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
        ];
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function announcement(): BelongsTo
    {
        return $this->belongsTo(Announcement::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /*
    |--------------------------------------------------------------------------
    | Visibility
    |--------------------------------------------------------------------------
    */

    /**
     * What may be served without signing in.
     *
     * The one gate the public download route passes through. It also refuses a
     * publication whose underlying file has been trashed: a disclosure that
     * quietly stops resolving is worse than one that was withdrawn on purpose.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereNull('unpublished_at')
            ->whereHas('file', fn (Builder $f) => $f->whereNull('deleted_at'));
    }

    /** On the disclosure board rather than attached to a notice. */
    public function scopeOnTheBoard(Builder $query): Builder
    {
        return $query->whereNull('announcement_id');
    }

    public function scopeInCategory(Builder $query, DisclosureCategory $category): Builder
    {
        return $query->where('category', $category->value);
    }

    public function isLive(): bool
    {
        return $this->published_at !== null
            && $this->published_at->isPast()
            && $this->unpublished_at === null
            && $this->file !== null;
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->published_at === null => 'Not published',
            $this->unpublished_at !== null => 'Withdrawn',
            $this->published_at->isFuture() => 'Scheduled',
            default => 'Published',
        };
    }

    public function statusTone(): string
    {
        return match ($this->statusLabel()) {
            'Published' => 'green',
            'Scheduled' => 'blue',
            'Withdrawn' => 'slate',
            default => 'amber',
        };
    }

    /** "Annual budget · FY 2026", the way it reads on the board. */
    public function shelfLabel(): string
    {
        return $this->fiscal_year
            ? "{$this->category->label()} · FY {$this->fiscal_year}"
            : $this->category->label();
    }
}
