<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Department;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only view of the audit trail.
 *
 * There is intentionally no create, edit, delete or export-and-reimport action
 * on this screen, at any role. The trail is evidence; a screen that could
 * alter it would defeat its purpose.
 *
 * Users without the all-departments permission see only their own office's
 * entries, so an office administrator cannot read another office's activity.
 */
class AuditTrail extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $eventFilter = '';

    #[Url(except: '')]
    public string $departmentFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedEventFilter(): void
    {
        $this->resetPage();
    }

    public function canViewAllDepartments(): bool
    {
        return Auth::user()->can(Permission::AuditViewAllDepartments->value);
    }

    public function render()
    {
        $logs = AuditLog::query()
            ->with(['user', 'department'])
            // Scoping happens here, at the query, not in the view. A user
            // without the all-departments permission cannot reach another
            // office's entries by any parameter on this page.
            ->when(
                ! $this->canViewAllDepartments(),
                fn ($q) => $q->where('department_id', Auth::user()->department_id),
            )
            ->when($this->departmentFilter !== '' && $this->canViewAllDepartments(),
                fn ($q) => $q->where('department_id', $this->departmentFilter))
            ->when($this->eventFilter !== '', fn ($q) => $q->where('event', $this->eventFilter))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($sub) => $sub->where('description', 'like', $term)
                    ->orWhere('actor_name', 'like', $term)
                    ->orWhere('event', 'like', $term));
            })
            ->latestFirst()
            ->paginate(50);

        return view('livewire.admin.audit-trail', [
            'logs' => $logs,
            'events' => AuditLog::query()
                ->when(! $this->canViewAllDepartments(),
                    fn ($q) => $q->where('department_id', Auth::user()->department_id))
                ->distinct()
                ->orderBy('event')
                ->pluck('event'),
            'departments' => $this->canViewAllDepartments()
                ? Department::orderBy('sort_order')->get()
                : collect(),
        ])->layout('components.layouts.app', ['title' => 'Audit trail']);
    }
}
