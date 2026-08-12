<?php

namespace App\Livewire\Drive;

use App\Enums\FolderVisibility;
use App\Enums\Permission;
use App\Exceptions\DriveException;
use App\Livewire\Concerns\PaginatesByPreference;
use App\Models\File;
use App\Models\Folder;
use App\Services\FileStorageService;
use Illuminate\Database\Eloquent\Collection as Models;
use Illuminate\Support\Collection;
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
    use PaginatesByPreference, WithFileUploads, WithPagination;

    /** office | shared | trash */
    #[Url(except: 'office')]
    public string $view = 'office';

    /** grid | list */
    #[Url(except: 'grid')]
    public string $displayMode = 'grid';

    /** name | updated_at | size */
    #[Url(except: 'name')]
    public string $sortBy = 'name';

    /** asc | desc */
    #[Url(except: 'asc')]
    public string $sortDir = 'asc';

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

    /**
     * What the open panel or the last bulk action acts on: keys of the form
     * "file:12" / "folder:3", as sent by the selection in the browser.
     *
     * Held only so the Move panel still knows its subject across the round trip
     * between opening and submitting. It is never trusted — resolveKeys()
     * re-reads every id through a visibleTo() scope and every action
     * re-authorises each model on its own.
     *
     * @var array<int, string>
     */
    public array $selection = [];

    /** The item whose details pane is open, as a key. */
    public ?string $detailsKey = null;

    public $upload;

    public $versionUpload;

    /** More than a page of items can be selected; this caps what one act may touch. */
    private const MAX_SELECTION = 500;

    public function mount(FileStorageService $drive): void
    {
        $this->authorize('viewAny', Folder::class);

        $this->applyPreferredDefaults();

        // Make sure the office has somewhere to put things before it looks.
        if ($office = Auth::user()->department) {
            $drive->documentScansFolderFor($office, Auth::user());
        }
    }

    /**
     * Open as this employee asked the drive to open, in Settings → Preferences.
     *
     * Only where the address bar has not already said otherwise. All three of
     * these are #[Url] properties, so a link somebody was sent — or a page they
     * bookmarked in list view — must win over a standing preference, or the
     * link would not take them where it says it does.
     */
    private function applyPreferredDefaults(): void
    {
        $preferences = Auth::user()->preferences();
        $query = request()->query();

        if (! array_key_exists('displayMode', $query)) {
            $this->displayMode = $preferences->driveView();
        }

        if (! array_key_exists('sortBy', $query)) {
            $this->sortBy = $preferences->driveSort();
        }

        if (! array_key_exists('sortDir', $query)) {
            $this->sortDir = $preferences->driveSortDirection();
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
        $this->detailsKey = null;
        $this->closePanel();
        $this->resetPage();
        $this->dispatch('drive-cleared');
    }

    public function switchView(string $view): void
    {
        $this->view = $view;
        $this->folderId = null;
        $this->detailsKey = null;
        $this->closePanel();
        $this->resetPage();
        $this->dispatch('drive-cleared');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->dispatch('drive-cleared');
    }

    public function setDisplayMode(string $mode): void
    {
        $this->displayMode = $mode === 'list' ? 'list' : 'grid';
    }

    /** Clicking the column already sorted on turns it around, as a table should. */
    public function sort(string $by): void
    {
        if (! in_array($by, ['name', 'updated_at', 'size'], true)) {
            return;
        }

        if ($this->sortBy === $by) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $by;
            // Names read best A→Z; dates and sizes are almost always asked for
            // biggest or newest first, so they start the other way round.
            $this->sortDir = $by === 'name' ? 'asc' : 'desc';
        }

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

    /**
     * Every branch that reads a folder or file by id scopes through
     * visibleTo() and then authorizes the same ability the button that
     * opened this panel was already gated behind. Without that, this being a
     * public Livewire property setter — not a page render — means anyone
     * could call it directly with an arbitrary id and read back another
     * office's file and folder names in the response, panel or no panel.
     */
    public function open(string $panel, ?int $targetId = null): void
    {
        $this->resetValidation();
        $this->panel = $panel;
        $this->targetId = $targetId;

        if ($panel === 'rename-folder' || $panel === 'visibility') {
            $folder = Folder::query()->visibleTo(Auth::user())->findOrFail($targetId);
            $this->authorize('update', $folder);

            if ($panel === 'rename-folder') {
                $this->formName = $folder->name;
            } else {
                $this->formVisibility = $folder->visibility->value;
            }
        }

        if ($panel === 'rename-file') {
            $file = File::query()->visibleTo(Auth::user())->findOrFail($targetId);
            $this->authorize('update', $file);

            $this->formName = $file->name;
        }

        if ($panel === 'new-folder') {
            $this->formName = '';
            $this->formVisibility = $this->currentFolder()?->visibility->value
                ?? FolderVisibility::Department->value;
        }
    }

    /**
     * Open a panel that acts on a selection rather than on one row.
     *
     * The keys are remembered only so the panel's own submit knows its subject.
     * Nothing is authorised here — opening a dialog decides nothing — and the
     * act itself re-resolves and re-authorises every key.
     */
    public function openFor(string $panel, array $keys): void
    {
        $this->resetValidation();
        $this->panel = $panel;
        $this->targetId = null;
        $this->selection = $this->cleanKeys($keys);
        $this->moveToId = null;
    }

    public function closePanel(): void
    {
        $this->reset(['panel', 'targetId', 'formName', 'formVisibility', 'moveToId', 'versionUpload', 'selection']);
        $this->resetValidation();
    }

    /** Remember which single item the details pane is describing. */
    public function loadDetails(?string $key): void
    {
        $this->detailsKey = is_string($key) && preg_match('/^(file|folder):[1-9]\d*$/', $key) === 1
            ? $key
            : null;
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

    /**
     * Move everything the Move panel was opened for.
     *
     * A destination of 0 is the top level, which only folders may go to: files
     * are not kept loose above a folder, the same rule uploadFile states.
     */
    public function moveSelected(FileStorageService $drive): void
    {
        $this->validate(
            ['moveToId' => ['required', 'integer', 'min:0']],
            attributes: ['moveToId' => 'destination folder'],
        );

        $this->performMove($this->selection, (int) $this->moveToId, $drive);
    }

    /** Dropping a selection onto a folder, which needs no panel to say where. */
    public function bulkMove(array $keys, int $targetId, FileStorageService $drive): void
    {
        $this->performMove($keys, $targetId, $drive);
    }

    public function bulkTrash(array $keys, FileStorageService $drive): void
    {
        $user = Auth::user();
        [$files, $folders] = $this->resolveKeys($keys);

        $done = 0;
        $problems = [];

        foreach ($files as $file) {
            if (! $user->can('delete', $file)) {
                $problems[] = $this->refusal($file->name);

                continue;
            }

            try {
                $drive->trash($file, $user);
                $done++;
            } catch (DriveException $e) {
                $problems[] = $e->getMessage();
            }
        }

        foreach ($folders as $folder) {
            if (! $user->can('delete', $folder)) {
                $problems[] = $this->refusal($folder->name);

                continue;
            }

            try {
                $drive->deleteFolder($folder, $user);

                if ($this->folderId === $folder->getKey()) {
                    $this->folderId = $folder->parent_id;
                }

                $done++;
            } catch (DriveException $e) {
                $problems[] = $e->getMessage();
            }
        }

        $this->report($done, $problems, 'Moved to the trash.');
    }

    public function bulkRestore(array $keys, FileStorageService $drive): void
    {
        $user = Auth::user();
        [$files] = $this->resolveKeys($keys, trashed: true);

        $done = 0;
        $problems = [];

        foreach ($files as $file) {
            if (! $user->can('restore', $file)) {
                $problems[] = $this->refusal($file->name);

                continue;
            }

            try {
                $drive->restore($file, $user);
                $done++;
            } catch (DriveException $e) {
                $problems[] = $e->getMessage();
            }
        }

        $this->report($done, $problems, 'Restored.');
    }

    public function bulkPurge(array $keys, FileStorageService $drive): void
    {
        $user = Auth::user();
        [$files] = $this->resolveKeys($keys, trashed: true);

        $done = 0;
        $problems = [];

        foreach ($files as $file) {
            if (! $user->can('forceDelete', $file)) {
                $problems[] = $this->refusal($file->name);

                continue;
            }

            try {
                $drive->purge($file, $user);
                $done++;
            } catch (DriveException $e) {
                $problems[] = $e->getMessage();
            }
        }

        $this->report($done, $problems, 'Destroyed for good.');
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

    private function performMove(array $keys, int $targetId, FileStorageService $drive): void
    {
        $user = Auth::user();
        $target = null;

        if ($targetId > 0) {
            $target = Folder::query()->visibleTo($user)->find($targetId);

            if (! $target) {
                $this->closePanel();
                $this->addError('drive', 'That destination no longer exists.');

                return;
            }

            // Said rather than thrown: a folder shared with the whole LGU is
            // readable and so a perfectly plausible thing to drag onto, and a
            // bare 403 page is no way to learn it is not writable.
            if (! $user->can('store', $target)) {
                $this->closePanel();
                $this->addError('drive', DriveException::notYourOffice($target)->getMessage());

                return;
            }
        }

        [$files, $folders] = $this->resolveKeys($keys);

        $done = 0;
        $problems = [];

        foreach ($files as $file) {
            if (! $target) {
                $problems[] = sprintf(
                    '“%s” is a file, and files are not kept loose at the top level.',
                    $file->name,
                );

                continue;
            }

            if (! $user->can('update', $file)) {
                $problems[] = $this->refusal($file->name);

                continue;
            }

            try {
                $drive->move($file, $target, $user);
                $done++;
            } catch (DriveException $e) {
                $problems[] = $e->getMessage();
            }
        }

        foreach ($folders as $folder) {
            if (! $user->can('update', $folder)) {
                $problems[] = $this->refusal($folder->name);

                continue;
            }

            try {
                $drive->moveFolder($folder, $target, $user);
                $done++;
            } catch (DriveException $e) {
                $problems[] = $e->getMessage();
            }
        }

        $this->report($done, $problems, 'Moved.');
    }

    /**
     * Turn selection keys into models this user is allowed to see.
     *
     * Scoped through visibleTo(), so an id typed into the payload by hand
     * reaches nothing another office owns. The caller still authorises each
     * model for the particular thing it is about to do to it.
     *
     * @param  array<int, mixed>  $keys
     * @return array{0: Models<int, File>, 1: Models<int, Folder>}
     */
    private function resolveKeys(array $keys, bool $trashed = false): array
    {
        $fileIds = [];
        $folderIds = [];

        foreach ($this->cleanKeys($keys) as $key) {
            [$type, $id] = explode(':', $key, 2);

            if ($type === 'file') {
                $fileIds[] = (int) $id;
            } else {
                $folderIds[] = (int) $id;
            }
        }

        $user = Auth::user();

        return [
            $fileIds === [] ? new Models : File::query()
                ->when($trashed, fn ($q) => $q->onlyTrashed())
                ->visibleTo($user)
                ->whereKey($fileIds)
                ->get(),
            $folderIds === [] ? new Models : Folder::query()
                ->visibleTo($user)
                ->whereKey($folderIds)
                ->get(),
        ];
    }

    /**
     * Keep only well-formed keys, and no more of them than one act may touch.
     *
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    private function cleanKeys(array $keys): array
    {
        return collect($keys)
            ->filter(fn ($key) => is_string($key) && preg_match('/^(file|folder):[1-9]\d*$/', $key) === 1)
            ->unique()
            ->take(self::MAX_SELECTION)
            ->values()
            ->all();
    }

    /**
     * Say what happened when a sweep only partly worked.
     *
     * Bulk acts over a mixed selection half-succeed routinely — a folder that
     * still has things in it, a file another office owns — and a bare total
     * would hide which. The first few refusals are quoted as the service wrote
     * them; the rest are counted, because twenty lines of red is not a message.
     *
     * @param  array<int, string>  $problems
     */
    private function report(int $done, array $problems, string $success): void
    {
        $this->closePanel();

        // Whatever was selected has just been trashed, moved or restored out
        // from under the listing that was showing it.
        $this->dispatch('drive-cleared');

        if ($problems === []) {
            session()->flash('status', $done === 1
                ? $success
                : $done.' items: '.mb_strtolower($success));

            return;
        }

        $shown = array_slice($problems, 0, 3);
        $rest = count($problems) - count($shown);

        $this->addError('drive', trim(sprintf(
            '%s %s%s',
            $done > 0 ? $done.' done.' : 'Nothing was changed.',
            implode(' ', $shown),
            $rest > 0 ? " …and {$rest} more." : '',
        )));
    }

    private function refusal(string $name): string
    {
        return "“{$name}” belongs to another office, or is not yours to change.";
    }

    /**
     * What this user may do to each listed item, settled once for the page.
     *
     * The flags come from the same writableBy() scope FilePolicy and
     * FolderPolicy ask, resolved in one query for the whole listing instead of
     * the two or three per row that repeated @can checks cost. They decide only
     * what is *offered*: every act re-authorises its own model, so a flag gone
     * stale between render and click buys nothing.
     *
     * @return array<string, array<string, mixed>>
     */
    private function abilitiesFor(Collection $folders, Collection $files): array
    {
        $user = Auth::user();

        $ids = $files->pluck('folder_id')->merge($folders->pluck('id'))->filter()->unique();

        $writable = $ids->isEmpty() ? [] : array_flip(
            Folder::query()->writableBy($user)->whereKey($ids->all())->pluck('id')->all()
        );

        $mayPurge = $user->can(Permission::SettingsManage->value);
        $inTrash = $this->view === 'trash';
        $out = [];

        foreach ($folders as $folder) {
            $writes = isset($writable[$folder->getKey()]) && ! $folder->is_system;

            $out['folder:'.$folder->getKey()] = [
                'kind' => 'folder',
                'name' => $folder->name,
                'open' => true,
                'rename' => $writes,
                'move' => $writes,
                'share' => $writes,
                'delete' => $writes,
                // Receiving a drop is FolderPolicy::store, which — unlike the
                // rest — does not exclude system folders: Document scans is
                // exactly the sort of folder things get filed into.
                'store' => isset($writable[$folder->getKey()]),
                'version' => false,
                'download' => false,
                'restore' => false,
                'purge' => false,
            ];
        }

        foreach ($files as $file) {
            $writes = isset($writable[$file->folder_id]) && ! $inTrash;

            $out['file:'.$file->getKey()] = [
                'kind' => 'file',
                'name' => $file->name,
                'open' => ! $inTrash,
                'rename' => $writes,
                'move' => $writes,
                'share' => false,
                'delete' => $writes,
                'version' => $writes,
                'download' => ! $inTrash,
                'restore' => $inTrash && isset($writable[$file->folder_id]),
                'purge' => $inTrash && isset($writable[$file->folder_id]) && $mayPurge,
                'url' => $inTrash
                    ? null
                    : route($file->isPreviewable() ? 'files.preview' : 'files.download', $file),
                'downloadUrl' => $inTrash ? null : route('files.download', $file),
                'preview' => ! $inTrash && $file->isPreviewable(),
                'type' => $file->kindLabel(),
            ];
        }

        foreach ($out as $key => $ability) {
            $out[$key]['attrs'] = $this->attrsFor($key, $ability);
        }

        return $out;
    }

    /**
     * The same flags, rendered as the data- attributes the item carries.
     *
     * Built here rather than in the template because these attributes have to
     * sit on the same element as the Alpine handlers, and Blade's component-tag
     * compiler mangles Alpine's own `:class` and `@click` syntax when it tries
     * to parse them as component arguments. A plain element with a
     * server-built attribute string sidesteps that entirely — and since every
     * value is escaped here, the template can print it raw.
     */
    private function attrsFor(string $key, array $ability): string
    {
        $attrs = [
            'data-key' => $key,
            'data-kind' => $ability['kind'],
            'data-name' => $ability['name'],
        ];

        foreach (['open', 'rename', 'move', 'share', 'delete', 'store', 'version', 'download', 'restore', 'purge', 'preview'] as $flag) {
            $attrs['data-'.$flag] = empty($ability[$flag]) ? '0' : '1';
        }

        foreach (['url' => 'data-url', 'downloadUrl' => 'data-download-url', 'type' => 'data-type'] as $from => $to) {
            if (filled($ability[$from] ?? null)) {
                $attrs[$to] = $ability[$from];
            }
        }

        return collect($attrs)
            ->map(fn ($value, $name) => $name.'="'.e($value).'"')
            ->implode(' ');
    }

    /**
     * The one item the details pane is describing, if it is open.
     *
     * @return array<string, mixed>|null
     */
    private function detailsFor(): ?array
    {
        if ($this->detailsKey === null) {
            return null;
        }

        [$type, $id] = explode(':', $this->detailsKey, 2);
        $user = Auth::user();

        if ($type === 'folder') {
            $folder = Folder::query()
                ->visibleTo($user)
                ->withCount(['files', 'children'])
                ->with(['department', 'creator', 'parent'])
                ->find((int) $id);

            return $folder ? ['kind' => 'folder', 'folder' => $folder] : null;
        }

        // withTrashed so the pane still describes something looked at in the
        // trash, where every row is soft-deleted by definition.
        $file = File::query()
            ->withTrashed()
            ->visibleTo($user)
            ->with(['folder', 'uploader'])
            ->find((int) $id);

        if (! $file) {
            return null;
        }

        return [
            'kind' => 'file',
            'file' => $file,
            'versions' => File::withTrashed()
                ->where('version_group_id', $file->version_group_id)
                ->with('uploader')
                ->orderByDesc('version_no')
                ->get(),
        ];
    }

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
            'usedBytes' => $user->department_id
                ? (int) File::withTrashed()->where('department_id', $user->department_id)->sum('size')
                : null,
            'abilities' => $this->abilitiesFor($folders, $files->getCollection()),
            'details' => $this->detailsFor(),
            // The selection bar and the context menu are rendered once for the
            // page rather than per row, so their destructive entries cannot be
            // gated by a per-item flag alone. Somebody who can never destroy
            // anything should not find the word in their page at all.
            'mayPurge' => $user->can(Permission::SettingsManage->value),
        ])->layout('components.layouts.app', ['title' => 'Drive']);
    }

    private function foldersToList(?Folder $current)
    {
        $user = Auth::user();

        if ($this->view === 'trash' || $this->search !== '') {
            return collect();
        }

        if ($current) {
            return $this->sortFolders($current->children()->visibleTo($user))->withCount('files')->get();
        }

        if ($this->view === 'shared') {
            // The shallowest folders other offices have shared. Listing every
            // shared folder would show a nested tree flattened into a pile.
            return $this->sortFolders(Folder::query()
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
                ->with('department'))
                ->withCount('files')
                ->get();
        }

        return $this->sortFolders(Folder::query()
            ->visibleTo($user)
            ->where('department_id', $user->department_id)
            ->roots())
            ->withCount('files')
            ->get();
    }

    /**
     * System folders stay pinned to the top whatever the sort.
     *
     * They are where other records point — Document scans in particular — so
     * burying them under an alphabetical run of ordinary folders would hide
     * the one folder a clerk most often wants. Folders have no size of their
     * own, so sorting by size falls back to name for them.
     */
    private function sortFolders($query)
    {
        return $query->reorder()
            ->orderByDesc('is_system')
            ->orderBy($this->sortBy === 'updated_at' ? 'updated_at' : 'name', $this->direction());
    }

    /**
     * Both sort properties are #[Url], so both arrive from the address bar and
     * neither can be handed to the query builder as it stands: orderBy() throws
     * on any direction that is not asc or desc, which would turn a mistyped URL
     * into a 500. The column is pinned by a match; this pins the direction.
     */
    private function direction(): string
    {
        return $this->sortDir === 'desc' ? 'desc' : 'asc';
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

        $column = match ($this->sortBy) {
            'updated_at' => 'updated_at',
            'size' => 'size',
            default => 'name',
        };

        return $query->reorder()->orderBy($column, $this->direction())->paginate($this->perPage());
    }
}
