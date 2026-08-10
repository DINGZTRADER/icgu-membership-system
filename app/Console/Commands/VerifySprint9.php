<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\PilotImportBatch;
use App\Models\Role;
use App\Models\User;
use App\Services\PilotMemberImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VerifySprint9 extends Command
{
    protected $signature = 'icgu:verify-sprint9';
    protected $description = 'Verify Sprint 9 controlled pilot import, deduplication and registration-sequence cutover safety.';

    public function handle(PilotMemberImportService $imports): int
    {
        DB::beginTransaction();
        $path = tempnam(sys_get_temp_dir(), 'icgu-s9-');

        try {
            if ($path === false) {
                throw new \RuntimeException('Unable to create Sprint 9 verification file.');
            }

            $role = Role::query()->where('slug', 'finance-officer')->firstOrFail();
            $approver = User::query()->create([
                'name' => 'Sprint Nine Approver',
                'email' => 'sprint9.approver@prototype.invalid',
                'password' => 'prototype-password-strong',
                'is_active' => true,
            ]);
            $approver->forceFill([
                'mfa_secret' => 'SPRINT9TESTSECRET',
                'mfa_recovery_codes' => [],
                'mfa_confirmed_at' => now(),
            ])->save();
            $approver->roles()->attach($role->id);

            $csv = implode(',', PilotMemberImportService::HEADER)."\n"
                .'ICGU/997/2026,individual,student,ACTIVE,Pilot,Individual,,sprint9.individual@prototype.invalid,+256772000091,Makerere University,Student,Student,false,2026-08-01,2026-01-01,2026-12-31,2026'."\n"
                .'ICGU/998/2026,corporate,sme-corporate,ACTIVE,,,Pilot Governance Ltd,sprint9.corporate@prototype.invalid,+256772000092,,Company Secretary,SME,false,2026-08-01,2026-01-01,2026-12-31,2026'."\n";
            file_put_contents($path, $csv);

            $dryRun = $imports->import($path);
            if ($dryRun->status !== 'validated' || $dryRun->valid_rows !== 2 || $dryRun->conflict_rows !== 0 || $dryRun->error_rows !== 0) {
                throw new \RuntimeException('Sprint 9 dry-run did not validate the approved pilot fixture.');
            }
            if (Member::query()->whereIn('registration_number', ['ICGU/997/2026', 'ICGU/998/2026'])->exists()) {
                throw new \RuntimeException('Sprint 9 dry-run unexpectedly wrote member data.');
            }

            $committed = $imports->import($path, true, $approver);
            if ($committed->status !== 'committed' || $committed->imported_rows !== 2) {
                throw new \RuntimeException('Sprint 9 pilot batch did not commit exactly two members.');
            }

            $members = Member::query()
                ->whereIn('registration_number', ['ICGU/997/2026', 'ICGU/998/2026'])
                ->with(['primaryEmail', 'currentPeriod', 'membershipPlan'])
                ->get();

            if ($members->count() !== 2 || $members->contains(fn (Member $member): bool =>
                $member->primaryEmail === null || $member->currentPeriod === null || $member->membershipPlan === null
            )) {
                throw new \RuntimeException('Sprint 9 committed members are missing pilot-critical relations.');
            }

            $sequence = (int) DB::table('registration_sequences')->where('year', 2026)->value('last_sequence');
            if ($sequence < 998) {
                throw new \RuntimeException('Pilot import did not advance the 2026 registration sequence.');
            }

            $duplicateBlocked = false;
            try {
                $imports->import($path, true, $approver);
            } catch (ValidationException) {
                $duplicateBlocked = true;
            }
            if (! $duplicateBlocked) {
                throw new \RuntimeException('The exact same pilot source file was allowed to commit twice.');
            }

            if (PilotImportBatch::query()->where('source_sha256', $committed->source_sha256)->where('status', 'committed')->count() !== 1) {
                throw new \RuntimeException('Committed source hash is not uniquely protected.');
            }

            $this->info('Sprint 9 controlled pilot import and cutover safeguards verified successfully.');
            return self::SUCCESS;
        } finally {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
            DB::rollBack();
        }
    }
}
