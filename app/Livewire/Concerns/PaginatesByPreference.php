<?php

namespace App\Livewire\Concerns;

use App\Support\UserPreferences;
use Illuminate\Support\Facades\Auth;

/**
 * Page listings at the size the reader asked for.
 *
 * One preference, set once in Settings, rather than a per-screen control on
 * every listing: somebody who wants long pages wants them everywhere, and a
 * clerk on the counter machine wants short ones everywhere.
 *
 * Falls back to the default rather than to whatever each screen used to hard-
 * code, so every listing agrees for a user who has never opened Settings.
 */
trait PaginatesByPreference
{
    protected function perPage(): int
    {
        return Auth::user()?->preferences()->rowsPerPage()
            ?? UserPreferences::defaults()['rows_per_page'];
    }
}
