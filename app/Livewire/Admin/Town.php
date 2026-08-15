<?php

namespace App\Livewire\Admin;

use App\Enums\Permission;
use App\Models\LandmarkPhoto;
use App\Services\AuditLogger;
use App\Services\FileStorageService;
use App\Support\Compound;
use App\Support\World;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * The photographs behind the town and the compound.
 *
 * Both screens are drawings. Clicking the covered court should show the covered
 * court — the real one — and clicking the Treasurer's Office should show the
 * counter somebody is about to queue at. This is where those pictures come
 * from. One place at a time, because that is how somebody works: they have four
 * photographs of the plaza on their phone and they are here to deal with the
 * plaza.
 *
 * Gated on settings.manage, the same permission as Storage & Backups and System
 * settings. This is not a records screen: it changes what a stranger sees on the
 * front page of a gov.ph domain, which is the municipality's face rather than
 * any one office's business.
 *
 * Uploading writes an ordinary file into the uploader's own drive, through
 * FileStorageService like every other upload in the system. What makes it public
 * is the landmark_photos row — see App\Models\LandmarkPhoto. Removing a
 * photograph deletes that row and leaves the file alone.
 */
class Town extends Component
{
    use WithFileUploads;

    /** Which landmark is being worked on. In the URL so the screen is linkable. */
    #[Url(except: '')]
    public string $landmark = '';

    public $upload = null;

    public string $caption = '';

    /** The row whose caption is being edited, if any. */
    public ?int $editingId = null;

    public string $editingCaption = '';

    public function mount(): void
    {
        $this->authorize(Permission::SettingsManage->value);

        // Land on something rather than on an empty screen with a dropdown.
        if ($this->landmark === '' || ! array_key_exists($this->landmark, $this->everywhere())) {
            $this->landmark = (string) array_key_first($this->everywhere());
        }
    }

    /**
     * Everywhere a photograph can be hung, key => name.
     *
     * Two drawn screens, one screen for managing them. The town's landmarks
     * come from App\Support\World and the compound's buildings from
     * App\Support\Compound, both built from the same lists those screens are
     * drawn from — so a landmark added to either appears here without anybody
     * having to remember a third list.
     *
     * @return array<string, string>
     */
    private function everywhere(): array
    {
        return World::landmarks() + Compound::landmarks();
    }

    /*
    |--------------------------------------------------------------------------
    | Adding
    |--------------------------------------------------------------------------
    */

    public function add(FileStorageService $files, AuditLogger $audit): void
    {
        $this->authorize(Permission::SettingsManage->value);

        $this->validate([
            'landmark' => ['required', Rule::in(array_keys($this->everywhere()))],

            /*
             * Raster images only, and the rule is doubled on purpose. This is
             * checked here, checked again by the store, and checked a third
             * time by the public route before a byte is served — because that
             * route sends these inline to somebody who has not signed in, and
             * `inline` on a type the browser renders as markup is exactly how
             * an upload becomes stored cross-site scripting. SVG is markup.
             */
            'upload' => [
                'required', 'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.((int) config('drive.max_upload_mb', 50) * 1024),
            ],
            'caption' => ['nullable', 'string', 'max:200'],
        ], attributes: ['upload' => 'photograph']);

        $office = Auth::user()->department;

        if (! $office) {
            // Files belong to offices in this system; there is nowhere to put
            // one for an account that is not in an office yet.
            $this->addError('upload', 'Your account needs to be in an office before you can upload photographs.');

            return;
        }

        try {
            DB::transaction(function () use ($files, $audit, $office) {
                $file = $files->store(
                    upload: $this->upload,
                    folder: $files->townPhotosFolderFor($office, Auth::user()),
                    by: Auth::user(),
                    name: $this->upload->getClientOriginalName(),
                );

                $photo = LandmarkPhoto::create([
                    'landmark' => $this->landmark,
                    'file_id' => $file->getKey(),
                    'caption' => $this->caption ?: null,
                    'sort_order' => $this->nextSortOrder(),
                    'created_by' => Auth::id(),
                ]);

                $audit->log(
                    event: 'landmark.photo_added',
                    subject: $photo,
                    properties: ['landmark' => $this->landmark, 'file_id' => $file->getKey()],
                    description: "Added a photograph to “{$this->landmarkName()}” on the public page.",
                );
            });
        } catch (Throwable $e) {
            report($e);
            $this->addError('upload', 'That photograph could not be saved. Try again, or check the log.');

            return;
        }

        $this->reset(['upload', 'caption']);
        session()->flash('status', 'Added. It is on the public page now.');
    }

    private function nextSortOrder(): int
    {
        return (int) LandmarkPhoto::query()
            ->where('landmark', $this->landmark)
            ->max('sort_order') + 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Editing and ordering
    |--------------------------------------------------------------------------
    */

    public function editCaption(int $id): void
    {
        $photo = $this->photo($id);

        $this->editingId = $photo->getKey();
        $this->editingCaption = (string) $photo->caption;
        $this->resetValidation();
    }

    public function saveCaption(): void
    {
        $this->authorize(Permission::SettingsManage->value);

        $data = $this->validate(['editingCaption' => ['nullable', 'string', 'max:200']]);

        $this->photo((int) $this->editingId)->update([
            'caption' => $data['editingCaption'] ?: null,
        ]);

        $this->reset(['editingId', 'editingCaption']);
    }

    public function cancelCaption(): void
    {
        $this->reset(['editingId', 'editingCaption']);
        $this->resetValidation();
    }

    /**
     * Move one photograph one place along.
     *
     * The whole landmark's list is renumbered from zero afterwards rather than
     * two rows being swapped, because sort_order gets untidy the moment
     * anything is deleted and a list that renumbers itself never has to be
     * repaired.
     */
    public function move(int $id, int $by): void
    {
        $this->authorize(Permission::SettingsManage->value);

        $ordered = LandmarkPhoto::query()->forLandmark($this->landmark)->get();
        $from = $ordered->search(fn (LandmarkPhoto $p) => $p->getKey() === $id);

        if ($from === false) {
            return;
        }

        $to = $from + $by;

        if ($to < 0 || $to >= $ordered->count()) {
            return;
        }

        $moved = $ordered->splice($from, 1)->first();
        $ordered->splice($to, 0, [$moved]);

        DB::transaction(function () use ($ordered) {
            $ordered->values()->each(
                fn (LandmarkPhoto $photo, int $i) => $photo->update(['sort_order' => $i])
            );
        });
    }

    public function remove(int $id, AuditLogger $audit): void
    {
        $this->authorize(Permission::SettingsManage->value);

        $photo = $this->photo($id);
        $landmark = $photo->landmark;

        $audit->log(
            event: 'landmark.photo_removed',
            properties: ['landmark' => $landmark, 'file_id' => $photo->file_id],
            description: "Took a photograph off “{$this->landmarkName()}” on the public page.",
        );

        // The row goes; the file stays in its office's drive. Taking a picture
        // off the front page is not a reason to destroy it.
        $photo->delete();

        session()->flash('status', 'Removed from the public page. The file is still in the drive.');
    }

    private function photo(int $id): LandmarkPhoto
    {
        return LandmarkPhoto::query()
            ->where('landmark', $this->landmark)
            ->findOrFail($id);
    }

    private function landmarkName(): string
    {
        return $this->everywhere()[$this->landmark] ?? $this->landmark;
    }

    public function updatedLandmark(): void
    {
        $this->reset(['upload', 'caption', 'editingId', 'editingCaption']);
        $this->resetValidation();
    }

    public function render()
    {
        return view('livewire.admin.town', [
            'town' => World::landmarks(),
            'offices' => Compound::landmarks(),
            'landmarkName' => $this->landmarkName(),
            'photos' => LandmarkPhoto::query()
                ->forLandmark($this->landmark)
                ->with('file')
                ->get(),
            'counts' => LandmarkPhoto::query()
                ->groupBy('landmark')
                ->selectRaw('landmark, count(*) as total')
                ->pluck('total', 'landmark'),
        ])->layout('components.layouts.app', ['title' => 'The town']);
    }
}
