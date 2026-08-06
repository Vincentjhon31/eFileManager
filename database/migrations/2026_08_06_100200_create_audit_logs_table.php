<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail, required under RA 10173 (Data Privacy Act).
 *
 * Deliberately has created_at only and no updated_at: a row that can be
 * updated is not an audit trail. Nothing in the application exposes an update
 * or delete path for this table, at any role. Corrections are recorded as new
 * events describing the correction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Nullable: failed logins and scheduled jobs have no actor.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Denormalised so the trail still reads correctly after an employee
            // transfers to another office. The trail must reflect who they were
            // at the time of the act, not who they are now.
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_name')->nullable();

            $table->string('event', 64);              // user.login, document.received
            $table->nullableMorphs('auditable');      // Document, User, Folder...
            $table->text('description')->nullable();
            $table->json('properties')->nullable();   // before/after, remarks, context

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['event', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
