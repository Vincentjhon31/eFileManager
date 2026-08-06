<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_no', 32)->nullable()->unique()->after('id');
            $table->foreignId('department_id')->nullable()->after('email')->constrained()->nullOnDelete();
            $table->string('position')->nullable()->after('department_id'); // Administrative Officer IV

            // Google sign-in is optional and always secondary: an administrator
            // creates the account first, the employee may then link Google to
            // it. There is no self-registration, so a Google identity can never
            // create a user on its own.
            $table->string('google_id')->nullable()->unique()->after('position');

            // Deactivation instead of deletion. Users are referenced by the
            // append-only routing and audit trails, so their rows must survive
            // an employee leaving; only their access is revoked.
            $table->boolean('is_active')->default(true)->after('google_id');

            $table->timestamp('last_login_at')->nullable()->after('is_active');

            $table->index(['department_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['department_id', 'is_active']);
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['employee_no', 'position', 'google_id', 'is_active', 'last_login_at']);
        });
    }
};
