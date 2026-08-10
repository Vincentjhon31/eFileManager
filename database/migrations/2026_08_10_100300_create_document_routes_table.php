<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The transmittal ledger — one row per handover, in order.
     *
     * This is the digital equivalent of the receiving logbook, and it is the
     * part of the system that has to hold up if anyone ever asks "who had this
     * on the fourteenth?". Rows are appended and never removed; a leg sent to
     * the wrong office is cancelled in place, not deleted.
     */
    public function up(): void
    {
        Schema::create('document_routes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // Position in the journey, starting at 1. Unique per document, so
            // two concurrent releases cannot both open leg 3 — the database
            // refuses the second one even if the application lock were missed.
            $table->unsignedSmallInteger('seq');

            $table->foreignId('from_department_id')->constrained('departments')->restrictOnDelete();
            $table->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_actor_name')->nullable(); // survives the account being removed

            $table->foreignId('to_department_id')->constrained('departments')->restrictOnDelete();

            // Optional: addressed to a named person rather than the office
            // generally. The office may still receive it either way.
            $table->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action_requested', 40);
            $table->text('remarks')->nullable();

            // Set when this leg sends the document back where it came from,
            // rather than onward. Worth distinguishing: "it was returned to me"
            // means something different to a clerk than "it was forwarded".
            $table->boolean('is_return')->default(false);

            $table->timestamp('due_at')->nullable();

            // Always set explicitly by the routing service; the default exists
            // because MariaDB's strict mode refuses a NOT NULL timestamp
            // without one, and "now" is the only correct fallback for a leg
            // that has just been opened.
            $table->timestamp('sent_at')->useCurrent();

            // Written exactly once, enforced in the model. A correction is a
            // new entry with a remark, never a rewrite of this timestamp — that
            // is precisely what makes the ledger evidence rather than notes.
            $table->timestamp('received_at')->nullable();

            // The account that recorded the receipt.
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();

            // Who actually took custody. For a system receipt this is the same
            // person. For a manual one it is the name signed on the printed
            // transmittal by someone who has no account at all — which is the
            // normal case while only one office has onboarded.
            $table->string('received_by_name')->nullable();

            $table->string('receipt_method', 20)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->unique(['document_id', 'seq']);

            // Inbox: pending legs addressed to my office.
            $table->index(['to_department_id', 'status']);
            // Outbox: legs my office sent that nobody has signed for yet.
            $table->index(['from_department_id', 'status']);
            $table->index(['status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_routes');
    }
};
