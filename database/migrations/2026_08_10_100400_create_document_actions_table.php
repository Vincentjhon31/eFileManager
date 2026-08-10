<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The document's own timeline: every act performed on it, in order.
     *
     * This overlaps with audit_logs on purpose, and the reason matters. The
     * audit trail is a system-wide compliance record under RA 10173 — it covers
     * sign-ins and administrative acts too, it grows without bound, and it will
     * eventually be rotated or archived for volume. A document's chain of
     * custody is part of the record itself under RA 9470 and must never be
     * rotated away from the document it belongs to.
     *
     * Both are written through DocumentRoutingService, in one place, so they
     * cannot drift apart.
     *
     * document_routes holds the *state* of each handover; this table holds the
     * chronological narrative, including the acts that are not handovers at all
     * (registered, assigned, completed, archived).
     */
    public function up(): void
    {
        Schema::create('document_actions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')->constrained()->cascadeOnDelete();

            // Set for acts that concern a specific leg (released, received,
            // recalled); null for the rest.
            $table->foreignId('document_route_id')->nullable()
                ->constrained('document_routes')->nullOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Denormalised for the same reason as in audit_logs: when an
            // employee transfers or leaves, the timeline must still say who did
            // this and which office they were standing in at the time.
            $table->string('actor_name')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->string('action', 40);
            $table->text('remarks')->nullable();
            $table->json('meta')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Append-only: there is no updated_at because a row here is never
            // updated. See the AppendOnly concern on the model.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_id', 'id']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_actions');
    }
};
