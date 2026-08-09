<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class FinancialDocumentNumberService
{
    public function invoice(?int $year = null): string
    {
        return $this->next('invoice', 'ICGU/INV/%05d/%04d', $year);
    }

    public function receipt(?int $year = null): string
    {
        return $this->next('receipt', 'ICGU/RCT/%05d/%04d', $year);
    }

    private function next(string $type, string $format, ?int $year): string
    {
        if (! in_array($type, ['invoice', 'receipt'], true)) {
            throw new InvalidArgumentException('Unsupported financial document type.');
        }

        $year ??= (int) now()->format('Y');

        return DB::transaction(function () use ($type, $format, $year): string {
            $sequence = DB::table('financial_document_sequences')
                ->where('document_type', $type)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                DB::table('financial_document_sequences')->insert([
                    'document_type' => $type,
                    'year' => $year,
                    'last_sequence' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $next = 1;
            } else {
                $next = (int) $sequence->last_sequence + 1;
                DB::table('financial_document_sequences')
                    ->where('id', $sequence->id)
                    ->update(['last_sequence' => $next, 'updated_at' => now()]);
            }

            return sprintf($format, $next, $year);
        });
    }
}
