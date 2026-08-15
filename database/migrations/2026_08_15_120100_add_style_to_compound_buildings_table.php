<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which design a building is drawn in.
     *
     * `sprite` already said which draw function to call, and for every office
     * that was the same one — so twenty-one buildings differed only in size and
     * two colours, and a compound where every roof is the same shape reads as a
     * chart. This is the second axis: a plain block, a hall with a portico, or
     * a low annex, all from the one parameterised sprite.
     *
     * Named rather than numbered so a style added later does not have to guess
     * which integer is free, and so the value in the row says what it is.
     */
    public function up(): void
    {
        Schema::table('compound_buildings', function (Blueprint $table) {
            $table->string('style', 16)->default('plain')->after('sprite');
        });
    }

    public function down(): void
    {
        Schema::table('compound_buildings', function (Blueprint $table) {
            $table->dropColumn('style');
        });
    }
};
