<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which parts of the compound's ground the municipality has taken in.
     *
     * The grid is deliberately about twice the land the offices currently stand
     * on, and handing all of it over at once made the compound look like a
     * mostly-empty car park. So it comes in blocks: nine of the sixteen are
     * open from the start because there are already buildings on them, and the
     * rest are country until somebody in MIS decides otherwise.
     *
     * A row here means unlocked. There is no `is_unlocked` column and no rows
     * for the locked ones, because a table of mostly-false booleans is a table
     * that has to be kept in step with a grid size — and the grid size is a
     * constant that has already changed twice.
     *
     * Never unlocked by accident and never re-locked: a block with the Health
     * Office standing on it cannot be taken back, so there is no route for it.
     */
    public function up(): void
    {
        Schema::create('compound_districts', function (Blueprint $table) {
            $table->id();

            // Block coordinates, not cells: see App\Support\Compound::DISTRICT
            // for how many cells across one of these is.
            $table->unsignedTinyInteger('dx');
            $table->unsignedTinyInteger('dy');

            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['dx', 'dy']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compound_districts');
    }
};
