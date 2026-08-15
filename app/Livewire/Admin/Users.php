<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Enums\Role as RoleEnum;
use App\Livewire\Concerns\PaginatesByPreference;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Manage employee accounts.
 *
 * A department administrator sees and edits only their own office. Only a
 * system administrator may move a user between offices or grant the
 * superadmin role — otherwise an office administrator could quietly escalate
 * themselves or plant an account inside another office.
 *
 * Accounts are deactivated, never deleted: they are referenced by the
 * append-only routing and audit trails.
 */
class Users extends Component
{
    use PaginatesByPreference, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $departmentFilter = '';

    /**
     * '', 'requested', 'active' or 'inactive'.
     *
     * "Requested" is inactive *and* never given a role — which is exactly what
     * App\Http\Controllers\Auth\AccountRequestController writes and nothing else
     * does. A deactivated employee keeps the role they had, so the two states
     * stay tellable apart without a column for it.
     */
    #[Url(except: '')]
    public string $status = '';

    public ?int $editingId = null;

    public string $employee_no = '';

    public string $name = '';

    public string $email = '';

    public string $position = '';

    public ?int $department_id = null;

    public string $role = '';

    public ?string $generatedPassword = null;

    public function mount(): void
    {
        // A department administrator is pinned to their own office.
        if (! $this->canManageAllUsers()) {
            $this->departmentFilter = (string) Auth::user()->department_id;
        }
    }

    public function canManageAllUsers(): bool
    {
        return Auth::user()->can(Permission::UsersManageAll->value);
    }

    public function rules(): array
    {
        return [
            'employee_no' => [
                'nullable', 'string', 'max:32',
                Rule::unique('users', 'employee_no')->ignore($this->editingId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->editingId),
            ],
            'position' => ['nullable', 'string', 'max:255'],
            'department_id' => ['required', 'exists:departments,id'],
            'role' => ['required', Rule::in($this->assignableRoles())],
        ];
    }

    /**
     * A department administrator may not grant superadmin, and may not create
     * another department administrator outside their own office.
     *
     * @return array<int, string>
     */
    public function assignableRoles(): array
    {
        $roles = collect(RoleEnum::all());

        if (! $this->canManageAllUsers()) {
            $roles = $roles->reject(fn (RoleEnum $r) => $r === RoleEnum::SuperAdmin);
        }

        return $roles->map(fn (RoleEnum $r) => $r->value)->all();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->editingId = 0;
        $this->role = RoleEnum::Staff->value;

        // Pre-select the office a department administrator is confined to.
        $this->department_id = $this->canManageAllUsers()
            ? null
            : Auth::user()->department_id;
    }

    public function edit(int $id): void
    {
        $user = $this->scopedQuery()->findOrFail($id);

        $this->editingId = $user->id;
        $this->employee_no = $user->employee_no ?? '';
        $this->name = $user->name;
        $this->email = $user->email;
        $this->position = $user->position ?? '';
        $this->department_id = $user->department_id;
        $this->role = $user->roles->first()?->name ?? RoleEnum::Staff->value;

        $this->generatedPassword = null;
        $this->resetValidation();
    }

    public function save(AuditLogger $audit): void
    {
        $data = $this->validate();
        $role = $data['role'];
        unset($data['role']);

        $this->assertMayTouchDepartment((int) $data['department_id']);

        if ($this->editingId) {
            $user = $this->scopedQuery()->findOrFail($this->editingId);
            $before = $user->only(array_keys($data));

            $user->update($data);
            $user->syncRoles([$role]);

            $audit->log(
                event: 'user.updated',
                subject: $user,
                properties: ['before' => $before, 'after' => $data, 'role' => $role],
                description: "Updated account for {$user->name}.",
            );

            session()->flash('status', "Account for {$user->name} updated.");
            $this->resetForm();

            return;
        }

        // Shown once, on screen, for the administrator to hand over. Never
        // emailed and never stored in plain text.
        $password = Str::password(14);

        $user = User::create($data + [
            'password' => $password,
            'is_active' => true,
        ]);

        $user->syncRoles([$role]);

        $audit->log(
            event: 'user.created',
            subject: $user,
            properties: ['role' => $role, 'department_id' => $data['department_id']],
            description: "Created account for {$user->name}.",
        );

        $this->generatedPassword = $password;
        $this->editingId = null;
        session()->flash('status', "Account created for {$user->name}.");
    }

    public function toggleActive(int $id, AuditLogger $audit): void
    {
        $user = $this->scopedQuery()->findOrFail($id);

        if ($user->is($this->currentUser())) {
            $this->addError('active', 'You cannot deactivate your own account.');

            return;
        }

        $user->update(['is_active' => ! $user->is_active]);

        $audit->log(
            event: $user->is_active ? 'user.activated' : 'user.deactivated',
            subject: $user,
            description: ($user->is_active ? 'Reactivated' : 'Deactivated')." the account for {$user->name}.",
        );

        session()->flash('status', $user->is_active
            ? "{$user->name} can sign in again."
            : "{$user->name} has been deactivated and signed out.");
    }

    public function resetPassword(int $id, AuditLogger $audit): void
    {
        $user = $this->scopedQuery()->findOrFail($id);

        $password = Str::password(14);
        $user->update(['password' => $password]);

        // The password itself is deliberately not written to the audit trail.
        $audit->log(
            event: 'user.password_reset',
            subject: $user,
            description: "Reset the password for {$user->name}.",
        );

        $this->editingId = null;
        $this->generatedPassword = $password;
        session()->flash('status', "New password generated for {$user->name}.");
    }

    public function resetForm(): void
    {
        $this->reset([
            'editingId', 'employee_no', 'name', 'email',
            'position', 'department_id', 'role', 'generatedPassword',
        ]);
        $this->resetValidation();
    }

    private function currentUser(): User
    {
        return Auth::user();
    }

    /** Department administrators only ever see and act on their own office. */
    private function scopedQuery()
    {
        return User::query()->when(
            ! $this->canManageAllUsers(),
            fn ($q) => $q->where('department_id', $this->currentUser()->department_id),
        );
    }

    private function assertMayTouchDepartment(int $departmentId): void
    {
        if ($this->canManageAllUsers()) {
            return;
        }

        abort_unless(
            $departmentId === $this->currentUser()->department_id,
            403,
            'You may only manage accounts within your own office.',
        );
    }

    public function render()
    {
        $users = $this->scopedQuery()
            ->with(['department', 'roles'])
            ->when($this->departmentFilter !== '', fn ($q) => $q->where('department_id', $this->departmentFilter))
            ->when($this->status === 'requested', fn ($q) => $q->where('is_active', false)->whereDoesntHave('roles'))
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'inactive', fn ($q) => $q->where('is_active', false)->whereHas('roles'))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($sub) => $sub->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('employee_no', 'like', $term));
            })
            // Somebody waiting to be set up is the reason this screen is open,
            // so they come first whatever else is being looked at.
            ->orderByRaw('CASE WHEN is_active = 0 THEN 0 ELSE 1 END')
            ->orderBy('name')
            ->paginate($this->perPage());

        return view('livewire.admin.users', [
            'users' => $users,
            'departments' => Department::internal()->orderBy('sort_order')->get(),
            'roles' => RoleEnum::all(),

            // Counted through the same scope the list uses, so a department
            // administrator's queue is their own office's and nobody else's.
            'requested' => $this->scopedQuery()
                ->where('is_active', false)
                ->whereDoesntHave('roles')
                ->count(),
        ])->layout('components.layouts.app', ['title' => 'Users']);
    }
}
