<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();

            // Appears in tracking numbers (BGB-MO-2026-08-0001), so it must be
            // short, stable and unique. Changing a code after documents exist
            // would orphan the numbering, hence it is treated as immutable.
            $table->string('code', 12)->unique();

            $table->string('name');                       // Office of the Municipal Mayor
            $table->string('short_name', 60)->nullable(); // Mayor's Office
            $table->foreignId('parent_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('head_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Onboarded = staff of this office actually log in and act in the
            // system. Non-onboarded offices are still valid routing targets;
            // their legs are recorded with receipt_method 'manual' (someone
            // signed a printed slip). Onboarding later is a flag flip, not a
            // migration. This is what lets the Mayor's Office pilot alone.
            $table->boolean('is_onboarded')->default(false);

            // External = not part of the municipal government at all
            // (provincial offices, national agencies, barangays, private
            // parties). Origin and destination only; never gets logins.
            $table->boolean('is_external')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_external', 'is_onboarded']);
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
