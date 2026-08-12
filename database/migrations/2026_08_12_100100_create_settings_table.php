<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * System settings an administrator can change without a deployment.
     *
     * Deliberately sparse: this table holds only the handful of values the LGU
     * itself may reasonably need to change — its own name, how large an upload
     * may be, how many backups to keep. Everything structural stays in config
     * files under version control, where a change is reviewed and reversible.
     *
     * Each row overrides one config key at boot (App\Services\SystemSettings),
     * so nothing downstream has to know these are settable: config('drive
     * .max_upload_mb') keeps working and simply returns the LGU's answer.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            // The config key it overrides, e.g. 'drive.max_upload_mb'.
            $table->string('key', 100)->primary();

            // JSON so a setting can be a number, a string, a boolean or a list
            // (allowed file extensions) without a column per shape.
            $table->json('value')->nullable();

            // Who changed it last. The audit trail holds the full history; this
            // is here so the screen can say "changed by X" without a join to it.
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
