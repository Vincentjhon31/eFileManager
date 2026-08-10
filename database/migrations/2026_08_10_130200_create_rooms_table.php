<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('floor_id')->constrained()->cascadeOnDelete();

            $table->string('room_no', 16)->nullable();  // 201
            $table->string('name');                     // Mayor's Office
            $table->string('type', 20)->default('office');

            /*
             * Which office works here. Nullable, and it matters that it is:
             * comfort rooms and stairwells belong to nobody, and a room whose
             * occupant has not been confirmed should show as unassigned rather
             * than be quietly attached to the wrong office.
             */
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The id of this room's shape in the floor's SVG, e.g.
             * "room-mayors-office". This one string is the entire coupling
             * between the drawing and the database.
             */
            $table->string('svg_shape_id', 64)->nullable();

            /*
             * Where the badge sits, as a percentage of the drawing's width and
             * height. Percentages rather than pixels so the badge stays on the
             * door at any size, on a phone or a projector, without the
             * application knowing anything about the viewBox.
             */
            $table->decimal('centroid_x', 5, 2)->nullable();
            $table->decimal('centroid_y', 5, 2)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['floor_id', 'svg_shape_id']);
            $table->index(['floor_id', 'sort_order']);
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};
