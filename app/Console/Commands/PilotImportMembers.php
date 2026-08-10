<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PilotMemberImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PilotImportMembers extends Command
{
    protected $signature = 'icgu:pilot-import
        {source : Local file path or object key when --disk is supplied}
        {--disk= : Laravel filesystem disk containing the approved CSV}
        {--commit : Commit only when every row validates without conflicts}
        {--approved-by= : Active Secretariat email that approved this import}';

    protected $description = 'Validate or commit a controlled, auditable ICGU member CSV import.';

    public function handle(PilotMemberImportService $imports): int
    {
        $temporary = null;

        try {
            $source = (string) $this->argument('source');
            $path = $source;
            $disk = trim((string) $this->option('disk'));

            if ($disk !== '') {
                if (! array_key_exists($disk, (array) config('filesystems.disks'))) {
                    $this->error("Filesystem disk [{$disk}] is not configured.");
                    return self::FAILURE;
                }

                $contents = Storage::disk($disk)->get($source);
                $temporary = tempnam(sys_get_temp_dir(), 'icgu-pilot-');
                if ($temporary === false || file_put_contents($temporary, $contents) === false) {
                    throw new \RuntimeException('Unable to stage member CSV from private storage.');
                }
                $path = $temporary;
            } elseif (! str_starts_with($source, DIRECTORY_SEPARATOR)) {
                $path = base_path($source);
            }

            $commit = (bool) $this->option('commit');
            $approver = null;

            if ($commit) {
                $email = strtolower(trim((string) $this->option('approved-by')));
                if ($email === '') {
                    $this->error('--approved-by is required in commit mode.');
                    return self::FAILURE;
                }

                $query = User::query()
                    ->whereRaw('lower(email) = ?', [$email])
                    ->where('is_active', true);

                if ((bool) config('production.require_staff_mfa', false)) {
                    $query->whereNotNull('mfa_confirmed_at');
                }

                $approver = $query->first();

                if ($approver === null || ! $approver->hasStaffRole()) {
                    $this->error('Approver must be an active Secretariat account'.((bool) config('production.require_staff_mfa', false) ? ' with confirmed MFA.' : '.'));
                    return self::FAILURE;
                }
            }

            $batch = $imports->import($path, $commit, $approver, $disk !== '' ? $source : null);

            $this->table(
                ['Batch', 'Status', 'Rows', 'Valid', 'Conflicts', 'Errors', 'Imported'],
                [[
                    $batch->uuid,
                    $batch->status,
                    $batch->total_rows,
                    $batch->valid_rows,
                    $batch->conflict_rows,
                    $batch->error_rows,
                    $batch->imported_rows,
                ]],
            );

            $problemRows = $batch->rows()
                ->whereIn('disposition', ['conflict', 'error'])
                ->orderBy('row_number')
                ->limit(20)
                ->get();

            if ($problemRows->isNotEmpty()) {
                $this->newLine();
                $this->table(
                    ['CSV row', 'Registration', 'Disposition', 'Issues'],
                    $problemRows->map(fn ($row): array => [
                        $row->row_number,
                        $row->registration_number ?? '—',
                        $row->disposition,
                        implode(' ', (array) $row->issues),
                    ])->all(),
                );
                $this->warn('No member data was committed because the batch contains conflicts or validation errors.');
                return self::FAILURE;
            }

            if (! $commit) {
                $this->info('Dry-run passed. Review this batch, then repeat with --commit --approved-by=<staff-email>.');
                return self::SUCCESS;
            }

            if ($batch->status !== 'committed') {
                $this->error('Member import did not reach committed state.');
                return self::FAILURE;
            }

            $this->info('Controlled member import committed successfully.');
            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error((string) $message);
                }
            }
            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        } finally {
            if ($temporary !== null && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
