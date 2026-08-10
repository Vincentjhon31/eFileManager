<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scans and annexes attached to a tracked document.
     *
     * This is where the two halves of the system meet, and it is what makes the
     * drive worth having. Until now the routing engine has tracked a piece of
     * paper it has never seen; attaching the scan means an office three
     * transmittals away can read the thing without waiting for the folder to
     * arrive — while the folder itself still travels, still gets signed for,
     * and still ends up filed exactly where it always was.
     */
    public function up(): void
    {
        Schema::create('document_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('file_id')->constrained()->cascadeOnDelete();

            // 'main' is the document itself; 'attachment' is an annex, a
            // supporting voucher, a photograph. A document has at most one main.
            $table->string('kind', 20)->default('attachment');

            $table->foreignId('attached_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['document_id', 'file_id']);
            $table->index(['document_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_files');
    }
};
