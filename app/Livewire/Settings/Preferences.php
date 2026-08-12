<?php

namespace App\Livewire\Settings;

use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * How the system looks and behaves for one employee.
 *
 * Every preference here changes something real, which is the only reason any
 * of them is here. Date format reaches every screen through ph_date(); rows per
 * page reaches every listing; the drive settings decide what the drive opens
 * as; the landing page decides where signing in puts you. A switch that saved a
 * value and changed nothing would be worse than no switch.
 *
 * Nothing on this screen is a permission. A preference decides what a screen
 * looks like to the person who set it — what they may see is still settled by
 * policies and scopes, every time.
 */
class Preferences extends Component
{
    public string $landing = '';

    public int $rows_per_page = 25;

    public string $date_format = '';

    public string $time_format = '';

    public string $drive_view = '';

    public string $drive_sort = '';

    public string $drive_sort_dir = '';

    public function mount(): void
    {
        $prefs = $this->user()->preferences();

        $this->landing = $prefs->landing();
        $this->rows_per_page = $prefs->rowsPerPage();
        $this->date_format = $prefs->dateFormat();
        $this->time_format = $prefs->timeFormat();
        $this->drive_view = $prefs->driveView();
        $this->drive_sort = $prefs->driveSort();
        $this->drive_sort_dir = $prefs->driveSortDirection();
    }

    public function rules(): array
    {
        return [
            'landing' => ['required', Rule::in(array_keys(UserPreferences::LANDING))],
            'rows_per_page' => ['required', 'integer', Rule::in(UserPreferences::ROWS_PER_PAGE)],
            'date_format' => ['required', Rule::in(array_keys(UserPreferences::DATE_FORMATS))],
            'time_format' => ['required', Rule::in(array_keys(UserPreferences::TIME_FORMATS))],
            'drive_view' => ['required', Rule::in(array_keys(UserPreferences::DRIVE_VIEWS))],
            'drive_sort' => ['required', Rule::in(array_keys(UserPreferences::DRIVE_SORTS))],
            'drive_sort_dir' => ['required', Rule::in(['asc', 'desc'])],
        ];
    }

    public function save(): void
    {
        $data = $this->validate();
        $user = $this->user();

        // Merged over what is already stored rather than replacing it: the
        // notifications screen writes into the same bag, and a save here must
        // not quietly reset the digest choices made there.
        $user->update([
            'preferences' => UserPreferences::clean(
                array_merge($user->preferences ?? [], $data),
            ),
        ]);

        // Not audited. These are display choices about one person's own screen
        // — nothing another office could be affected by, and nothing anybody
        // would ever need to reconstruct. Filling the trail with them would
        // bury the entries that matter.
        session()->flash('status', 'Preferences saved.');
    }

    public function resetToDefaults(): void
    {
        $user = $this->user();
        $defaults = UserPreferences::defaults();

        $user->update([
            'preferences' => UserPreferences::clean(
                array_merge($user->preferences ?? [], collect($defaults)->except([
                    // Left alone: they belong to the notifications screen.
                    'digest_email', 'digest_office_summary',
                ])->all()),
            ),
        ]);

        $this->mount();

        session()->flash('status', 'Preferences put back to their defaults.');
    }

    private function user(): User
    {
        return Auth::user();
    }

    public function render()
    {
        return view('livewire.settings.preferences', [
            'landings' => UserPreferences::LANDING,
            'rowOptions' => UserPreferences::ROWS_PER_PAGE,
            'dateFormats' => UserPreferences::DATE_FORMATS,
            'timeFormats' => UserPreferences::TIME_FORMATS,
            'driveViews' => UserPreferences::DRIVE_VIEWS,
            'driveSorts' => UserPreferences::DRIVE_SORTS,

            // Rendered with the format currently chosen in the form, not the
            // one saved, so the example moves as the reader picks.
            'sample' => now()->timezone(ph_tz())->format($this->date_format.', '.$this->time_format),
        ])->layout('components.layouts.app', ['title' => 'Preferences']);
    }
}
