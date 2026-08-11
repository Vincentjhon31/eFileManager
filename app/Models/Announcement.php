<?php

namespace App\Models;

use App\Enums\AnnouncementCategory;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A notice on the municipality's public page.
 *
 * Publishing is never a side effect of saving. An announcement is written,
 * saved, read back, and only then published by somebody who has been given that
 * permission — see App\Services\PublicationService. The distinction matters
 * because the audience is the whole town: a half-finished advisory about
 * suspended classes is worse than none.
 */
#[Fillable([
    'title', 'slug', 'category', 'excerpt', 'body',
    'expires_at', 'is_pinned', 'department_id', 'author_id',
])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => AnnouncementCategory::Notice->value,
        'is_pinned' => false,
    ];

    protected function casts(): array
    {
        return [
            'category' => AnnouncementCategory::class,
            'published_at' => 'datetime',
            'unpublished_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_pinned' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** Posters, bid forms and the like, attached and published alongside. */
    public function attachments(): HasMany
    {
        return $this->hasMany(PublicFile::class)->orderBy('title');
    }

    /*
    |--------------------------------------------------------------------------
    | Visibility
    |--------------------------------------------------------------------------
    */

    /**
     * What a member of the public may see.
     *
     * The single gate for every unauthenticated query. Three conditions, all
     * required: it was published, it has not been taken down, and it has not
     * expired. A draft has no route to the public page at all.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->whereNull('unpublished_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Newest first, with anything pinned held at the top. */
    public function scopeForTheFrontPage(Builder $query): Builder
    {
        return $query->orderByDesc('is_pinned')->orderByDesc('published_at')->orderByDesc('id');
    }

    public function isLive(): bool
    {
        return $this->published_at !== null
            && $this->published_at->isPast()
            && $this->unpublished_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Draft, live, expired or taken down — the state an editor thinks in. */
    public function statusLabel(): string
    {
        return match (true) {
            $this->published_at === null => 'Draft',
            $this->unpublished_at !== null => 'Taken down',
            $this->hasExpired() => 'Expired',
            $this->published_at->isFuture() => 'Scheduled',
            default => 'Live',
        };
    }

    public function statusTone(): string
    {
        return match ($this->statusLabel()) {
            'Live' => 'green',
            'Scheduled' => 'blue',
            'Expired', 'Taken down' => 'slate',
            default => 'amber',
        };
    }

    /**
     * Who the public should understand issued this.
     *
     * The office, never the person. A municipal notice is issued by the
     * municipality; putting a clerk's name on the public web would publish
     * personal data nobody asked to disclose.
     */
    public function issuedBy(): string
    {
        return $this->department?->name ?? config('lgu.name');
    }

    public function summary(): string
    {
        return $this->excerpt ?: Str::limit(strip_tags($this->body), 180);
    }

    /** A slug that stays unique without asking the writer to think about it. */
    public static function slugFor(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'notice';
        $slug = $base;
        $suffix = 2;

        while (static::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
