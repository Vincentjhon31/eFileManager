<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floors', function (Blueprint $table) {
            $table->id();

            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');   // 1, 2, 3
            $table->string('name');                 // Second floor
            $table->string('slug', 40);             // second-floor

            /*
             * Path to the drawing, relative to resources/svg/.
             *
             * The file is a plain SVG with an id on each room shape and nothing
             * else — no classes the application depends on, no inline styles,
             * no script. It is inlined into the page and coloured by a
             * generated stylesheet that targets those ids.
             *
             * Nothing here parses the geometry. That is the point: a draughtsman
             * can redraw this floor from scratch, and as long as the ids survive,
             * no migration, no seeder and no code has to change.
             */
            $table->string('svg_path')->nullable();

            // A floor with no usable drawing yet still lists its rooms.
            $table->boolean('has_map')->default(false);

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['building_id', 'level']);
            $table->unique(['building_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floors');
    }
};
