<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A photograph of a place in the town, shown when somebody clicks it.
     *
     * The drawn world is a drawing. Clicking the covered court should show the
     * covered court — the real one, with the real roof and the real paint — and
     * that means the one thing the renderer has never had: an image somebody
     * uploaded.
     *
     * Same shape as public_files, and for the same reason: **this row is the
     * capability, not the file**. The public route takes a landmark_photos id
     * and nothing else, so there is no parameter on the welcome page that a
     * stranger could walk from a file in an office's drive to the public web.
     * Removing a photo is deleting this row; the bytes stay in the drive where
     * their office can still find them.
     *
     * Unlike public_files there is no unique key on file_id. A disclosure
     * published twice would give the public two links disagreeing about when it
     * was disclosed, which is a real problem; the same photograph shown at both
     * the plaza and the covered court is just a photograph of both.
     */
    public function up(): void
    {
        Schema::create('landmark_photos', function (Blueprint $table) {
            $table->id();

            // The landmark's id in App\Support\World — 'court', 'beach'. A
            // string rather than a foreign key because the town is a list in
            // PHP, not a table: renaming a landmark is a code change, and
            // photos for an id nobody draws any more are simply never asked
            // for. Cheaper than a table whose rows must be kept in step with an
            // array in a class.
            $table->string('landmark', 40);

            $table->foreignId('file_id')->constrained()->cascadeOnDelete();

            // Shown under the photo. Null is normal — most photographs of a
            // basketball court do not need explaining.
            $table->string('caption', 200)->nullable();

            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Every read is "the photos of this landmark, in order", which is
            // the whole query the welcome page makes.
            $table->index(['landmark', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landmark_photos');
    }
};
