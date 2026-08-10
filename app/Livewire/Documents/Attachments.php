<?php

namespace App\Livewire\Documents;

use App\Exceptions\DriveException;
use App\Models\Document;
use App\Models\File;
use App\Services\AuditLogger;
use App\Services\FileStorageService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Scans and annexes on a tracked document.
 *
 * This is where the two halves of the system meet. Until a scan is attached,
 * the routing engine is tracking a piece of paper it has never seen — it knows
 * where the folder is but not what is in it. Attaching means an office three
 * transmittals away can read the thing without waiting for the folder to
 * arrive, while the folder itself still travels and still gets signed for.
 *
 * Uploads land in the owning office's "Document scans" folder, so an attachment
 * is an ordinary file in the drive with ordinary versioning and an ordinary
 * trail — not a second, parallel store with its own rules.
 */
class Attachments extends Component
{
    use WithFileUploads;

    public Document $document;

    public $upload;

    public string $kind = 'attachment';

    public function mount(Document $document): void
    {
        $this->authorize('view', $document);

        $this->document = $document;
    }

    /** Attaching is part of working on a document, so the holding office does it. */
    public function canAttach(): bool
    {
        return Auth::user()->can('comment', $this->document);
    }

    public function attach(FileStorageService $drive, AuditLogger $audit): void
    {
        abort_unless($this->canAttach(), 403);

        $this->validate([
            'upload' => [
                'required', 'file',
                'max:'.((int) config('drive.max_upload_mb', 50) * 1024),
                'extensions:'.implode(',', config('drive.allowed_extensions', [])),
            ],
            'kind' => ['required', 'in:main,attachment'],
        ], attributes: ['upload' => 'file']);

        $office = Auth::user()->department;

        try {
            $file = $drive->store(
                upload: $this->upload,
                folder: $drive->documentScansFolderFor($office, Auth::user()),
                by: Auth::user(),
                name: $this->upload->getClientOriginalName(),
            );
        } catch (DriveException $e) {
            $this->addError('upload', $e->getMessage());

            return;
        }

        // A document has one main copy; anything else attached is an annex.
        $kind = $this->kind === 'main' && ! $this->hasMain() ? 'main' : 'attachment';

        DB::table('document_files')->insert([
            'document_id' => $this->document->getKey(),
            'file_id' => $file->getKey(),
            'kind' => $kind,
            'attached_by' => Auth::id(),
            'created_at' => now(),
        ]);

        $audit->log(
            event: 'document.file_attached',
            subject: $this->document,
            properties: ['file_id' => $file->getKey(), 'name' => $file->name, 'kind' => $kind],
            description: "Attached “{$file->name}” to {$this->document->tracking_no}.",
        );

        $this->reset(['upload', 'kind']);
        $this->document->refresh();

        session()->flash('status', "Attached “{$file->name}”.");
    }

    /**
     * Take it off the document. The file stays in the drive.
     *
     * Detaching is not deleting on purpose: the scan remains in the office's
     * folder with its own history, so removing it from one document cannot
     * quietly destroy something another document also relies on.
     */
    public function detach(int $fileId, AuditLogger $audit): void
    {
        abort_unless($this->canAttach(), 403);

        $file = File::find($fileId);

        DB::table('document_files')
            ->where('document_id', $this->document->getKey())
            ->where('file_id', $fileId)
            ->delete();

        $audit->log(
            event: 'document.file_detached',
            subject: $this->document,
            properties: ['file_id' => $fileId, 'name' => $file?->name],
            description: sprintf(
                'Detached “%s” from %s. The file is still in the drive.',
                $file?->name ?? 'a file',
                $this->document->tracking_no,
            ),
        );

        $this->document->refresh();
        session()->flash('status', 'Detached. The file is still in your office folder.');
    }

    private function hasMain(): bool
    {
        return DB::table('document_files')
            ->where('document_id', $this->document->getKey())
            ->where('kind', 'main')
            ->exists();
    }

    public function render()
    {
        return view('livewire.documents.attachments', [
            'files' => $this->document->files()
                ->visibleTo(Auth::user())
                ->current()
                ->with('uploader')
                ->orderByRaw("field(document_files.kind, 'main') desc")
                ->orderBy('files.name')
                ->get(),
            'canAttach' => $this->canAttach(),
            'hasMain' => $this->hasMain(),
            'maxUploadMb' => (int) config('drive.max_upload_mb', 50),
        ]);
    }
}
