<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();

            // A folder belongs to exactly one office and never moves between
            // them. Records belong to the office accountable for them, and a
            // folder that could be handed over would make that unanswerable.
            $table->foreignId('department_id')->constrained()->restrictOnDelete();

            $table->foreignId('parent_id')->nullable()->constrained('folders')->cascadeOnDelete();
            $table->string('name');
            $table->string('visibility', 20)->default('department');

            // Created by the system rather than a person — the office's root
            // folder, and the one that receives scans attached to documents.
            // Cannot be renamed or deleted, because other things point at them.
            $table->boolean('is_system')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Not a unique key. MySQL treats each NULL as distinct, so a unique
            // index on (department_id, parent_id, name) would happily allow two
            // root folders with the same name while forbidding two subfolders.
            // Half-enforcement is worse than none: the rule lives in
            // FileStorageService, where it applies at every level.
            $table->index(['department_id', 'parent_id']);
            $table->index('visibility');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folders');
    }
};
