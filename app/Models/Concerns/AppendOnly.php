<?php

namespace App\Models\Concerns;

use RuntimeException;

/**
 * Makes a model write-once: rows may be created and read, never changed or
 * removed.
 *
 * Applied to the two records this system treats as evidence — the system audit
 * trail and a document's chain of custody. Both are relied on to answer
 * questions about the past, which they cannot do if the past is editable.
 *
 * The guard sits in the model rather than only in the interface because the
 * threat is not a stray button. It is a well-meaning fix six months from now:
 * a tinker session, a data-correction script, a seeder run against production.
 * Anything short of raw SQL is stopped here, loudly.
 *
 * The model must also declare `public const UPDATED_AT = null;` — a row that
 * cannot be updated has no business carrying a timestamp that says it was.
 */
trait AppendOnly
{
    protected static function bootAppendOnly(): void
    {
        static::updating(function ($model): never {
            throw new RuntimeException(static::appendOnlyMessage($model, 'modified'));
        });

        static::deleting(function ($model): never {
            throw new RuntimeException(static::appendOnlyMessage($model, 'deleted'));
        });
    }

    protected static function appendOnlyMessage(object $model, string $verb): string
    {
        return class_basename($model)." records are append-only and cannot be {$verb}. "
            .'Record a correcting entry instead.';
    }
}
