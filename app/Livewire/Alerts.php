<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * In-app alerts.
 *
 * Deliberately separate from the audit trail and a document's timeline. Those
 * are records of what happened and cannot be altered by anybody. This is a
 * message queue for one person, which they are meant to read and clear — and
 * clearing it changes nothing about the record.
 */
class Alerts extends Component
{
    use WithPagination;

    public function markAsRead(string $id): void
    {
        Auth::user()->notifications()->whereKey($id)->first()?->markAsRead();
    }

    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();

        session()->flash('status', 'All alerts marked as read.');
    }

    public function render()
    {
        return view('livewire.alerts', [
            'alerts' => Auth::user()->notifications()->paginate(20),
            'unread' => Auth::user()->unreadNotifications()->count(),
        ])->layout('components.layouts.app', ['title' => 'Alerts']);
    }
}
