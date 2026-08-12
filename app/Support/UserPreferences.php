<?php

namespace App\Support;

/**
 * How one employee has asked to be shown things.
 *
 * The database column is a JSON bag; this class is the only thing that knows
 * what may be in it. Every value read out is checked against the allowed set
 * and falls back to the default, so a bag written by an older version of the
 * app — or edited by hand — can never put an unknown column name into an
 * `order by`, an arbitrary string into a `date()` format, or a route name that
 * does not exist into a redirect.
 *
 * Nothing here is a permission. A preference decides what a screen looks like
 * to the person who set it; what they may see is still settled by policies and
 * scopes, every time.
 */
class UserPreferences
{
    /** Where signing in takes you. Keys are route names. */
    public const LANDING = [
        'dashboard' => 'Dashboard',
        'desk' => 'My Desk',
        'documents.index' => 'Documents',
        'drive' => 'Drive',
        'workspace' => 'Workspace',
    ];

    public const ROWS_PER_PAGE = [10, 25, 50, 100];

    /** Label => PHP date() format. Philippine offices write day-first. */
    public const DATE_FORMATS = [
        'd M Y' => '06 Aug 2026',
        'd/m/Y' => '06/08/2026',
        'j F Y' => '6 August 2026',
        'Y-m-d' => '2026-08-06',
    ];

    public const TIME_FORMATS = [
        'g:i A' => '8:30 AM',
        'H:i' => '08:30',
    ];

    /**
     * Light, dark, or whatever the machine is set to.
     *
     * 'system' is resolved in the browser before first paint rather than on
     * the server, because the server cannot know what the operating system is
     * set to — and resolving it after paint would show a white flash to
     * somebody who asked for dark.
     */
    public const THEMES = [
        'light' => 'Light',
        'dark' => 'Dark',
        'system' => 'Match my device',
    ];

    /** Scales every spacing utility at once through Tailwind's --spacing. */
    public const DENSITIES = [
        'comfortable' => 'Comfortable',
        'compact' => 'Compact',
    ];

    /** Scales the root font size; Tailwind's rem-based sizes follow it. */
    public const TEXT_SIZES = [
        'small' => 'Small',
        'normal' => 'Normal',
        'large' => 'Large',
    ];

    public const DRIVE_VIEWS = ['grid' => 'Grid', 'list' => 'List'];

    public const DRIVE_SORTS = [
        'name' => 'Name',
        'updated_at' => 'Last changed',
        'size' => 'Size',
    ];

    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values = []) {}

    /** @param array<string, mixed>|null $values */
    public static function fromArray(?array $values): self
    {
        return new self($values ?? []);
    }

    /**
     * Everything at its default. Also the canonical list of keys — anything
     * not named here is not a preference and is dropped on the way in.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'theme' => 'system',
            'density' => 'comfortable',
            'text_size' => 'normal',
            'landing' => 'dashboard',
            'rows_per_page' => 25,
            'date_format' => 'd M Y',
            'time_format' => 'g:i A',
            'drive_view' => 'grid',
            'drive_sort' => 'name',
            'drive_sort_dir' => 'asc',
            'digest_email' => true,
            'digest_office_summary' => true,
        ];
    }

    /**
     * Keep only known keys with allowed values. Used on the way in, so the
     * column never holds anything the getters would have to reject later.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function clean(array $values): array
    {
        $clean = new self($values);

        return [
            'theme' => $clean->theme(),
            'density' => $clean->density(),
            'text_size' => $clean->textSize(),
            'landing' => $clean->landing(),
            'rows_per_page' => $clean->rowsPerPage(),
            'date_format' => $clean->dateFormat(),
            'time_format' => $clean->timeFormat(),
            'drive_view' => $clean->driveView(),
            'drive_sort' => $clean->driveSort(),
            'drive_sort_dir' => $clean->driveSortDirection(),
            'digest_email' => $clean->wantsDigestEmail(),
            'digest_office_summary' => $clean->wantsOfficeSummary(),
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return self::clean($this->values);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading one preference
    |--------------------------------------------------------------------------
    */

    public function theme(): string
    {
        return $this->oneOf('theme', array_keys(self::THEMES));
    }

    public function density(): string
    {
        return $this->oneOf('density', array_keys(self::DENSITIES));
    }

    public function textSize(): string
    {
        return $this->oneOf('text_size', array_keys(self::TEXT_SIZES));
    }

    public function landing(): string
    {
        return $this->oneOf('landing', array_keys(self::LANDING));
    }

    public function rowsPerPage(): int
    {
        $value = (int) ($this->values['rows_per_page'] ?? 0);

        return in_array($value, self::ROWS_PER_PAGE, true)
            ? $value
            : self::defaults()['rows_per_page'];
    }

    public function dateFormat(): string
    {
        return $this->oneOf('date_format', array_keys(self::DATE_FORMATS));
    }

    public function timeFormat(): string
    {
        return $this->oneOf('time_format', array_keys(self::TIME_FORMATS));
    }

    /** Date and time together, as ph_datetime() renders a timestamp. */
    public function dateTimeFormat(): string
    {
        return $this->dateFormat().', '.$this->timeFormat();
    }

    public function driveView(): string
    {
        return $this->oneOf('drive_view', array_keys(self::DRIVE_VIEWS));
    }

    public function driveSort(): string
    {
        return $this->oneOf('drive_sort', array_keys(self::DRIVE_SORTS));
    }

    public function driveSortDirection(): string
    {
        return $this->oneOf('drive_sort_dir', ['asc', 'desc']);
    }

    public function wantsDigestEmail(): bool
    {
        return $this->boolean('digest_email');
    }

    /**
     * Whether the digest should carry the office-wide figures as well as this
     * person's own papers. Only ever offered to somebody who can receive
     * documents; for everyone else the digest has no office section to leave
     * out, so the answer is moot.
     */
    public function wantsOfficeSummary(): bool
    {
        return $this->boolean('digest_office_summary');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** @param array<int, string> $allowed */
    private function oneOf(string $key, array $allowed): string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) && in_array($value, $allowed, true)
            ? $value
            : self::defaults()[$key];
    }

    private function boolean(string $key): bool
    {
        return array_key_exists($key, $this->values)
            ? (bool) $this->values[$key]
            : self::defaults()[$key];
    }
}
