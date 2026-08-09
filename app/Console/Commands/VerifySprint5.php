<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\LookupStatus;
use App\Models\Member;
use App\Models\MembershipPlan;
use App\Models\User;
use App\Services\MemberCredentialService;
use App\Services\MemberPortalService;
use App\Services\MembershipRenewalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class VerifySprint5 extends Command
{
    protected $signature = 'icgu:verify-sprint5';
    protected $description = 'Exercise Sprint 5 member portal, invitation security, self-service and digital credentials.';

    public function handle(MemberPortalService $portal, MemberCredentialService $credentials, MembershipRenewalService $renewals): int
    {
        DB::beginTransaction();

        try {
            $activeStatusId = (int) LookupStatus::query()->where('type', 'membership')->where('code', 'ACTIVE')->value('id');
            $individualPlan = MembershipPlan::query()->where('code', 'individual')->firstOrFail();
            $corporatePlan = MembershipPlan::query()->where('code', 'corporate')->firstOrFail();
            $actor = User::query()->create([
                'name' => 'Sprint Five Membership Officer',
                'email' => 'sprint5.officer@prototype.invalid',
                'password' => 'prototype-password',
                'is_active' => true,
            ]);

            $member = new Member();
            $member->forceFill([
                'registration_number' => 'ICGU/995/2099',
                'type' => 'individual',
                'title' => 'Ms',
                'first_name' => 'Portal',
                'last_name' => 'Member',
                'phone' => '+256700005005',
                'job_title' => 'Director',
                'registration_date' => today()->subMonth()->toDateString(),
                'status_id' => $activeStatusId,
                'membership_plan_id' => $individualPlan->id,
                'is_archived' => false,
            ])->save();
            $member->emails()->create([
                'email' => 'sprint5.member@prototype.invalid',
                'email_type' => 'personal',
                'is_primary' => true,
                'is_active' => true,
            ]);
            $start = today()->subMonth();
            $end = $start->addYear()->subDay();
            $member->periods()->create([
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'target_year' => (int) $start->format('Y'),
                'is_backdated' => false,
                'is_future' => false,
                'created_by' => $actor->id,
            ]);

            $issued = $portal->issueInvitation($member, 'sprint5.member@prototype.invalid', 'owner', $actor, 24);
            if ($issued['invitation']->token_hash === $issued['token'] || $issued['invitation']->expires_at->isPast()) {
                throw new \RuntimeException('Portal invitation token hashing or expiry failed.');
            }

            $account = $portal->acceptInvitation($issued['token'], 'Portal Member', 'Secure-Portal-Password-2026');
            if ($account->member_id !== $member->id || ! $account->user->hasRole('member') || $account->user->email_verified_at === null) {
                throw new \RuntimeException('Portal invitation acceptance did not create the expected member account link.');
            }

            $second = $portal->issueInvitation($member, 'sprint5.member@prototype.invalid', 'owner', $actor, 24);
            $wrongPasswordRejected = false;
            try {
                $portal->acceptInvitation($second['token'], 'Portal Member', 'wrong-password');
            } catch (ValidationException) {
                $wrongPasswordRejected = true;
            }
            if (! $wrongPasswordRejected) {
                throw new \RuntimeException('Existing portal account was linked without validating its password.');
            }
            $portal->acceptInvitation($second['token'], 'Portal Member', 'Secure-Portal-Password-2026');

            $updated = $portal->updateProfile($account->user, $member, [
                'phone' => '+256700005999',
                'job_title' => 'Board Director',
                'organization' => 'Prototype Governance Ltd',
            ]);
            if ($updated->phone !== '+256700005999' || $updated->job_title !== 'Board Director') {
                throw new \RuntimeException('Member self-service profile update failed.');
            }

            $card = $credentials->issue($member->refresh(), $account->user);
            if ($card->credential_type !== 'card' || ! $card->valid_until->isSameDay($end)) {
                throw new \RuntimeException('Individual digital membership card validity is incorrect.');
            }
            $svg = $credentials->renderSvg($member->refresh(), $card);
            if (! str_contains($svg, $card->verification_code) || ! str_contains($svg, 'MEMBERSHIP CARD')) {
                throw new \RuntimeException('Digital membership card SVG did not contain verification data.');
            }
            if ($credentials->issue($member->refresh(), $account->user)->id !== $card->id) {
                throw new \RuntimeException('Credential issuance was not idempotent for unchanged coverage.');
            }

            $renewal = $renewals->ensureRenewal($member->refresh(), $account->user);
            if ((float) $renewal->renewal_fee !== 100000.0 || $renewal->invoice === null) {
                throw new \RuntimeException('Member self-service renewal initiation failed.');
            }

            $otherMember = new Member();
            $otherMember->forceFill([
                'registration_number' => 'ICGU/994/2099',
                'type' => 'corporate',
                'company_name' => 'Sprint Five Corporate Prototype Ltd',
                'registration_date' => today()->subMonth()->toDateString(),
                'status_id' => $activeStatusId,
                'membership_plan_id' => $corporatePlan->id,
                'is_archived' => false,
            ])->save();
            $otherMember->periods()->create([
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'target_year' => (int) $start->format('Y'),
                'is_backdated' => false,
                'is_future' => false,
                'created_by' => $actor->id,
            ]);

            $blocked = false;
            try {
                $portal->assertAccess($account->user, $otherMember);
            } catch (HttpException $e) {
                $blocked = $e->getStatusCode() === 403;
            }
            if (! $blocked) {
                throw new \RuntimeException('Portal user could access an unlinked membership.');
            }

            $corporateInvitation = $portal->issueInvitation($otherMember, 'sprint5.corporate@prototype.invalid', 'representative', $actor, 24);
            $corporateAccount = $portal->acceptInvitation($corporateInvitation['token'], 'Corporate Representative', 'Corporate-Portal-Password-2026');
            $certificate = $credentials->issue($otherMember->refresh(), $corporateAccount->user);
            if ($certificate->credential_type !== 'certificate' || ! str_contains($credentials->renderSvg($otherMember, $certificate), 'Membership Certificate')) {
                throw new \RuntimeException('Corporate membership certificate generation failed.');
            }

            $this->info('Sprint 5 member portal, secure account linking, self-service renewal and digital credentials verified successfully.');
            return self::SUCCESS;
        } finally {
            DB::rollBack();
        }
    }
}
