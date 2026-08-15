<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ground, where somebody has laid something on it.
     *
     * The streets and the plaza used to be a pair of constants in PHP — x is 8
     * or 20, y is 7 or 19 — which drew a perfectly good compound and made it
     * one nobody could change. Paving is a thing you do to a place, so it is
     * data now.
     *
     * Only the cells that are *not* plain grass are stored. A row per cell of
     * the compound as it stands today would be seven hundred and eighty-four
     * rows to say "grass" six hundred times — and the compound has no fixed
     * size at all any more, it grows outwards for as long as anybody keeps
     * taking land into it. An absent row meaning grass survives that. A table
     * sized to the ground does not.
     */
    public function up(): void
    {
        Schema::create('compound_tiles', function (Blueprint $table) {
            $table->id();

            $table->unsignedTinyInteger('x');
            $table->unsignedTinyInteger('y');

            // 'r' path, 'p' paving. Grass is the absence of a row. See
            // App\Support\Compound::GROUNDS for what may be laid.
            $table->string('kind', 2);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['x', 'y']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compound_tiles');
    }
};
