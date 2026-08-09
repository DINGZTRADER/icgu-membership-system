<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\MembershipPlan;
use Illuminate\Database\Seeder;

final class MembershipPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'code' => 'individual',
                'name' => 'Individual Membership',
                'audience' => 'individual',
                'first_year_fee' => 150000,
                'renewal_fee' => 100000,
                'requires_legal_entity' => false,
                'requirements' => [
                    'application_documents' => ['cv' => 1, 'passport_photo' => 2],
                    'primary_representative_documents' => [],
                ],
            ],
            [
                'code' => 'student',
                'name' => 'Student Membership',
                'audience' => 'student',
                'first_year_fee' => 50000,
                'renewal_fee' => 50000,
                'requires_legal_entity' => false,
                'requirements' => [
                    'application_documents' => ['student_evidence' => 1, 'passport_photo' => 2],
                    'primary_representative_documents' => [],
                ],
            ],
            [
                'code' => 'ngo-corporate',
                'name' => 'NGO Corporate Membership',
                'audience' => 'corporate',
                'first_year_fee' => 1000000,
                'renewal_fee' => 1000000,
                'requires_legal_entity' => true,
                'requirements' => [
                    'application_documents' => ['company_profile' => 1, 'registration_certificate' => 1],
                    'primary_representative_documents' => ['cv' => 1, 'passport_photo' => 2],
                ],
            ],
            [
                'code' => 'corporate',
                'name' => 'Corporate Membership',
                'audience' => 'corporate',
                'first_year_fee' => 2000000,
                'renewal_fee' => 2000000,
                'requires_legal_entity' => true,
                'requirements' => [
                    'application_documents' => ['company_profile' => 1, 'registration_certificate' => 1],
                    'primary_representative_documents' => ['cv' => 1, 'passport_photo' => 2],
                ],
            ],
            [
                'code' => 'sme-corporate',
                'name' => 'SME Corporate Membership',
                'audience' => 'corporate',
                'first_year_fee' => 1000000,
                'renewal_fee' => 1000000,
                'requires_legal_entity' => true,
                'requirements' => [
                    'application_documents' => ['company_profile' => 1, 'registration_certificate' => 1],
                    'primary_representative_documents' => ['cv' => 1, 'passport_photo' => 2],
                ],
            ],
        ];

        foreach ($plans as $plan) {
            MembershipPlan::query()->updateOrCreate(
                ['code' => $plan['code']],
                [...$plan, 'currency' => 'UGX', 'is_active' => true],
            );
        }
    }
}
