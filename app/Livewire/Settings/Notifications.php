<?php

namespace App\Livewire\Settings;

use App\Enums\Permission;
use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * What the system is allowed to send you.
 *
 * A short list, and deliberately so: this system sends exactly one email — the
 * weekday morning digest — and keeps everything else as an in-app alert you
 * collect when you look. Offering switches for messages that do not exist would
 * be a promise the system does not keep.
 *
 * The one thing that cannot be turned off is the alerts list itself. Documents
 * arriving on your desk is the work, not a notification about the work, and an
 * employee who had silenced it would simply stop receiving papers.
 */
class Notifications extends Component
{
    public bool $digest_email = true;

    public bool $digest_office_summary = true;

    public function mount(): void
    {
        $prefs = $this->user()->preferences();

        $this->digest_email = $prefs->wantsDigestEmail();
        $this->digest_office_summary = $prefs->wantsOfficeSummary();
    }

    public function save(): void
    {
        $user = $this->user();

        // Merged, not replaced: the preferences screen writes into the same bag
        // and must not be reset by a save here.
        $user->update([
            'preferences' => UserPreferences::clean(array_merge($user->preferences ?? [], [
                'digest_email' => $this->digest_email,
                'digest_office_summary' => $this->digest_office_summary,
            ])),
        ]);

        session()->flash('status', 'Notification settings saved.');
    }

    private function user(): User
    {
        return Auth::user();
    }

    public function render()
    {
        $user = $this->user();

        return view('livewire.settings.notifications', [
            'user' => $user,

            // Only somebody who can receive documents gets an office-wide
            // section in the digest at all, so for everyone else the switch
            // would govern nothing.
            'canSeeOfficeSummary' => $user->can(Permission::DocumentsReceive->value),

            // If an administrator has stopped the digest system-wide, say so
            // plainly rather than leaving a switch that appears to work.
            'digestEnabledGlobally' => (bool) config('digest.enabled', true),
            'digestTime' => config('digest.time', '07:30'),
        ])->layout('components.layouts.app', ['title' => 'Notifications']);
    }
}
