<?php

namespace Tests\Feature\Documents;

use App\Models\Department;
use App\Models\DocumentCounter;
use App\Services\TrackingNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Proof that the counter lock is real.
 *
 * This is the reason the test suite runs on MariaDB rather than SQLite. On
 * SQLite these assertions would pass without exercising anything — there are no
 * row locks to take — and the suite would go green while two clerks pressing
 * Register in the same second were quietly issued the same control number in
 * production.
 *
 * DatabaseMigrations rather than RefreshDatabase: RefreshDatabase wraps each
 * test in a transaction, and a second connection cannot see, let alone contend
 * for, rows that have not been committed. That makes these tests slower than
 * the rest of the suite, which is the price of testing the thing itself instead
 * of a simulation of it.
 */
class TrackingNumberConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    /**
     * Outside a transaction MySQL runs in autocommit and releases the lock the
     * instant the SELECT returns, so the lock would appear to work while
     * protecting nothing. Failing loudly is the only safe behaviour.
     */
    public function test_a_number_cannot_be_issued_outside_a_transaction(): void
    {
        $office = Department::factory()->create(['code' => 'MO']);

        $this->assertSame(0, DB::transactionLevel(), 'Precondition: no ambient transaction.');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('inside a transaction');

        app(TrackingNumberGenerator::class)->next($office);
    }

    /**
     * A second connection standing in for another clerk's request is made to
     * wait on the same counter row. It times out, which is exactly what it
     * should do: it will get its own number a moment later, and a different one.
     */
    public function test_a_second_request_is_made_to_wait_for_the_counter_row(): void
    {
        $office = Department::factory()->create(['code' => 'MO']);
        $now = now()->setTimezone(ph_tz());

        // Committed up front so the probe has a real row to contend for rather
        // than an invisible uncommitted one.
        DocumentCounter::create([
            'department_id' => $office->id,
            'year' => $now->year,
            'month' => $now->month,
            'last_seq' => 0,
        ]);

        config(['database.connections.probe' => config('database.connections.mysql')]);
        $probe = DB::connection('probe');
        $probe->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();

        try {
            $issued = app(TrackingNumberGenerator::class)->next($office);
            $this->assertSame('BGB-MO-'.$now->format('Y-m').'-0001', $issued);

            $blocked = false;

            try {
                $probe->table('document_counters')
                    ->where('department_id', $office->id)
                    ->where('year', $now->year)
                    ->where('month', $now->month)
                    ->lockForUpdate()
                    ->get();
            } catch (QueryException $e) {
                $blocked = str_contains($e->getMessage(), 'Lock wait timeout');
            }

            $this->assertTrue(
                $blocked,
                'The counter row was not locked. Two clerks registering at the same '
                .'moment would be issued the same tracking number.',
            );
        } finally {
            DB::rollBack();
            $probe->disconnect();
        }
    }

    /**
     * The reserved sequence is released if the registration it belonged to is
     * rolled back, so an abandoned attempt does not silently burn a number.
     */
    public function test_an_abandoned_transaction_does_not_consume_a_number(): void
    {
        $office = Department::factory()->create(['code' => 'MO']);
        $generator = app(TrackingNumberGenerator::class);

        DB::beginTransaction();
        $this->assertSame('0001', substr($generator->next($office), -4));
        DB::rollBack();

        DB::beginTransaction();
        $this->assertSame('0001', substr($generator->next($office), -4));
        DB::commit();
    }
}
