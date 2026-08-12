<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Enums\WorkspaceAppScope;
use App\Enums\WorkspaceAppStatus;
use App\Livewire\Concerns\PaginatesByPreference;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkspaceApp;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The catalog behind the workspace: what the LGU runs, and where.
 *
 * An app here is a link and a few facts about who may see it — no bytes, no
 * versions, nothing to download. That is why it can be curated from a plain
 * form while a file needs the whole drive.
 *
 * Two lines are drawn and both are drawn twice, in the policy and again here:
 * an office administrator lists what their own office runs, and only somebody
 * who can manage settings may publish to every office or to the public. The
 * second is the one that matters, because a public entry is a link the
 * municipality is putting its name to.
 *
 * Retiring, not deleting, is the ordinary way to remove an app. A link that
 * used to work is something staff will ask about, and a row that simply
 * vanished cannot answer them.
 */
class WorkspaceApps extends Component
{
    use PaginatesByPreference, WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $statusFilter = '';

    /** null = closed, 0 = adding, >0 = editing that app. */
    public ?int $editingId = null;

    public string $name = '';

    public string $url = '';

    public string $description = '';

    public string $icon_glyph = '';

    public string $status = '';

    public string $scope = '';

    public ?int $department_id = null;

    public int $sort_order = 0;

    public function mount(): void
    {
        $this->authorize('create', WorkspaceApp::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            /*
             * http and https only, and never a relative path.
             *
             * This URL is rendered as a link staff are invited to click from a
             * government system. Allowing any scheme would let a catalog entry
             * carry javascript: or data:, which is a stored cross-site
             * scripting hole wearing the municipality's badge.
             */
            'url' => ['required', 'url:http,https', 'max:2048'],
            'description' => ['nullable', 'string', 'max:500'],
            // One or two characters — it is a badge, not an icon set.
            'icon_glyph' => ['nullable', 'string', 'max:2'],
            'status' => ['required', Rule::enum(WorkspaceAppStatus::class)],
            'scope' => ['required', Rule::enum(WorkspaceAppScope::class)],
            'department_id' => [
                Rule::requiredIf(fn () => $this->scope === WorkspaceAppScope::Department->value),
                'nullable',
                'exists:departments,id',
            ],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'url' => 'web address',
            'icon_glyph' => 'badge',
            'department_id' => 'office',
            'sort_order' => 'order',
        ];
    }

    public function canPublishWidely(): bool
    {
        return Auth::user()->can(Permission::SettingsManage->value);
    }

    /** @return array<int, WorkspaceAppScope> */
    public function availableScopes(): array
    {
        return $this->canPublishWidely()
            ? WorkspaceAppScope::all()
            : [WorkspaceAppScope::Department];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->resetForm();

        $this->editingId = 0;
        $this->status = WorkspaceAppStatus::Pilot->value;
        $this->scope = WorkspaceAppScope::Department->value;
        $this->department_id = $this->user()->department_id;
    }

    public function edit(int $id): void
    {
        $app = WorkspaceApp::findOrFail($id);
        $this->authorize('update', $app);

        $this->editingId = $app->id;
        $this->name = $app->name;
        $this->url = $app->url;
        $this->description = $app->description ?? '';
        $this->icon_glyph = $app->icon_glyph ?? '';
        $this->status = $app->status->value;
        $this->scope = $app->scope->value;
        $this->department_id = $app->department_id;
        $this->sort_order = $app->sort_order;

        $this->resetValidation();
    }

    public function save(AuditLogger $audit): void
    {
        $data = $this->validate();

        $scope = WorkspaceAppScope::from($data['scope']);

        // Checked here and not only in the form: the scope arrives from the
        // browser, and an office administrator posting 'public' by hand must
        // be refused rather than obeyed.
        abort_unless(
            Auth::user()->can('publishAt', [WorkspaceApp::class, $scope]),
            403,
            'Publishing beyond your own office needs the settings permission.',
        );

        // An office-scoped app always belongs to an office, and for anyone who
        // cannot publish widely that office is their own. Anything wider is
        // attributed to the office that runs it, or to none.
        $data['department_id'] = $scope === WorkspaceAppScope::Department
            ? ($this->canPublishWidely() ? $data['department_id'] : $this->user()->department_id)
            : $data['department_id'];

        if ($scope === WorkspaceAppScope::Department) {
            $this->assertMayTouchDepartment((int) $data['department_id']);
        }

        $data['description'] = $data['description'] ?: null;
        $data['icon_glyph'] = $data['icon_glyph'] ?: null;

        if ($this->editingId) {
            $app = WorkspaceApp::findOrFail($this->editingId);
            $this->authorize('update', $app);

            $before = $app->only(array_keys($data));
            $app->update($data);

            $audit->log(
                event: 'workspace_app.updated',
                subject: $app,
                properties: ['before' => $before, 'after' => $data],
                description: "Updated the workspace app “{$app->name}”.",
            );

            session()->flash('status', "“{$app->name}” updated.");
            $this->resetForm();

            return;
        }

        $this->authorize('create', WorkspaceApp::class);

        $app = WorkspaceApp::create($data + [
            'slug' => $this->uniqueSlug($data['name']),
            'created_by' => $this->user()->getKey(),
        ]);

        $audit->log(
            event: 'workspace_app.created',
            subject: $app,
            properties: ['scope' => $app->scope->value, 'url' => $app->url],
            description: "Published “{$app->name}” to the workspace catalog.",
        );

        session()->flash('status', "“{$app->name}” added to the workspace.");
        $this->resetForm();
    }

    /**
     * Retire an app, or bring a retired one back.
     *
     * The ordinary way to take an app off the workspace. Retired rows stay
     * visible on this screen and nowhere else, so an administrator can still
     * answer "what happened to that link".
     */
    public function toggleRetired(int $id, AuditLogger $audit): void
    {
        $app = WorkspaceApp::findOrFail($id);
        $this->authorize('update', $app);

        $retiring = $app->status !== WorkspaceAppStatus::Retired;

        $app->update([
            'status' => $retiring ? WorkspaceAppStatus::Retired : WorkspaceAppStatus::Pilot,
        ]);

        $audit->log(
            event: $retiring ? 'workspace_app.retired' : 'workspace_app.restored',
            subject: $app,
            description: ($retiring ? 'Retired' : 'Brought back')." the workspace app “{$app->name}”.",
        );

        session()->flash('status', $retiring
            ? "“{$app->name}” retired. It no longer appears on anybody's workspace."
            : "“{$app->name}” is back, as a pilot.");
    }

    public function delete(int $id, AuditLogger $audit): void
    {
        $app = WorkspaceApp::findOrFail($id);
        $this->authorize('delete', $app);

        $name = $app->name;
        $app->delete();

        $audit->log(
            event: 'workspace_app.deleted',
            properties: ['name' => $name],
            description: "Removed the workspace app “{$name}” entirely.",
        );

        session()->flash('status', "“{$name}” removed.");
    }

    public function resetForm(): void
    {
        $this->reset([
            'editingId', 'name', 'url', 'description',
            'icon_glyph', 'status', 'scope', 'department_id', 'sort_order',
        ]);
        $this->resetValidation();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'app';
        $slug = $base;
        $n = 1;

        while (WorkspaceApp::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }

    private function assertMayTouchDepartment(int $departmentId): void
    {
        if ($this->canPublishWidely()) {
            return;
        }

        abort_unless(
            $departmentId === $this->user()->department_id,
            403,
            'You may only publish apps for your own office.',
        );
    }

    private function user(): User
    {
        return Auth::user();
    }

    public function render()
    {
        $user = $this->user();

        /*
         * Retired apps are listed here and nowhere else, which is why this
         * query does not go through visibleTo(): that scope exists to keep
         * retired and other offices' entries off the workspace, and this is
         * the screen where an administrator has to see exactly those.
         */
        $apps = WorkspaceApp::query()
            ->with(['department', 'creator'])
            ->when(! $this->canPublishWidely(), fn ($q) => $q
                ->where('scope', WorkspaceAppScope::Department->value)
                ->where('department_id', $user->department_id))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($sub) => $sub->where('name', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('url', 'like', $term));
            })
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate($this->perPage());

        return view('livewire.admin.workspace-apps', [
            'apps' => $apps,
            'statuses' => WorkspaceAppStatus::all(),
            'scopes' => $this->availableScopes(),
            'departments' => Department::internal()->orderBy('sort_order')->get(),
            'canPublishWidely' => $this->canPublishWidely(),
        ])->layout('components.layouts.app', ['title' => 'Workspace apps']);
    }
}
