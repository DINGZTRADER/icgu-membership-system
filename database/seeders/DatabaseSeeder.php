<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupStatus;
use App\Models\Member;
use App\Models\MemberEmail;
use App\Models\MembershipPeriod;
use App\Models\FinancialLedger;
use App\Services\RegistrationNumberService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Generates purely fictional, anonymous prototype data.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding ICGU prototype with anonymous test data...');
        $this->call(MembershipPlanSeeder::class);

        $regService = app(RegistrationNumberService::class);
        $activeStatusId = LookupStatus::idByCode('ACTIVE');
        $pendingStatusId = LookupStatus::idByCode('PENDING');
        $expiredStatusId = LookupStatus::idByCode('EXPIRED');
        $payPendingId = LookupStatus::idByCode('PAY_PENDING');
        $payPaidId = LookupStatus::idByCode('PAY_PAID');
        $payOverdueId = LookupStatus::idByCode('PAY_OVERDUE');

        $adminUser = \App\Models\User::firstOrCreate(
            ['email' => 'admin@icgu.prototype'],
            ['name' => 'ICGU System Administrator', 'password' => Hash::make('ChangeMe@2026!')],
        );

        $individuals = [
            [
                'type' => 'individual', 'title' => 'Mr', 'first_name' => 'Alpha', 'last_name' => 'Testman',
                'phone' => '+256700000001', 'organization' => 'Fictional Enterprises Ltd', 'job_title' => 'Chief Executive Officer',
                'registration_date' => '2024-01-15', 'status_id' => $activeStatusId, 'email' => 'alpha.testman@prototype.invalid',
                'period_year' => 2025, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
                'invoice_amount' => 500000.0000, 'fee_type' => 'annual_individual', 'inv_status_id' => $payPaidId, 'amount_settled' => 500000.0000,
            ],
            [
                'type' => 'individual', 'title' => 'Dr', 'first_name' => 'Beta', 'last_name' => 'Demouser',
                'phone' => '+256700000002', 'organization' => 'Prototype Holdings Inc', 'job_title' => 'Board Secretary',
                'registration_date' => '2023-06-20', 'status_id' => $expiredStatusId, 'email' => 'beta.demouser@prototype.invalid',
                'period_year' => 2024, 'period_start' => '2024-01-01', 'period_end' => '2024-12-31',
                'invoice_amount' => 500000.0000, 'fee_type' => 'annual_individual', 'inv_status_id' => $payOverdueId, 'amount_settled' => 0.0000,
            ],
            [
                'type' => 'individual', 'title' => 'Ms', 'first_name' => 'Gamma', 'last_name' => 'Sampledata',
                'phone' => '+256700000003', 'organization' => 'Test Corp Uganda', 'job_title' => 'Finance Director',
                'registration_date' => '2025-03-01', 'status_id' => $pendingStatusId, 'email' => 'gamma.sampledata@prototype.invalid',
                'period_year' => 2025, 'period_start' => '2025-03-01', 'period_end' => '2025-12-31',
                'invoice_amount' => 500000.0000, 'fee_type' => 'application', 'inv_status_id' => $payPendingId, 'amount_settled' => 0.0000,
            ],
        ];

        $corporates = [
            [
                'type' => 'corporate', 'company_name' => 'Fictional Bank Uganda Limited', 'industry_code' => '6419',
                'registration_cert' => 'CRP/PROTO/0001/2024', 'phone' => '+256414000001', 'organization' => 'Fictional Bank Uganda Limited',
                'job_title' => null, 'registration_date' => '2024-02-10', 'status_id' => $activeStatusId,
                'email' => 'secretary@fictionalbank.prototype.invalid', 'period_year' => 2025, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
                'invoice_amount' => 2500000.0000, 'fee_type' => 'annual_corporate', 'inv_status_id' => $payPaidId, 'amount_settled' => 2500000.0000,
            ],
            [
                'type' => 'corporate', 'company_name' => 'Demo Insurance Co Uganda', 'industry_code' => '6511',
                'registration_cert' => 'CRP/PROTO/0002/2023', 'phone' => '+256414000002', 'organization' => 'Demo Insurance Co Uganda',
                'job_title' => null, 'registration_date' => '2023-09-05', 'status_id' => $activeStatusId,
                'email' => 'corporate@demoinsurance.prototype.invalid', 'period_year' => 2025, 'period_start' => '2025-01-01', 'period_end' => '2025-12-31',
                'invoice_amount' => 2500000.0000, 'fee_type' => 'annual_corporate', 'inv_status_id' => $payOverdueId, 'amount_settled' => 1250000.0000,
            ],
        ];

        foreach (array_merge($individuals, $corporates) as $data) {
            DB::transaction(function () use ($data, $regService, $payPaidId, $adminUser): void {
                $regNumber = $regService->generate((int) date('Y', strtotime($data['registration_date'])));

                $member = Member::create([
                    'registration_number' => $regNumber,
                    'type' => $data['type'], 'title' => $data['title'] ?? null,
                    'first_name' => $data['first_name'] ?? null, 'last_name' => $data['last_name'] ?? null,
                    'company_name' => $data['company_name'] ?? null, 'industry_code' => $data['industry_code'] ?? null,
                    'registration_cert' => $data['registration_cert'] ?? null, 'phone' => $data['phone'],
                    'organization' => $data['organization'], 'job_title' => $data['job_title'] ?? null,
                    'registration_date' => $data['registration_date'], 'status_id' => $data['status_id'], 'is_archived' => false,
                ]);

                MemberEmail::create([
                    'member_id' => $member->id, 'email' => $data['email'], 'email_type' => 'work',
                    'is_primary' => true, 'is_active' => true, 'verified_at' => now(),
                ]);

                $period = MembershipPeriod::create([
                    'member_id' => $member->id, 'start_date' => $data['period_start'], 'end_date' => $data['period_end'],
                    'target_year' => $data['period_year'], 'is_backdated' => false, 'is_future' => false, 'created_by' => $adminUser->id,
                ]);

                $prototypeKey = strtoupper(substr(md5($data['email']), 0, 8));
                $invoice = FinancialLedger::create([
                    'member_id' => $member->id, 'period_id' => $period->id, 'status_id' => $data['inv_status_id'],
                    'type' => 'invoice', 'invoice_number' => 'ICGU/INV/PROTO/'.$prototypeKey,
                    'fee_type' => $data['fee_type'], 'amount' => $data['invoice_amount'],
                    'amount_settled' => $data['amount_settled'], 'currency' => 'UGX',
                    'due_date' => now()->parse($data['period_start'])->addDays(30),
                    'settled_at' => $data['amount_settled'] >= $data['invoice_amount'] ? now() : null,
                    'created_by' => $adminUser->id, 'tx_reference' => 'PROTO-INV-'.$prototypeKey,
                ]);

                if ((float) $data['amount_settled'] > 0) {
                    FinancialLedger::create([
                        'member_id' => $member->id, 'period_id' => $period->id, 'status_id' => $payPaidId,
                        'type' => 'payment', 'fee_type' => $data['fee_type'], 'amount' => $data['amount_settled'],
                        'amount_settled' => $data['amount_settled'], 'currency' => 'UGX', 'parent_invoice_id' => $invoice->id,
                        'tx_reference' => 'PROTO-PAY-'.strtoupper(substr(md5($data['email'].'pay'), 0, 8)),
                        'settled_at' => now(), 'created_by' => $adminUser->id,
                    ]);
                }

                $this->command->line("  ✓ Created member: {$regNumber} — ".($data['company_name'] ?? "{$data['first_name']} {$data['last_name']}"));
            });
        }

        $this->command->info('✅ Seeding complete. 5 anonymous members created.');
        $this->command->warn('   All data is purely fictional. No real personal information has been used.');
    }
}
