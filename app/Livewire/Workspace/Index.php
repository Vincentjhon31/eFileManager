<?php

namespace App\Livewire\Workspace;

use App\Enums\WorkspaceAppScope;
use App\Models\File;
use App\Models\WorkspaceApp;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The office landing page: what this office keeps, and what it runs.
 *
 * Files and apps stay two different tables underneath — an app is a link,
 * a file is bytes on a disk with versions and a trash — but they answer to
 * the same search box and sit on the same page, because "where do I find
 * X" should not require already knowing which kind of thing X is. Full file
 * management still happens in the Drive; this page previews it and points
 * there, rather than reimplementing it.
 */
class Index extends Component
{
    /** home | apps */
    #[Url(as: 'tab', except: 'home')]
    public string $tab = 'home';

    /** all | office | shared */
    #[Url(as: 'scope', except: 'all')]
    public string $appFilter = 'all';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', WorkspaceApp::class);
    }

    public function switchTab(string $tab): void
    {
        $this->tab = $tab;
        $this->search = '';
    }

    public function filterApps(string $filter): void
    {
        $this->appFilter = $filter;
    }

    public function updatedSearch(): void
    {
        //
    }

    public function render()
    {
        $user = Auth::user();
        $searching = $this->search !== '';

        return view('livewire.workspace.index', [
            'searching' => $searching,
            'matchedApps' => $searching ? $this->searchApps($user) : collect(),
            'matchedFiles' => $searching ? $this->searchFiles($user) : collect(),
            'homeApps' => (! $searching && $this->tab === 'home')
                ? WorkspaceApp::query()->visibleTo($user)->with('department')
                    ->orderBy('sort_order')->orderBy('name')->limit(4)->get()
                : collect(),
            'recentFiles' => (! $searching && $this->tab === 'home')
                ? File::query()->visibleTo($user)->current()->latest('updated_at')->limit(5)->get()
                : collect(),
            'catalogApps' => (! $searching && $this->tab === 'apps')
                ? $this->filteredCatalog($user)
                : collect(),
        ])->layout('components.layouts.app', ['title' => 'Workspace']);
    }

    private function filteredCatalog($user)
    {
        $query = WorkspaceApp::query()->visibleTo($user)->with('department');

        if ($this->appFilter === 'office') {
            $query->where('scope', WorkspaceAppScope::Department->value)
                ->where('department_id', $user->department_id);
        } elseif ($this->appFilter === 'shared') {
            $query->orgWide();
        }

        return $query->orderBy('sort_order')->orderBy('name')->get();
    }

    private function searchApps($user)
    {
        $term = '%'.$this->search.'%';

        return WorkspaceApp::query()->visibleTo($user)->with('department')
            ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('description', 'like', $term))
            ->orderBy('name')->limit(6)->get();
    }

    private function searchFiles($user)
    {
        $term = '%'.$this->search.'%';

        return File::query()->visibleTo($user)->current()->with('folder')
            ->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('original_name', 'like', $term))
            ->orderBy('name')->limit(6)->get();
    }
}
