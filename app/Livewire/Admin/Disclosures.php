<?php

namespace App\Livewire\Admin;

use App\Enums\DisclosureCategory;
use App\Enums\Permission;
use App\Exceptions\PublicationException;
use App\Models\File;
use App\Models\PublicFile;
use App\Services\PublicationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The Full Disclosure Policy board, from the inside.
 *
 * Two steps, and the gap between them is the point.
 *
 *   1. **Prepare.** Pick a file already in the drive, give it a title the
 *      public will understand, file it under a heading and a fiscal year.
 *      Nothing is public. This is where somebody opens the scan and checks it
 *      is the right document, right way up, and legible.
 *
 *   2. **Publish.** A separate button with a separate confirmation that says
 *      plainly who is about to be able to read it.
 *
 * Nothing is copied. The same bytes serve the office and the public; what this
 * screen changes is whether a row says they may be served without signing in.
 */
class Disclosures extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $filter = 'all';

    /** Preparing a new entry. */
    public bool $preparing = false;

    public ?int $file_id = null;

    public string $title = '';

    public string $description = '';

    public string $category = 'other';

    public ?int $fiscal_year = null;

    /** A publish or withdrawal awaiting confirmation. */
    public ?int $confirmingId = null;

    public string $confirmAction = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize(Permission::PublicPublish->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Step one: prepare
    |--------------------------------------------------------------------------
    */

    public function prepare(): void
    {
        $this->authorize(Permission::PublicPublish->value);

        $this->reset(['file_id', 'title', 'description', 'category', 'fiscal_year']);
        $this->fiscal_year = (int) ph_now()->year;
        $this->preparing = true;
        $this->resetValidation();
    }

    /** Pre-fill the public title from the file, since it is usually close. */
    public function updatedFileId($value): void
    {
        if ($value && $this->title === '' && $file = File::find($value)) {
            $this->title = $file->name;
        }
    }

    public function savePreparation(PublicationService $publication): void
    {
        $this->authorize(Permission::PublicPublish->value);

        $data = $this->validate([
            'file_id' => ['required', 'exists:files,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'category' => ['required', Rule::enum(DisclosureCategory::class)],
            'fiscal_year' => ['nullable', 'integer', 'min:1900', 'max:2200'],
        ], attributes: ['file_id' => 'file']);

        // Not just any file id: only one this user could already open through
        // the drive. Otherwise the disclosure board would be a second, weaker
        // door into every office's files.
        $file = File::query()->visibleTo(Auth::user())->findOrFail($data['file_id']);

        try {
            $publication->nominate($file, [
                'title' => $data['title'],
                'description' => $data['description'] ?: null,
                'category' => DisclosureCategory::from($data['category']),
                'fiscal_year' => $data['fiscal_year'],
            ], Auth::user());
        } catch (PublicationException $e) {
            $this->addError('publication', $e->getMessage());

            return;
        }

        $this->reset(['preparing', 'file_id', 'title', 'description', 'category', 'fiscal_year']);
        session()->flash('status', 'Prepared. Check it, then publish it — it is not public yet.');
    }

    public function cancelPreparation(): void
    {
        $this->reset(['preparing', 'file_id', 'title', 'description', 'category', 'fiscal_year']);
        $this->resetValidation();
    }

    /*
    |--------------------------------------------------------------------------
    | Step two: publish
    |--------------------------------------------------------------------------
    */

    public function confirm(string $action, int $id): void
    {
        $this->authorize(Permission::PublicPublish->value);

        $this->confirmAction = $action;
        $this->confirmingId = $id;
        $this->reason = '';
        $this->resetValidation();
    }

    public function cancelConfirmation(): void
    {
        $this->reset(['confirmingId', 'confirmAction', 'reason']);
        $this->resetValidation();
    }

    public function publish(PublicationService $publication): void
    {
        $this->authorize(Permission::PublicPublish->value);

        $entry = PublicFile::findOrFail($this->confirmingId);

        try {
            $publication->publishFile($entry, Auth::user());
        } catch (PublicationException $e) {
            $this->addError('publication', $e->getMessage());

            return;
        }

        $this->cancelConfirmation();
        session()->flash('status', "“{$entry->title}” is now on the public disclosure board.");
    }

    public function withdraw(PublicationService $publication): void
    {
        $this->authorize(Permission::PublicPublish->value);

        $entry = PublicFile::findOrFail($this->confirmingId);

        $data = $this->validate(['reason' => ['required', 'string', 'max:500']], [
            'reason.required' => 'Say why it is being withdrawn. This goes on the record.',
        ]);

        try {
            $publication->withdrawFile($entry, Auth::user(), $data['reason']);
        } catch (PublicationException $e) {
            $this->addError('publication', $e->getMessage());

            return;
        }

        $this->cancelConfirmation();
        session()->flash('status', "“{$entry->title}” has been withdrawn.");
    }

    public function render()
    {
        $entries = PublicFile::query()
            ->with(['file', 'publisher'])
            ->onTheBoard()
            ->when($this->filter === 'published', fn ($q) => $q->live())
            ->when($this->filter === 'prepared', fn ($q) => $q->whereNull('published_at'))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($sub) => $sub->where('title', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->orderByDesc('id')
            ->paginate(20);

        return view('livewire.admin.disclosures', [
            'entries' => $entries,
            'categories' => DisclosureCategory::all(),
            'confirming' => $this->confirmingId ? PublicFile::with('file')->find($this->confirmingId) : null,

            // Only what this user could open anyway, and only files not already
            // prepared. Disclosure must not become a way to reach into another
            // office's drive.
            'candidates' => $this->preparing
                ? File::query()
                    ->visibleTo(Auth::user())
                    ->current()
                    ->whereDoesntHave('publicFile')
                    ->with('folder')
                    ->orderBy('name')
                    ->limit(200)
                    ->get()
                : collect(),
        ])->layout('components.layouts.app', ['title' => 'Disclosure board']);
    }
}
