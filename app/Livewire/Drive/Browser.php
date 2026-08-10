<?php

namespace App\Livewire\Drive;

use App\Enums\FolderVisibility;
use App\Exceptions\DriveException;
use App\Models\File;
use App\Models\Folder;
use App\Services\FileStorageService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * The drive.
 *
 * Three views, because they answer three different questions: what does my
 * office keep, what have other offices shared, and what did we throw away.
 *
 * Every listing starts from a scope — Folder::visibleTo or File::visibleTo —
 * so no breadcrumb, search term or folder id in the address bar can reach past
 * what the user is entitled to. What the screen offers is decided by policies;
 * what the queries return is decided by the scopes. Neither alone is trusted.
 */
class Browser extends Component
{
    use WithFileUploads, WithPagination;

    /** office | shared | trash */
    #[Url(except: 'office')]
    public string $view = 'office';

    #[Url(except: null)]
    public ?int $folderId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** new-folder | rename-folder | visibility | rename-file | move | version */
    public string $panel = '';

    public ?int $targetId = null;

    public string $formName = '';

    public string $formVisibility = 'department';

    public ?int $moveToId = null;

    public $upload;

    public $versionUpload;

    public function mount(FileStorageService $drive): void
    {
        $this->authorize('viewAny', Folder::class);

        // Make sure the office has somewhere to put things before it looks.
        if ($office = Auth::user()->department) {
            $drive->documentScansFolderFor($office, Auth::user());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    public function openFolder(?int $id): void
    {
        $this->folderId = $id;
        $this->closePanel();
        $this->resetPage();
    }

    public function switchView(string $view): void
    {
        $this->view = $view;
        $this->folderId = null;
        $this->closePanel();
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function currentFolder(): ?Folder
    {
        if (! $this->folderId) {
            return null;
        }

        return Folder::query()->visibleTo(Auth::user())->find($this->folderId);
    }

    /*
    |--------------------------------------------------------------------------
    | Panels
    |--------------------------------------------------------------------------
    */

    public function open(string $panel, ?int $targetId = null): void
    {
        $this->resetValidation();
        $this->panel = $panel;
        $this->targetId = $targetId;

        if ($panel === 'rename-folder' && $folder = Folder::find($targetId)) {
            $this->formName = $folder->name;
        }

        if ($panel === 'visibility' && $folder = Folder::find($targetId)) {
            $this->formVisibility = $folder->visibility->value;
        }

        if ($panel === 'rename-file' && $file = File::find($targetId)) {
            $this->formName = $file->name;
        }

        if ($panel === 'new-folder') {
            $this->formName = '';
            $this->formVisibility = $this->currentFolder()?->visibility->value
                ?? FolderVisibility::Department->value;
        }
    }

    public function closePanel(): void
    {
        $this->reset(['panel', 'targetId', 'formName', 'formVisibility', 'moveToId', 'versionUpload']);
        $this->resetValidation();
    }

    /*
    |--------------------------------------------------------------------------
    | Folders
    |--------------------------------------------------------------------------
    */

    public function createFolder(FileStorageService $drive): void
    {
        $this->authorize('create', Folder::class);

        $data = $this->validate([
            'formName' => ['required', 'string', 'max:200'],
            'formVisibility' => ['required', Rule::enum(FolderVisibility::class)],
        ], attributes: ['formName' => 'folder name']);

        $parent = $this->currentFolder();

        if ($parent) {
            $this->authorize('store', $parent);
        }

        $this->attempt(fn () => $drive->createFolder(
            office: Auth::user()->department,
            parent: $parent,
            name: $data['formName'],
            visibility: FolderVisibility::from($data['formVisibility']),
            by: Auth::user(),
        ), 'Folder created.');
    }

    public function renameFolder(FileStorageService $drive): void
    {
        $folder = $this->folderTarget();
        $this->authorize('update', $folder);

        $data = $this->validate(['formName' => ['required', 'string', 'max:200']],
            attributes: ['formName' => 'folder name']);

        $this->attempt(fn () => $drive->renameFolder($folder, $data['formName'], Auth::user()), 'Folder renamed.');
    }

    public function changeVisibility(FileStorageService $drive): void
    {
        $folder = $this->folderTarget();
        $this->authorize('update', $folder);

        $data = $this->validate(['formVisibility' => ['required', Rule::enum(FolderVisibility::class)]]);

        $this->attempt(fn () => $drive->setFolderVisibility(
            $folder,
            FolderVisibility::from($data['formVisibility']),
            Auth::user(),
        ), 'Who can see this folder has been changed.');
    }

    public function deleteFolder(int $id, FileStorageService $drive): void
    {
        $folder = Folder::findOrFail($id);
        $this->authorize('delete', $folder);

        $this->attempt(function () use ($drive, $folder) {
            $drive->deleteFolder($folder, Auth::user());

            if ($this->folderId === $folder->getKey()) {
                $this->folderId = $folder->parent_id;
            }
        }, 'Folder deleted.');
    }

    /*
    |--------------------------------------------------------------------------
    | Files
    |--------------------------------------------------------------------------
    */

    public function uploadFile(FileStorageService $drive): void
    {
        $folder = $this->currentFolder();

        if (! $folder) {
            $this->addError('upload', 'Open a folder first — files are not kept loose at the top level.');

            return;
        }

        $this->authorize('store', $folder);

        $this->validate(['upload' => $this->uploadRules()], attributes: ['upload' => 'file']);

        $this->attempt(function () use ($drive, $folder) {
            $drive->store($this->upload, $folder, Auth::user());
            $this->reset('upload');
        }, 'Uploaded.');
    }

    public function addVersion(FileStorageService $drive): void
    {
        $file = $this->fileTarget();
        $this->authorize('update', $file);

        $this->validate(['versionUpload' => $this->uploadRules()], attributes: ['versionUpload' => 'file']);

        $this->attempt(
            fn () => $drive->storeNewVersion($file, $this->versionUpload, Auth::user()),
            'New version saved. The previous one is still here.',
        );
    }

    public function renameFile(FileStorageService $drive): void
    {
        $file = $this->fileTarget();
        $this->authorize('update', $file);

        $data = $this->validate(['formName' => ['required', 'string', 'max:200']],
            attributes: ['formName' => 'file name']);

        $this->attempt(fn () => $drive->rename($file, $data['formName'], Auth::user()), 'Renamed.');
    }

    public function moveFile(FileStorageService $drive): void
    {
        $file = $this->fileTarget();
        $this->authorize('update', $file);

        $data = $this->validate(['moveToId' => ['required', 'exists:folders,id']],
            attributes: ['moveToId' => 'destination folder']);

        $target = Folder::findOrFail($data['moveToId']);
        $this->authorize('store', $target);

        $this->attempt(fn () => $drive->move($file, $target, Auth::user()), 'Moved.');
    }

    public function trashFile(int $id, FileStorageService $drive): void
    {
        $file = File::findOrFail($id);
        $this->authorize('delete', $file);

        $this->attempt(fn () => $drive->trash($file, Auth::user()), 'Moved to the trash.');
    }

    public function restoreFile(int $id, FileStorageService $drive): void
    {
        $file = File::withTrashed()->findOrFail($id);
        $this->authorize('restore', $file);

        $this->attempt(fn () => $drive->restore($file, Auth::user()), 'Restored.');
    }

    public function purgeFile(int $id, FileStorageService $drive): void
    {
        $file = File::withTrashed()->findOrFail($id);
        $this->authorize('forceDelete', $file);

        $this->attempt(fn () => $drive->purge($file, Auth::user()), 'Destroyed for good.');
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    /** @return array<int, mixed> */
    private function uploadRules(): array
    {
        return [
            'required', 'file',
            'max:'.((int) config('drive.max_upload_mb', 50) * 1024),
            // The service checks this again against the real upload. Here it is
            // so the user is told before waiting for fifty megabytes to arrive.
            'extensions:'.implode(',', config('drive.allowed_extensions', [])),
        ];
    }

    private function folderTarget(): Folder
    {
        return Folder::findOrFail($this->targetId);
    }

    private function fileTarget(): File
    {
        return File::findOrFail($this->targetId);
    }

    /** Run a drive act and put whatever it refuses in front of the user. */
    private function attempt(callable $act, string $success): void
    {
        try {
            $act();
        } catch (DriveException $e) {
            $this->addError('drive', $e->getMessage());

            return;
        }

        $this->closePanel();
        session()->flash('status', $success);
    }

    public function render()
    {
        $user = Auth::user();
        $current = $this->currentFolder();
        $searching = $this->search !== '';

        $folders = $this->foldersToList($current);
        $files = $this->filesToList($current, $searching);

        return view('livewire.drive.browser', [
            'current' => $current,
            'folders' => $folders,
            'files' => $files,
            'breadcrumbs' => $current?->breadcrumbs() ?? collect(),
            'levels' => FolderVisibility::all(),
            'destinations' => $user->department_id
                ? Folder::query()->writableBy($user)->orderBy('name')->get()
                : collect(),
            'canWriteHere' => $current !== null && $user->can('store', $current),
            'maxUploadMb' => (int) config('drive.max_upload_mb', 50),
        ])->layout('components.layouts.app', ['title' => 'Drive']);
    }

    private function foldersToList(?Folder $current)
    {
        $user = Auth::user();

        if ($this->view === 'trash' || $this->search !== '') {
            return collect();
        }

        if ($current) {
            return $current->children()->visibleTo($user)->withCount('files')->get();
        }

        if ($this->view === 'shared') {
            // The shallowest folders other offices have shared. Listing every
            // shared folder would show a nested tree flattened into a pile.
            return Folder::query()
                ->visibleTo($user)
                ->where('department_id', '!=', $user->department_id)
                ->whereIn('visibility', [FolderVisibility::Internal->value, FolderVisibility::Public->value])
                ->where(fn ($q) => $q->whereNull('parent_id')->orWhereHas(
                    'parent',
                    fn ($p) => $p->whereNotIn('visibility', [
                        FolderVisibility::Internal->value,
                        FolderVisibility::Public->value,
                    ])
                ))
                ->with('department')
                ->orderBy('name')
                ->withCount('files')
                ->get();
        }

        return Folder::query()
            ->visibleTo($user)
            ->where('department_id', $user->department_id)
            ->roots()
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->withCount('files')
            ->get();
    }

    private function filesToList(?Folder $current, bool $searching)
    {
        $user = Auth::user();

        $query = File::query()
            ->visibleTo($user)
            ->current()
            ->with(['folder', 'uploader']);

        if ($this->view === 'trash') {
            // Flat: everything this office has thrown away, wherever it lived.
            $query->onlyTrashed()->where('department_id', $user->department_id ?? 0);
        } elseif (! $searching) {
            // Nothing is kept loose above a folder, so the top level lists none.
            $current
                ? $query->where('folder_id', $current->getKey())
                : $query->whereRaw('1 = 0');
        }

        if ($searching) {
            // Narrows whichever set is in view, and reaches across folders.
            $term = '%'.$this->search.'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('original_name', 'like', $term));
        }

        return $query->orderByDesc('updated_at')->paginate(25);
    }
}
