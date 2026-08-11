<?php

namespace App\Livewire\Admin;

use App\Enums\AnnouncementCategory;
use App\Enums\Permission;
use App\Exceptions\PublicationException;
use App\Models\Announcement;
use App\Services\AuditLogger;
use App\Services\PublicationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Writing notices, and putting them on the public page.
 *
 * Two things, kept apart. Saving a notice never publishes it and publishing is
 * never a checkbox on the edit form — it is its own button, with its own
 * confirmation, and the wording is deliberately blunt about the audience.
 *
 * The reason is that this is the only irreversible act in the system. A
 * document can be recalled, a file restored, a receipt corrected by a new
 * entry. A notice that has been read by the town cannot be unread.
 */
class Announcements extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: 'all')]
    public string $filter = 'all';

    public ?int $editingId = null;

    public string $title = '';

    public string $category = 'notice';

    public string $excerpt = '';

    public string $body = '';

    public string $expires_at = '';

    public bool $is_pinned = false;

    /** Set when a publish or withdrawal is awaiting its confirmation. */
    public ?int $confirmingId = null;

    public string $confirmAction = '';

    public string $reason = '';

    /**
     * One permission gates the whole screen, drafting included.
     *
     * There is no separate "may write a draft" right. In a municipality this
     * size the person who writes the notice is the person who releases it, and
     * a second permission would only be another thing to forget to revoke. The
     * safety is the two-step confirmation, not a second role.
     */
    public function mount(): void
    {
        $this->authorize(Permission::PublicPublish->value);
    }

    public function canPublish(): bool
    {
        return Auth::user()->can(Permission::PublicPublish->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    public function create(): void
    {
        $this->resetForm();
        $this->editingId = 0;
    }

    public function edit(int $id): void
    {
        $this->authorize(Permission::PublicPublish->value);

        $announcement = Announcement::findOrFail($id);

        $this->editingId = $announcement->id;
        $this->title = $announcement->title;
        $this->category = $announcement->category->value;
        $this->excerpt = $announcement->excerpt ?? '';
        $this->body = $announcement->body;
        $this->expires_at = $announcement->expires_at?->setTimezone(ph_tz())->format('Y-m-d\TH:i') ?? '';
        $this->is_pinned = $announcement->is_pinned;

        $this->resetValidation();
    }

    public function save(AuditLogger $audit): void
    {
        $this->authorize(Permission::PublicPublish->value);

        $data = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::enum(AnnouncementCategory::class)],
            'excerpt' => ['nullable', 'string', 'max:400'],
            'body' => ['required', 'string', 'max:20000'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $attributes = [
            'title' => $data['title'],
            'category' => AnnouncementCategory::from($data['category']),
            'excerpt' => $data['excerpt'] ?: null,
            'body' => $data['body'],
            'expires_at' => $data['expires_at'] ?: null,
            'is_pinned' => $this->is_pinned,
        ];

        if ($this->editingId) {
            $announcement = Announcement::findOrFail($this->editingId);

            $announcement->update($attributes + [
                'slug' => Announcement::slugFor($data['title'], $announcement->id),
            ]);

            // Editing a notice that is already up changes what the town is
            // being told, so it is recorded as its own act rather than folded
            // into a generic update.
            $audit->log(
                event: $announcement->isLive() ? 'public.announcement_edited_while_live' : 'public.announcement_saved',
                subject: $announcement,
                properties: ['title' => $announcement->title, 'live' => $announcement->isLive()],
                description: $announcement->isLive()
                    ? "Edited “{$announcement->title}” while it was on the public page."
                    : "Saved the draft “{$announcement->title}”.",
            );

            session()->flash('status', 'Saved.');
        } else {
            $announcement = Announcement::create($attributes + [
                'slug' => Announcement::slugFor($data['title']),
                'department_id' => Auth::user()->department_id,
                'author_id' => Auth::id(),
            ]);

            $audit->log(
                event: 'public.announcement_saved',
                subject: $announcement,
                properties: ['title' => $announcement->title],
                description: "Wrote the draft “{$announcement->title}”. Not yet public.",
            );

            session()->flash('status', 'Saved as a draft. It is not on the public page until you publish it.');
        }

        $this->resetForm();
    }

    /*
    |--------------------------------------------------------------------------
    | Publishing — the second step, always
    |--------------------------------------------------------------------------
    */

    public function confirm(string $action, int $id): void
    {
        abort_unless($this->canPublish(), 403);

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
        abort_unless($this->canPublish(), 403);

        $announcement = Announcement::findOrFail($this->confirmingId);

        try {
            $publication->publishAnnouncement($announcement, Auth::user());
        } catch (PublicationException $e) {
            $this->addError('publication', $e->getMessage());

            return;
        }

        $this->cancelConfirmation();
        session()->flash('status', "“{$announcement->title}” is now on the public page.");
    }

    public function withdraw(PublicationService $publication): void
    {
        abort_unless($this->canPublish(), 403);

        $announcement = Announcement::findOrFail($this->confirmingId);

        $data = $this->validate(['reason' => ['required', 'string', 'max:500']], [
            'reason.required' => 'Say why it is coming down. This goes on the record.',
        ]);

        try {
            $publication->unpublishAnnouncement($announcement, Auth::user(), $data['reason']);
        } catch (PublicationException $e) {
            $this->addError('publication', $e->getMessage());

            return;
        }

        $this->cancelConfirmation();
        session()->flash('status', "“{$announcement->title}” has been taken off the public page.");
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'title', 'category', 'excerpt', 'body', 'expires_at', 'is_pinned']);
        $this->resetValidation();
    }

    public function render()
    {
        $announcements = Announcement::query()
            ->with(['department', 'publisher'])
            ->when($this->filter === 'live', fn ($q) => $q->live())
            ->when($this->filter === 'draft', fn ($q) => $q->whereNull('published_at'))
            ->when($this->search !== '', function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($sub) => $sub->where('title', 'like', $term)->orWhere('body', 'like', $term));
            })
            ->orderByDesc('id')
            ->paginate(15);

        return view('livewire.admin.announcements', [
            'announcements' => $announcements,
            'categories' => AnnouncementCategory::all(),
            'confirming' => $this->confirmingId ? Announcement::find($this->confirmingId) : null,
            'canPublish' => $this->canPublish(),
        ])->layout('components.layouts.app', ['title' => 'Notices']);
    }
}
