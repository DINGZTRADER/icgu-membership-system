<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\PaymentRequest;
use App\Models\PilotImportBatch;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PilotReadinessCheck extends Command
{
    protected $signature = 'icgu:pilot-check {--strict : Treat pilot warnings as blockers}';
    protected $description = 'Check data, staff and operational conditions for a controlled ICGU production pilot.';

    /** @var list<array{level:string,check:string,detail:string}> */
    private array $results = [];

    public function handle(): int
    {
        $strict = (bool) $this->option('strict');

        try {
            DB::select('select 1');
            $this->pass('Database', 'PostgreSQL is reachable.');
        } catch (\Throwable $exception) {
            $this->block('Database', 'Database is unreachable: '.$exception->getMessage());
        }

        $prototypeUsers = User::query()
            ->where(fn ($query) => $query
                ->whereRaw("lower(email) like '%.invalid'")
                ->orWhereRaw("lower(email) like '%.prototype'"))
            ->count();
        $prototypeMembers = DB::table('member_emails')
            ->where(fn ($query) => $query
                ->whereRaw("lower(email) like '%.invalid'")
                ->orWhereRaw("lower(email) like '%.prototype'"))
            ->count();
        $this->check($prototypeUsers + $prototypeMembers === 0, 'Prototype data', 'No .invalid/.prototype accounts may remain in a production pilot.', $strict);

        $committedBatches = PilotImportBatch::query()->where('status', 'committed')->count();
        $this->check($committedBatches > 0, 'Committed pilot import', 'At least one approved pilot import batch should be committed before pilot launch.', $strict);

        $openValidationBatches = PilotImportBatch::query()->where('status', 'validated')->count();
        $this->check($openValidationBatches === 0, 'Uncommitted import batches', 'Review validated-but-uncommitted pilot batches before launch.', false);

        $failedBatches = PilotImportBatch::query()->where('status', 'failed')->count();
        $this->check($failedBatches === 0, 'Failed import batches', 'Review historical failed pilot import batches and confirm the corrected source was used.', false);

        $activeWithoutPlan = Member::query()->active()->whereNull('membership_plan_id')->count();
        $this->check($activeWithoutPlan === 0, 'Active member plans', 'Every active member must have a membership plan.', true);

        $activeWithoutEmail = Member::query()->active()->whereDoesntHave('primaryEmail')->count();
        $this->check($activeWithoutEmail === 0, 'Active member email', 'Every active member must have an active primary email.', true);

        $activeWithoutPeriod = Member::query()->active()->whereDoesntHave('currentPeriod')->count();
        $this->check($activeWithoutPeriod === 0, 'Active membership periods', 'Every active member must have a current membership period.', true);

        foreach (['ceo', 'membership-officer', 'finance-officer'] as $role) {
            $ready = User::query()
                ->where('is_active', true)
                ->whereNotNull('mfa_confirmed_at')
                ->whereHas('roles', fn ($query) => $query->where('slug', $role))
                ->exists();

            $this->check($ready, 'Staff MFA: '.$role, "An active {$role} account must complete MFA before pilot launch.", $strict);
        }

        $manualReconciliation = PaymentRequest::query()->where('status', 'review_required')->count();
        $this->check($manualReconciliation === 0, 'Payment reconciliation queue', 'Finance must clear review_required mobile-money payments before cutover.', $strict);

        $failedJobs = DB::table('failed_jobs')->count();
        $this->check($failedJobs === 0, 'Failed queue jobs', 'Resolve failed background jobs before cutover.', $strict);

        $this->table(
            ['Result', 'Check', 'Detail'],
            array_map(fn (array $result): array => [
                $result['level'],
                $result['check'],
                $result['detail'],
            ], $this->results),
        );

        $blocking = collect($this->results)->contains(fn (array $result): bool => $result['level'] === 'BLOCK');
        if ($blocking) {
            $this->error('ICGU controlled-pilot readiness check failed.');
            return self::FAILURE;
        }

        $this->info('ICGU controlled-pilot readiness checks passed.');
        return self::SUCCESS;
    }

    private function check(bool $condition, string $check, string $detail, bool $blocking): void
    {
        if ($condition) {
            $this->pass($check, 'OK');
            return;
        }

        $blocking ? $this->block($check, $detail) : $this->warnResult($check, $detail);
    }

    private function pass(string $check, string $detail): void
    {
        $this->results[] = ['level' => 'PASS', 'check' => $check, 'detail' => $detail];
    }

    private function warnResult(string $check, string $detail): void
    {
        $this->results[] = ['level' => 'WARN', 'check' => $check, 'detail' => $detail];
    }

    private function block(string $check, string $detail): void
    {
        $this->results[] = ['level' => 'BLOCK', 'check' => $check, 'detail' => $detail];
    }
}
