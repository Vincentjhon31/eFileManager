<?php

namespace App\Livewire\Settings;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Who you are, as the rest of the hall sees you.
 *
 * A deliberately short list of editable fields. Name, position and contact
 * number are how a colleague finds the right person for a document, and the
 * employee is the authority on all three.
 *
 * Everything else on this screen is read-only on purpose. Office, role and
 * employee number decide what this account may reach and are an administrator's
 * to set; an account that could move itself between offices would defeat the
 * whole visibility model. Email is the sign-in identifier and the address a
 * password reset would go to, so it is changed by an administrator who can
 * confirm the person asking is the person who owns it.
 */
class Profile extends Component
{
    public string $name = '';

    public string $position = '';

    public string $phone = '';

    public function mount(): void
    {
        $user = $this->user();

        $this->name = $user->name;
        $this->position = $user->position ?? '';
        $this->phone = $user->phone ?? '';
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'string', 'max:255'],
            // Loose on purpose: Philippine numbers are written +63 917…,
            // 0917…, and with spaces, dashes or brackets, and none of those is
            // wrong. Rejecting a real number to enforce a house style would
            // cost more than it saves.
            'phone' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function save(AuditLogger $audit): void
    {
        $data = $this->validate();
        $user = $this->user();

        $before = $user->only(array_keys($data));

        $user->update([
            'name' => $data['name'],
            'position' => $data['position'] ?: null,
            'phone' => $data['phone'] ?: null,
        ]);

        // Their own name and position appear on routing slips and in the
        // document trail, so a change to them is worth recording — not because
        // it is suspicious, but because a slip signed "J. Cruz, Clerk III" and
        // a profile now reading something else should be reconcilable.
        $audit->log(
            event: 'user.profile_updated',
            subject: $user,
            properties: ['before' => $before, 'after' => $data],
            description: 'Updated their own profile.',
        );

        session()->flash('status', 'Profile saved.');
    }

    private function user(): User
    {
        return Auth::user();
    }

    public function render()
    {
        return view('livewire.settings.profile', [
            'user' => $this->user()->loadMissing(['department', 'roles']),
        ])->layout('components.layouts.app', ['title' => 'Profile']);
    }
}
