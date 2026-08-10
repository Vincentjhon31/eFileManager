<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('folder_id')->constrained()->restrictOnDelete();

            // Denormalised from the folder. Every visibility check starts with
            // "which office owns this", and a file never crosses offices — a
            // move is only ever within one — so the copy cannot drift.
            $table->foreignId('department_id')->constrained()->restrictOnDelete();

            $table->string('name');           // shown, and renameable
            $table->string('original_name');  // exactly as it was uploaded
            $table->string('mime', 191);
            $table->unsignedBigInteger('size');

            // SHA-256 of the contents. Used to prove a stored file has not been
            // altered on disk, and to refuse a "new version" that is byte for
            // byte the one already there.
            //
            // Not used to share storage between rows. Reference counting one
            // blob across several records would mean a delete could take away
            // somebody else's file, and on a records system that is a far worse
            // failure than using more of a 100 GB disk than strictly necessary.
            $table->char('sha256', 64);

            // Relative to the 'documents' disk, which is outside the web root.
            $table->string('storage_path');

            // Versions of the same file share a group. Uploading a replacement
            // does not overwrite anything: it adds a row and moves the flag.
            $table->uuid('version_group_id');
            $table->unsignedSmallInteger('version_no')->default(1);
            $table->boolean('is_current')->default(true);

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            // Trash. Restorable indefinitely; emptying it is a separate,
            // privileged act that is written to the audit trail.
            $table->softDeletes();
            $table->timestamps();

            $table->index(['folder_id', 'is_current', 'deleted_at']);
            $table->index(['department_id', 'is_current']);
            $table->unique(['version_group_id', 'version_no']);
            $table->index('sha256');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
