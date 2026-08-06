<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards the test environment itself. The routing engine relies on
 * transactional row locking, so the suite must run against the same engine as
 * production. If someone reverts phpunit.xml to SQLite, this fails loudly
 * rather than letting concurrency tests pass on semantics that do not exist.
 */
class DatabaseEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_tests_run_against_mysql_or_mariadb(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_migrations_apply_cleanly(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('roles'));
        $this->assertTrue(Schema::hasTable('permissions'));
    }

    public function test_row_locking_is_supported(): void
    {
        // Proves SELECT ... FOR UPDATE parses and executes on this engine.
        // The tracking-number generator depends on it.
        DB::transaction(function () {
            $rows = DB::table('users')->lockForUpdate()->get();

            $this->assertCount(0, $rows);
        });
    }
}
