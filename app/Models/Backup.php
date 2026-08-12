<?php

namespace App\Models;

use App\Enums\BackupType;
use App\Support\Bytes;
use Database\Factories\BackupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A finished database or files backup.
 *
 * Write through App\Services\BackupService, never here — the row and the
 * bytes on the 'backups' disk are created and removed together, the same rule
 * as App\Models\File and for the same reason.
 */
#[Fillable(['type', 'disk_path', 'size', 'created_by'])]
class Backup extends Model
{
    /** @use HasFactory<BackupFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'type' => BackupType::class,
            'size' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function humanSize(): string
    {
        return Bytes::human($this->size);
    }
}
