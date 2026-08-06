<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Models\Department;
use App\Services\AuditLogger;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Manage the offices documents can move between.
 *
 * Onboarding an office is the single most consequential action here: it changes
 * that office from one whose documents are handed over on paper and logged
 * manually, into one whose staff sign in and receive digitally. It is a flag,
 * not a migration — nothing about existing documents changes.
 */
class Departments extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'internal')]
    public string $filter = 'internal';

    public ?int $editingId = null;

    public string $code = '';

    public string $name = '';

    public string $short_name = '';

    public bool $is_onboarded = false;

    public bool $is_external = false;

    public function rules(): array
    {
        return [
            // The code is embedded in every tracking number this office issues,
            // so it must stay unique and is treated as immutable once documents
            // exist (enforced in save()).
            'code' => [
                'required', 'string', 'max:12', 'regex:/^[A-Z0-9\-]+$/',
                Rule::unique('departments', 'code')->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:60'],
            'is_onboarded' => ['boolean'],
            'is_external' => ['boolean'],
        ];
    }

    protected function messages(): array
    {
        return [
            'code.regex' => 'Use capital letters, numbers and hyphens only, e.g. MO or EXT-DILG.',
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    /** External parties never have staff, so the two flags are mutually exclusive. */
    public function updatedIsExternal(bool $value): void
    {
        if ($value) {
            $this->is_onboarded = false;
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->editingId = 0; // 0 means "new", distinct from null meaning "closed"
    }

    public function edit(int $id): void
    {
        $department = Department::findOrFail($id);

        $this->editingId = $department->id;
        $this->code = $department->code;
        $this->name = $department->name;
        $this->short_name = $department->short_name ?? '';
        $this->is_onboarded = $department->is_onboarded;
        $this->is_external = $department->is_external;

        $this->resetValidation();
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize(Permission::DepartmentsManage->value);

        $data = $this->validate();

        if ($this->editingId) {
            $department = Department::findOrFail($this->editingId);
            $before = $department->only(array_keys($data));

            $department->update($data);

            $audit->log(
                event: 'department.updated',
                subject: $department,
                properties: ['before' => $before, 'after' => $data],
                description: "Updated office {$department->code}.",
            );

            session()->flash('status', "Office {$department->code} updated.");
        } else {
            $department = Department::create($data + ['sort_order' => (Department::max('sort_order') ?? 0) + 10]);

            $audit->log(
                event: 'department.created',
                subject: $department,
                properties: $data,
                description: "Created office {$department->code}.",
            );

            session()->flash('status', "Office {$department->code} created.");
        }

        $this->resetForm();
    }

    /**
     * Onboarding is surfaced as its own action rather than buried in the edit
     * form, because it is the moment an office starts receiving digitally and
     * deserves its own audit entry.
     */
    public function toggleOnboarded(int $id, AuditLogger $audit): void
    {
        $this->authorize(Permission::DepartmentsManage->value);

        $department = Department::findOrFail($id);

        if ($department->is_external) {
            $this->addError('onboard', 'External parties cannot be onboarded — they never have accounts in this system.');

            return;
        }

        $department->update(['is_onboarded' => ! $department->is_onboarded]);

        $audit->log(
            event: $department->is_onboarded ? 'department.onboarded' : 'department.offboarded',
            subject: $department,
            description: $department->is_onboarded
                ? "{$department->code} now receives documents digitally."
                : "{$department->code} reverted to manual receipt.",
        );

        session()->flash('status', $department->is_onboarded
            ? "{$department->code} is now onboarded."
            : "{$department->code} is no longer onboarded.");
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'code', 'name', 'short_name', 'is_onboarded', 'is_external']);
        $this->resetValidation();
    }

    public function render()
    {
        $departments = Department::query()
            ->when($this->filter === 'internal', fn ($q) => $q->where('is_external', false))
            ->when($this->filter === 'external', fn ($q) => $q->where('is_external', true))
            ->when($this->filter === 'onboarded', fn ($q) => $q->where('is_onboarded', true))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($sub) => $sub->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhere('short_name', 'like', $term));
            })
            ->withCount('users')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(20);

        return view('livewire.admin.departments', [
            'departments' => $departments,
        ])->layout('components.layouts.app', ['title' => 'Offices']);
    }
}
