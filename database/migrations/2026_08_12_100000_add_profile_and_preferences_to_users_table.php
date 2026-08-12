<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A contact number for the person, not the office. Routing slips
            // and handover questions ("who received this?") are settled on the
            // phone far more often than by email in a municipal hall.
            $table->string('phone', 32)->nullable()->after('position');

            /*
             * How this employee has asked to be shown things: date format,
             * rows per page, which screen they land on, whether they want the
             * morning digest.
             *
             * One JSON column rather than a column per preference. These are
             * display choices with no referential meaning — nothing joins on
             * them, nothing reports on them, and the set will grow. A column
             * per preference would mean a migration every time somebody wants
             * a new checkbox. App\Support\UserPreferences owns the shape, the
             * defaults, and the validation; the database only stores the bag.
             */
            $table->json('preferences')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'preferences']);
        });
    }
};
