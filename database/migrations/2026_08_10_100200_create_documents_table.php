<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();

            // BGB-MO-2026-08-0001. Municipality, registering office, year,
            // month, sequence. Issued once and never reissued — it is written
            // on the paper and quoted in follow-up letters.
            $table->string('tracking_no', 48)->unique();

            // The document's own official number, as its issuing office wrote
            // it: "Office Order No. 12, s. 2026", "SB Resolution 2026-041".
            // Staff search by this far more often than by the tracking number,
            // because it is what the document calls itself.
            $table->string('reference_no', 64)->nullable();

            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();

            $table->string('subject');
            $table->text('description')->nullable();

            // Where the paper came from. Always a departments row — external
            // parties (provincial, national, barangay) have rows of their own,
            // which keeps every join simple and every origin auditable.
            $table->foreignId('origin_department_id')->constrained('departments')->restrictOnDelete();

            // The specific outside sender, when the origin row is a catch-all:
            // "Hon. Juan dela Cruz, Punong Barangay, Brgy. Anilao".
            $table->string('origin_external_name')->nullable();

            // The office that entered this document into the system. Always
            // internal, and the code that appears in the tracking number. Kept
            // separate from origin_department_id because for incoming mail the
            // two differ, and separate from the creator's current office
            // because staff transfer and the record must not move with them.
            $table->foreignId('registering_department_id')->constrained('departments')->restrictOnDelete();

            $table->string('confidentiality', 20)->default('internal');
            $table->string('status', 20)->default('draft');

            // The office that last signed for the document, and is therefore
            // accountable for it right now. While a document is in transit this
            // deliberately stays with the *sender*: until the destination signs,
            // the sender is the one who has to answer for where it is. That is
            // both the paper reality and what makes "exactly one holder at all
            // times" a true statement rather than an aspiration.
            $table->foreignId('current_holder_department_id')->nullable()
                ->constrained('departments')->nullOnDelete();
            $table->foreignId('current_holder_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamp('due_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The desk queries: "what is sitting with my office", "what is my
            // office's caseload", "what is overdue".
            $table->index(['current_holder_department_id', 'status']);
            $table->index(['registering_department_id', 'status']);
            $table->index(['status', 'due_at']);
            $table->index('reference_no');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
