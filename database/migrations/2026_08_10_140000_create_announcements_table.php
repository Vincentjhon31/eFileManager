<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            $table->string('title');
            $table->string('slug')->unique();
            $table->string('category', 20)->default('notice');

            // Shown in listings. Kept separate from the body so a long notice
            // does not get truncated mid-sentence on the front page.
            $table->string('excerpt', 400)->nullable();
            $table->text('body');

            /*
             * Null means it has never been published — a draft, invisible to
             * the public. Publishing is a separate, deliberate act with its own
             * confirmation and its own audit entry, never a checkbox somebody
             * ticks by accident while editing the wording.
             */
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();

            // Taken down again. The row and its history stay: a municipality
            // needs to be able to say what it published and when it stopped.
            $table->timestamp('unpublished_at')->nullable();
            $table->foreignId('unpublished_by')->nullable()->constrained('users')->nullOnDelete();

            // For notices that stop being true: a suspension of work, an
            // invitation to bid whose deadline has passed.
            $table->timestamp('expires_at')->nullable();

            $table->boolean('is_pinned')->default(false);

            // The office that issued it, and the person who typed it. The
            // public page shows the office — a notice is issued by the
            // municipality, not by a named clerk.
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['published_at', 'is_pinned']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
