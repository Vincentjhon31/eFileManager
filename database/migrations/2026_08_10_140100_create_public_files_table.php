<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A file the municipality has decided to show the public.
     *
     * Named public_files rather than the plan's public_documents on purpose:
     * it publishes a row from `files`, the drive, and not a tracked `Document`.
     * Two tables one letter apart meaning different things is how somebody
     * eventually publishes the wrong one.
     *
     * This row — not the file — is the capability. The public download route
     * takes a public_files id and refuses anything not published, so there is
     * no path from a guessed file id to a file in the drive. That separation is
     * the whole security model of the portal.
     */
    public function up(): void
    {
        Schema::create('public_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('file_id')->constrained()->cascadeOnDelete();

            // What the public sees it called. The drive's filename is an
            // internal matter and is often unhelpful: "scan0043.pdf".
            $table->string('title');
            $table->string('description', 500)->nullable();

            $table->string('category', 30)->default('other');

            // Which year's figures these are. Null for things that are not
            // filed by year, such as an ordinance.
            $table->unsignedSmallInteger('fiscal_year')->nullable();

            // Set when this is an attachment on a notice rather than a shelf on
            // the disclosure board — an event poster, a bid form.
            $table->foreignId('announcement_id')->nullable()->constrained()->cascadeOnDelete();

            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unpublished_at')->nullable();
            $table->foreignId('unpublished_by')->nullable()->constrained('users')->nullOnDelete();

            // Worth counting: "how many people read it" is a fair question to
            // be asked about a disclosure obligation.
            $table->unsignedInteger('download_count')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One file, published once. Publishing the same scan twice under
            // two headings would give the public two links that disagree about
            // when it was disclosed.
            $table->unique('file_id');

            $table->index(['published_at', 'category']);
            $table->index(['category', 'fiscal_year']);
            $table->index('announcement_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_files');
    }
};
