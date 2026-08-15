<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where each office stands in the compound.
     *
     * The public town has no coordinates anywhere: it is a row, and its layout
     * is the order of an array in PHP. The compound is a map, and a map needs
     * somewhere to put things — so this table exists, and it is the first thing
     * in this system whose contents are a picture rather than a record.
     *
     * A row with a department is that office's building. A row without one is
     * scenery: the gate, the flagpole, a tree. Both are placed the same way and
     * dragged the same way, because from the point of view of somebody
     * arranging the compound there is no difference between them.
     *
     * Grid cells, never pixels. The renderer decides how wide a cell is on
     * screen — that is a drawing decision and it has changed twice already —
     * and a table full of pixel offsets would have to be migrated every time.
     */
    public function up(): void
    {
        Schema::create('compound_buildings', function (Blueprint $table) {
            $table->id();

            // One building per office, and the office may exist without one:
            // a newly created department has no place in the compound until
            // somebody puts it somewhere.
            $table->foreignId('department_id')->nullable()->unique()
                ->constrained()->cascadeOnDelete();

            // Names a draw function in resources/js/compound.js. 'office' is
            // the parameterised one every department uses; the rest are one-off
            // scenery.
            $table->string('sprite', 24)->default('office');

            // The near corner of the footprint, in grid cells.
            $table->unsignedTinyInteger('gx');
            $table->unsignedTinyInteger('gy');

            // How many cells it covers, and how far it is extruded upward in
            // art pixels. Height is what makes the Sangguniang Bayan read as
            // bigger than the Civil Registrar without anybody being told.
            $table->unsignedTinyInteger('w')->default(2);
            $table->unsignedTinyInteger('h')->default(2);
            $table->unsignedSmallInteger('height')->default(26);

            // Two colours, and the renderer derives every face from them.
            $table->char('wall', 7)->default('#ede3d2');
            $table->char('roof', 7)->default('#2e7d7b');

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['gx', 'gy']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compound_buildings');
    }
};
