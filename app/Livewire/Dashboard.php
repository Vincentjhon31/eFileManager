<?php

namespace App\Livewire;

use App\Models\Document;
use App\Support\DeskCounts;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The landing screen: what needs attention, and what has moved recently.
 *
 * Deliberately thin. Everything here is a shortcut into My Desk or a document —
 * a dashboard that becomes a place to work is a second interface to maintain.
 */
class Dashboard extends Component
{
    public function render()
    {
        $user = Auth::user();

        return view('livewire.dashboard', [
            'counts' => DeskCounts::for($user),
            'recent' => Document::query()
                ->visibleTo($user)
                ->with(['type', 'currentHolderDepartment'])
                ->orderByDesc('updated_at')
                ->limit(8)
                ->get(),
        ])->layout('components.layouts.app', ['title' => 'Dashboard']);
    }
}
