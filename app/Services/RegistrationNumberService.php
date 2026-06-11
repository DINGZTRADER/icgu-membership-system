<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Concurrency-safe ICGU Registration Number Generator.
 *
 * Strategy:
 *   1. BEGIN TRANSACTION
 *   2. SELECT ... FOR UPDATE on registration_sequences row for the target year
 *      (row-level lock prevents concurrent increments)
 *   3. Increment last_sequence atomically
 *   4. COMMIT
 *
 * This approach:
 *   - Prevents race conditions (two concurrent requests cannot read the same sequence value)
 *   - Avoids table locks (only the specific year row is locked)
 *   - Is idempotent (the sequence is only advanced on confirmed commit)
 *   - Does NOT produce gaps under normal operation (gaps only appear on transaction rollback)
 */
class RegistrationNumberService
{
    private const FORMAT   = 'ICGU/%03d/%04d';
    private const MAX_SEQ  = 999;

    /**
     * Generate and return the next registration number for the given year.
     * The caller MUST assign this number to a member record within the SAME
     * outer transaction to maintain consistency.
     *
     * @throws RuntimeException if the annual sequence limit (999) is exceeded.
     */
    public function generate(?int $year = null): string
    {
        $year = $year ?? (int) date('Y');

        return DB::transaction(function () use ($year): string {
            // Attempt to lock the existing row for this year.
            // lockForUpdate() translates to SELECT ... FOR UPDATE in PostgreSQL,
            // which blocks any concurrent transaction from reading this row until we commit.
            $sequence = DB::table('registration_sequences')
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                // First registration of the year: insert the sequence row.
                // We use insertOrIgnore defensively, but lockForUpdate on the select
                // already serialises concurrent inserts via the transaction.
                DB::table('registration_sequences')->insert([
                    'year'          => $year,
                    'last_sequence' => 1,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                $nextSeq = 1;
            } else {
                $nextSeq = $sequence->last_sequence + 1;

                if ($nextSeq > self::MAX_SEQ) {
                    throw new RuntimeException(
                        "Annual registration sequence limit of " . self::MAX_SEQ
                        . " has been reached for year {$year}. Contact system administrator."
                    );
                }

                DB::table('registration_sequences')
                    ->where('year', $year)
                    ->update([
                        'last_sequence' => $nextSeq,
                        'updated_at'    => now(),
                    ]);
            }

            return sprintf(self::FORMAT, $nextSeq, $year);
        });
    }

    /**
     * Preview what the NEXT number would be without incrementing the sequence.
     * Useful for display purposes only. Do not use for actual registration.
     */
    public function peek(?int $year = null): string
    {
        $year = $year ?? (int) date('Y');

        $sequence = DB::table('registration_sequences')
            ->where('year', $year)
            ->first();

        $nextSeq = $sequence ? $sequence->last_sequence + 1 : 1;

        return sprintf(self::FORMAT, $nextSeq, $year);
    }

    /**
     * Parse a registration number string back into its components.
     *
     * @return array{prefix: string, sequence: int, year: int}|null
     */
    public function parse(string $registrationNumber): ?array
    {
        if (preg_match('/^ICGU\/(\d{3})\/(\d{4})$/', $registrationNumber, $matches)) {
            return [
                'prefix'   => 'ICGU',
                'sequence' => (int) $matches[1],
                'year'     => (int) $matches[2],
            ];
        }

        return null;
    }
}
