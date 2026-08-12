<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The handful of config values the LGU may change for itself.
 *
 * Each setting names a config key and replaces it at boot. Nothing downstream
 * has to know a value is settable — config('drive.max_upload_mb') keeps working
 * and simply returns the municipality's answer instead of the file's. That is
 * the whole point: no second source of truth, no `Setting::get()` calls
 * sprinkled through the codebase, and a setting that is removed from this map
 * quietly reverts to the config file rather than becoming a null.
 *
 * What is NOT here is as deliberate as what is. The LGU code is fixed: it is
 * the leading segment of every tracking number ever issued (BGB-MO-2026-08-0001)
 * and changing it would orphan every document already registered. Anything
 * structural — database, mail transport, disks — stays in the environment,
 * where changing it is a deployment somebody reviewed.
 */
class SystemSettings
{
    private const CACHE_KEY = 'system-settings';

    /** An hour is long enough to be worth caching and short enough that a
     *  hand-edited row is not invisible until the next deploy. Every write
     *  through put() flushes it immediately anyway. */
    private const CACHE_TTL = 3600;

    /**
     * Setting key => how it is validated and shown.
     *
     * The key is the config key it overrides. 'default' is only used by the
     * settings screen to show what the file says when nothing has been set;
     * the actual fallback at runtime is the config file itself.
     *
     * @return array<string, array{label: string, type: string, hint?: string, min?: int, max?: int, options?: array<string, string>}>
     */
    public static function schema(): array
    {
        return [
            'app.name' => [
                'label' => 'System name',
                'type' => 'string',
                'hint' => 'Shown in the sidebar, the browser tab and outgoing email.',
            ],
            'lgu.name' => [
                'label' => 'Municipality',
                'type' => 'string',
                'hint' => 'Appears on the public portal and on printed routing slips.',
            ],
            'lgu.province' => [
                'label' => 'Province',
                'type' => 'string',
            ],
            'drive.max_upload_mb' => [
                'label' => 'Largest upload',
                'type' => 'int',
                'min' => 1,
                'max' => 512,
                'hint' => 'Megabytes. PHP has its own limit and PHP\'s wins silently — '
                    .'upload_max_filesize and post_max_size on the server must both be higher than this.',
            ],
            'backups.keep_per_type' => [
                'label' => 'Backups to keep',
                'type' => 'int',
                'min' => 1,
                'max' => 50,
                'hint' => 'Of each kind — database and files. Older ones are deleted as newer ones finish.',
            ],
            'auth.google_enabled' => [
                'label' => 'Allow Google sign-in',
                'type' => 'bool',
                'hint' => 'Lets employees sign in with, and link, their municipal Google account. '
                    .'Credentials must also be configured on the server. Password sign-in is always available '
                    .'and has no switch — turning it off could lock everybody out.',
            ],
            'session.lifetime' => [
                'label' => 'Sign out after',
                'type' => 'int',
                'min' => 5,
                'max' => 1440,
                'hint' => 'Minutes of inactivity before a session ends. Shorter is safer on shared office machines.',
            ],
            'digest.enabled' => [
                'label' => 'Send the morning digest',
                'type' => 'bool',
                'hint' => 'Off stops it for everybody, whatever each employee has chosen for themselves.',
            ],
            'digest.time' => [
                'label' => 'Digest send time',
                'type' => 'time',
                'hint' => 'Philippine time, weekday mornings.',
            ],
            'digest.due_within' => [
                'label' => 'Count as due within',
                'type' => 'int',
                'min' => 0,
                'max' => 30,
                'hint' => 'Days ahead a paper has to be due before the digest mentions it.',
            ],
        ];
    }

    /**
     * Every stored override, keyed by config key.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                if (! Schema::hasTable('settings')) {
                    return [];
                }

                return Setting::query()->pluck('value', 'key')->all();
            });
        } catch (Throwable) {
            // Boot must survive a database that is not there yet: `migrate` on
            // a fresh install runs through this provider before the table it
            // reads exists, and a settings lookup is never worth a white
            // screen. Fall back to the config files, which are always present.
            return [];
        }
    }

    /**
     * Lay the stored overrides over the config, once, at boot.
     *
     * Only keys named in schema() are applied, so a stray row cannot reach an
     * arbitrary config path — 'app.key' or 'database.connections' are not
     * settings and cannot be made into them by inserting a row.
     */
    public function applyToConfig(): void
    {
        $stored = $this->all();

        if ($stored === []) {
            return;
        }

        foreach (array_keys(self::schema()) as $key) {
            if (array_key_exists($key, $stored) && $stored[$key] !== null) {
                Config::set($key, $stored[$key]);
            }
        }
    }

    /**
     * Save a set of settings and write what changed to the audit trail.
     *
     * Only what actually differs is recorded: a settings form posts every field
     * every time, and a trail saying "changed" for nine untouched values buries
     * the one that moved.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function put(array $values, User $by, AuditLogger $audit): array
    {
        $allowed = self::schema();
        $current = $this->all();
        $changed = [];

        foreach ($values as $key => $value) {
            if (! isset($allowed[$key])) {
                continue;
            }

            $before = $current[$key] ?? Config::get($key);

            if ($before === $value) {
                continue;
            }

            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value, 'updated_by' => $by->getKey()],
            );

            $changed[$key] = ['before' => $before, 'after' => $value];
        }

        if ($changed === []) {
            return [];
        }

        Cache::forget(self::CACHE_KEY);

        // Applied immediately as well as cleared, so the screen that saved
        // them re-renders showing what it just saved rather than what the
        // config file says.
        $this->applyToConfig();

        $audit->log(
            event: 'settings.updated',
            properties: $changed,
            description: 'Changed system settings: '.implode(', ', array_keys($changed)).'.',
            actor: $by,
        );

        return $changed;
    }

    /** Who last touched each setting, for the screen to show. */
    public function lastEditors(): array
    {
        try {
            if (! Schema::hasTable('settings')) {
                return [];
            }

            return Setting::query()->with('editor')->get()->keyBy('key')->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
