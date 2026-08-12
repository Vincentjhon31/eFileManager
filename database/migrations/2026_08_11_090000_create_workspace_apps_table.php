<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_apps', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Where it lives. Always external for now — no bytes pass through
            // this system, so there is nothing here for FileStorageService to
            // be responsible for.
            $table->string('url');

            // A single letter or two, shown as the app's badge — the same
            // text-and-color language the rest of the app uses instead of an
            // icon set.
            $table->string('icon_glyph', 2)->nullable();

            $table->string('status', 20)->default('pilot');
            $table->string('scope', 20)->default('department');

            // The office that runs the app. Nullable because an
            // organization-wide or public app does not always have one
            // office to attribute it to, not because scope depends on it —
            // scope alone decides who may see the row.
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope', 'status']);
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_apps');
    }
};
