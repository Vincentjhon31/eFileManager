<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One system setting, overriding one config key.
 *
 * Write through App\Services\SystemSettings, never here: a setting that is
 * saved without flushing the cache is a setting that appears not to have
 * changed until the cache happens to expire, which is the kind of thing an
 * administrator reports as "the system ignored me".
 */
#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        // 'json' rather than 'array': a setting is as likely to be a number, a
        // string or a boolean as it is a list, and this cast decodes each back
        // to the type it was stored as.
        return ['value' => 'json'];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
