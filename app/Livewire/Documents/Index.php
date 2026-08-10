<?php

namespace App\Livewire\Documents;

use App\Enums\DocumentStatus;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Search and browse documents.
 *
 * Every query starts from Document::visibleTo(), so no combination of filters
 * on this screen can surface a document the user is not entitled to. The
 * filters narrow that set; they never widen it.
 */
class Index extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $statusFilter = '';

    #[Url(except: '')]
    public string $typeFilter = '';

    #[Url(except: '')]
    public string $officeFilter = '';

    #[Url(except: false)]
    public bool $overdueOnly = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'typeFilter', 'officeFilter', 'overdueOnly']);
        $this->resetPage();
    }

    public function render()
    {
        $documents = Document::query()
            ->visibleTo(Auth::user())
            ->with(['type', 'originDepartment', 'currentHolderDepartment', 'currentHolderUser'])
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->typeFilter !== '', fn ($q) => $q->where('document_type_id', $this->typeFilter))
            ->when($this->officeFilter !== '', fn ($q) => $q->where('current_holder_department_id', $this->officeFilter))
            ->when($this->overdueOnly, fn ($q) => $q->overdue())
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                // Tracking number and reference number first: staff arrive here
                // holding a piece of paper with one of those written on it.
                $q->where(fn ($sub) => $sub->where('tracking_no', 'like', $term)
                    ->orWhere('reference_no', 'like', $term)
                    ->orWhere('subject', 'like', $term)
                    ->orWhere('origin_external_name', 'like', $term));
            })
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.documents.index', [
            'documents' => $documents,
            'statuses' => DocumentStatus::all(),
            'types' => DocumentType::active()->inMenuOrder()->get(),
            'offices' => Department::internal()->orderBy('sort_order')->get(),
        ])->layout('components.layouts.app', ['title' => 'Documents']);
    }
}
