<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();

            $table->string('code', 16)->unique();  // MEMO, OO, TO, DV
            $table->string('name');                // Memorandum
            $table->string('description')->nullable();

            // Pre-selected on the registration form. A travel order is almost
            // always for signature; a memorandum is usually for information.
            // Saves a click on the screen clerks use most.
            $table->string('default_action', 40)->nullable();

            // RA 9470 (National Archives Act) retention period. Drives the
            // disposal report in Phase 7. Nothing is ever auto-deleted.
            //
            // NULL means permanent retention — ordinances, resolutions and
            // appointment papers are never disposed of. A sentinel like 0 or
            // 999 would eventually be read as "expired yesterday" by somebody
            // writing the disposal report, so the distinction is a real null.
            $table->unsignedSmallInteger('retention_years')->nullable();

            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
