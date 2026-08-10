<?php

namespace App\Models;

use Database\Factories\FileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A stored file.
 *
 * Write through App\Services\FileStorageService, never here. The row and the
 * bytes on disk have to be created, moved and removed together, and a row
 * whose file is missing — or a file with no row, which nothing will ever clean
 * up — is the kind of quiet corruption a records office discovers years later.
 *
 * Uploading a replacement never overwrites anything. It adds a row to the same
 * version group and moves the is_current flag, so every version an office has
 * ever held is still there.
 */
#[Fillable([
    'folder_id', 'department_id', 'name', 'original_name', 'mime', 'size',
    'sha256', 'storage_path', 'version_group_id', 'version_no', 'is_current',
    'uploaded_by',
])]
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'version_no' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'document_files')
            ->withPivot(['kind', 'attached_by', 'created_at']);
    }

    /** Every version of this file, newest first, including this one. */
    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'version_group_id', 'version_group_id')
            ->orderByDesc('version_no');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /** The version in use. Older ones stay, but are not what a listing shows. */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    /**
     * Confine a query to what this user may read.
     *
     * A file inherits its folder's visibility — there are no per-file
     * permissions. One rule, in one place, that an office administrator can
     * explain to a clerk in a sentence.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('folder', fn (Builder $f) => $f->visibleTo($user));
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    /**
     * "2.4 MB".
     *
     * Written out rather than using Number::fileSize, which needs the intl
     * extension. Requiring an extension across the whole deployment — and
     * discovering it is missing on the host — is a poor trade for one label.
     */
    public function humanSize(): string
    {
        $bytes = max(0, (int) $this->size);
        $units = ['B', 'KB', 'MB', 'GB'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < count($units) - 1) {
            $bytes /= 1024;
            $unit++;
        }

        return round($bytes, $unit >= 2 ? 1 : 0).' '.$units[$unit];
    }

    /** Whether the browser can be trusted to display this in a sandbox. */
    public function isPreviewable(): bool
    {
        return in_array($this->mime, config('drive.previewable_mimes', []), true);
    }

    public function extension(): string
    {
        return mb_strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    /** A short label for the file-type chip in listings. */
    public function kindLabel(): string
    {
        return match (true) {
            $this->mime === 'application/pdf' => 'PDF',
            str_starts_with($this->mime, 'image/') => 'Image',
            str_contains($this->mime, 'spreadsheet'), str_contains($this->mime, 'excel') => 'Sheet',
            str_contains($this->mime, 'word'), str_contains($this->mime, 'opendocument.text') => 'Document',
            str_contains($this->mime, 'presentation') => 'Slides',
            str_starts_with($this->mime, 'text/') => 'Text',
            $this->mime === 'application/zip' => 'Archive',
            default => mb_strtoupper($this->extension()) ?: 'File',
        };
    }

    public function hasOlderVersions(): bool
    {
        return $this->version_no > 1;
    }
}
