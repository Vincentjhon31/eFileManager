<?php

namespace App\Services;

use App\Models\Department;
use App\Models\DocumentCounter;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Issues control numbers of the form BGB-MO-2026-08-0001.
 *
 *   BGB   municipality code
 *   MO    the office that registered the document
 *   2026  year
 *   08    month
 *   0001  sequence within that office and month
 *
 * A tracking number is written on paper, quoted in follow-up letters and used
 * to find the file years later. Two documents sharing one is not a display bug;
 * it is a records failure that surfaces during an audit, attached to the wrong
 * file. So the number is never derived from the highest one already issued —
 * it comes from a counter row held under a lock.
 */
class TrackingNumberGenerator
{
    /**
     * Reserve the next number for an office.
     *
     * Must be called inside a transaction. Outside one, MySQL runs in
     * autocommit and releases the row lock the instant the SELECT returns,
     * so the lock would appear to work while protecting nothing.
     */
    public function next(Department $office, ?CarbonInterface $at = null): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'Tracking numbers must be issued inside a transaction, otherwise the '
                .'counter lock is released before the document row is written.'
            );
        }

        // Grouped by the Manila calendar month, not UTC. A document registered
        // at 7am on 1 September is 23:00 on 31 August in UTC, and handing that
        // clerk an August number would be wrong in the only way that counts:
        // visibly, on the paper in their hand.
        $local = ($at ?? now())->copy()->setTimezone(ph_tz());
        $year = (int) $local->year;
        $month = (int) $local->month;

        $counter = $this->lockCounter($office, $year, $month);

        $seq = $counter->last_seq + 1;
        $counter->forceFill(['last_seq' => $seq])->save();

        return sprintf(
            '%s-%s-%04d-%02d-%04d',
            config('lgu.code'),
            $office->code,
            $year,
            $month,
            $seq,
        );
    }

    /**
     * Find and lock this office's counter for the month, creating it on the
     * first registration of a new month.
     */
    private function lockCounter(Department $office, int $year, int $month): DocumentCounter
    {
        $locked = fn (): ?DocumentCounter => DocumentCounter::query()
            ->where('department_id', $office->getKey())
            ->where('year', $year)
            ->where('month', $month)
            ->lockForUpdate()
            ->first();

        if ($counter = $locked()) {
            return $counter;
        }

        try {
            DocumentCounter::create([
                'department_id' => $office->getKey(),
                'year' => $year,
                'month' => $month,
                'last_seq' => 0,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two clerks registered the first document of the month at the same
            // moment. The unique key let exactly one insert through; this one
            // simply reads the winner's row below and takes the next number.
        }

        return $locked() ?? throw new RuntimeException(
            "Could not reserve a tracking number for {$office->code} in {$year}-{$month}."
        );
    }
}
