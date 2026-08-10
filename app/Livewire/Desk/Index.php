<?php

namespace App\Livewire\Desk;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Support\DeskCounts;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * My Desk — the screen an employee lives on.
 *
 * Four questions, in the order they get asked in a records office:
 *
 *   Incoming  what has been sent to us that we have not signed for
 *   On desk   what we are holding and have not dealt with
 *   Awaiting  what we sent that nobody has signed for — the chase list
 *   Overdue   what is past its deadline, wherever it is
 *
 * The chase list is the one that does not exist on paper, and the one that
 * answers the question this whole system was built for: *nasaan na ang papel ko?*
 */
class Index extends Component
{
    use WithPagination;

    #[Url(except: 'incoming')]
    public string $tab = 'incoming';

    #[Url(except: false)]
    public bool $mineOnly = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function updatedMineOnly(): void
    {
        $this->resetPage();
    }

    /** @return array{incoming: int, desk: int, awaiting: int, overdue: int} */
    public function counts(): array
    {
        return DeskCounts::for(Auth::user());
    }

    public function render()
    {
        $user = Auth::user();
        $officeId = $user->department_id;

        // Incoming and awaiting are lists of transmittals, not documents: what
        // matters is the handover, including who sent it and when.
        $legs = null;
        $documents = null;

        if (! $officeId) {
            $documents = Document::query()->whereRaw('1 = 0')->paginate(20);
        } elseif ($this->tab === 'incoming') {
            $legs = DocumentRoute::awaitingReceiptBy($officeId)
                ->with(['document.type', 'fromDepartment', 'toUser'])
                ->when($this->mineOnly, fn ($q) => $q->where('to_user_id', $user->id))
                ->orderBy('sent_at')
                ->paginate(20);
        } elseif ($this->tab === 'awaiting') {
            $legs = DocumentRoute::releasedBy($officeId)
                ->with(['document.type', 'toDepartment'])
                ->when($this->mineOnly, fn ($q) => $q->where('from_user_id', $user->id))
                ->orderBy('sent_at')
                ->paginate(20);
        } elseif ($this->tab === 'overdue') {
            $documents = Document::query()
                ->visibleTo($user)
                ->where('current_holder_department_id', $officeId)
                ->overdue()
                ->with(['type', 'originDepartment', 'currentHolderUser'])
                ->when($this->mineOnly, fn ($q) => $q->where('current_holder_user_id', $user->id))
                ->orderBy('due_at')
                ->paginate(20);
        } else {
            $documents = Document::query()
                ->visibleTo($user)
                ->onDeskOf($officeId)
                ->with(['type', 'originDepartment', 'currentHolderUser'])
                ->when($this->mineOnly, fn ($q) => $q->where('current_holder_user_id', $user->id))
                ->orderByRaw('due_at is null, due_at asc')
                ->paginate(20);
        }

        return view('livewire.desk.index', [
            'legs' => $legs,
            'documents' => $documents,
            'counts' => $this->counts(),
            'statuses' => DocumentStatus::all(),
        ])->layout('components.layouts.app', ['title' => 'My Desk']);
    }
}
