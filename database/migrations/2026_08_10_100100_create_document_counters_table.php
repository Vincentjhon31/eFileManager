<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per office per month, holding the last sequence number issued.
     *
     * This table exists so that tracking numbers are never derived from
     * MAX(tracking_no) + 1. Two clerks pressing Register in the same second
     * would both read the same maximum and both be handed the same number —
     * and a duplicate control number on a government document is the kind of
     * error that surfaces months later in an audit, attached to the wrong file.
     *
     * Instead the row is locked with SELECT ... FOR UPDATE, incremented, and
     * released when the transaction commits. The second clerk waits a few
     * milliseconds and gets the next number.
     */
    public function up(): void
    {
        Schema::create('document_counters', function (Blueprint $table) {
            $table->id();

            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedInteger('last_seq')->default(0);
            $table->timestamps();

            // The unique key is what makes the create-if-missing race safe:
            // when two registrations both find no counter for a new month,
            // exactly one insert wins and the other re-reads the winner's row.
            $table->unique(['department_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_counters');
    }
};
