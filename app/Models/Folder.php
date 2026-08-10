<?php

namespace App\Models;

use App\Enums\FolderVisibility;
use App\Enums\Permission;
use Database\Factories\FolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder in an office's drive.
 *
 * Folders belong to an office and never move between them. The office decides
 * who else may look inside, through the visibility setting.
 */
#[Fillable(['department_id', 'parent_id', 'name', 'visibility', 'is_system', 'created_by'])]
class Folder extends Model
{
    /** @use HasFactory<FolderFactory> */
    use HasFactory;

    /** The office root, created on demand. Cannot be renamed or removed. */
    public const ROOT_NAME = 'Office files';

    /** Where scans attached to tracked documents are filed. */
    public const DOCUMENTS_NAME = 'Document scans';

    /** @var array<string, mixed> */
    protected $attributes = [
        'visibility' => FolderVisibility::Department->value,
        'is_system' => false,
    ];

    protected function casts(): array
    {
        return [
            'visibility' => FolderVisibility::class,
            'is_system' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('name');
    }

    public function files(): HasMany
    {
        return $this->hasMany(File::class);
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
     * Confine a query to the folders this user may open.
     *
     * Enforced here, at the query, so that no breadcrumb, search or crafted
     * parameter can reach past it — the same posture as document visibility,
     * for the same reason.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can(Permission::DocumentsViewAllDepartments->value)) {
            return $query;
        }

        $officeId = $user->department_id;

        return $query->where(function (Builder $q) use ($user, $officeId) {
            // Anything shared with the whole LGU.
            $q->where('visibility', FolderVisibility::Internal->value)
                ->orWhere('visibility', FolderVisibility::Public->value);

            if ($officeId) {
                // My office's ordinary folders...
                $q->orWhere(fn (Builder $mine) => $mine
                    ->where('department_id', $officeId)
                    ->where('visibility', FolderVisibility::Department->value));

                // ...and my own private ones, wherever they sit.
                $q->orWhere(fn (Builder $own) => $own
                    ->where('visibility', FolderVisibility::Private->value)
                    ->where('created_by', $user->getKey()));
            }
        });
    }

    /** Folders whose contents this user may add to, rename or remove. */
    public function scopeWritableBy(Builder $query, ?User $user): Builder
    {
        if (! $user || ! $user->department_id) {
            return $query->whereRaw('1 = 0');
        }

        // Writing is always an act of the owning office. Sharing a folder with
        // the whole LGU makes it readable, never writable — otherwise "shared
        // with everyone" would quietly mean "editable by everyone".
        return $query->where('department_id', $user->department_id)
            ->where(fn (Builder $q) => $q
                ->where('visibility', '!=', FolderVisibility::Private->value)
                ->orWhere('created_by', $user->getKey()));
    }

    /*
    |--------------------------------------------------------------------------
    | Presentation
    |--------------------------------------------------------------------------
    */

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * This folder and every folder above it, outermost first.
     *
     * @return Collection<int, self>
     */
    public function breadcrumbs(): Collection
    {
        $trail = new Collection([$this]);
        $folder = $this;

        // Depth is bounded by the tree the office builds by hand, which is a
        // handful of levels. The guard is for a cycle introduced by a bad move,
        // not for depth.
        for ($i = 0; $i < 20 && $folder->parent_id; $i++) {
            $folder = $folder->parent;

            if (! $folder) {
                break;
            }

            $trail->prepend($folder);
        }

        return $trail;
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /** System folders are pointed at by other things and must keep their names. */
    public function isRenameable(): bool
    {
        return ! $this->is_system;
    }
}
