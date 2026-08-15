<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What the office actually does, in one line.
     *
     * The compound turned every office into a building somebody can walk up to
     * and click, and a panel that can only say "Municipal Assessor's Office —
     * MASSO" is a nameplate rather than a door. Departments had a code, a name
     * and a short name, all of which are the same fact three times.
     *
     * Public, like the office's name: a guest walking the compound reads this.
     * So it is a description of a function, not a note about the people in it.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->string('summary', 200)->nullable()->after('short_name');
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
