<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Member;
use App\Models\MemberPortalAccount;
use App\Models\MemberPortalInvitation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MemberPortalService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return array{invitation: MemberPortalInvitation, token: string} */
    public function issueInvitation(Member $member, string $email, string $accessRole, User $actor, int $ttlHours = 72): array
    {
        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'A valid email address is required.']);
        }
        if (! in_array($accessRole, ['owner', 'representative', 'billing'], true)) {
            throw ValidationException::withMessages(['access_role' => 'Unsupported portal access role.']);
        }

        return DB::transaction(function () use ($member, $email, $accessRole, $actor, $ttlHours): array {
            MemberPortalInvitation::query()
                ->where('member_id', $member->id)
                ->where('email', $email)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $token = Str::random(64);
            $invitation = new MemberPortalInvitation();
            $invitation->forceFill([
                'member_id' => $member->id,
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'access_role' => $accessRole,
                'expires_at' => now()->addHours(max(1, min($ttlHours, 168))),
                'created_by' => $actor->id,
            ])->save();

            $this->audit->record('member_portal_invitation_issued', $invitation, after: [
                'member_id' => $member->id,
                'email' => $email,
                'access_role' => $accessRole,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ]);

            return ['invitation' => $invitation, 'token' => $token];
        });
    }

    public function acceptInvitation(string $token, string $name, string $password): MemberPortalAccount
    {
        return DB::transaction(function () use ($token, $name, $password): MemberPortalAccount {
            $invitation = MemberPortalInvitation::query()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! $invitation->is_usable) {
                throw ValidationException::withMessages(['invitation' => 'This portal invitation is invalid, expired, accepted, or revoked.']);
            }

            $user = User::query()->where('email', $invitation->email)->lockForUpdate()->first();
            if ($user !== null) {
                if (! Hash::check($password, $user->password)) {
                    throw ValidationException::withMessages(['password' => 'This email already has an account. Enter its existing password to link this membership.']);
                }
                if (! $user->is_active) {
                    throw ValidationException::withMessages(['account' => 'This account is inactive. Contact the ICGU Secretariat.']);
                }
            } else {
                $user = new User();
                $user->forceFill([
                    'name' => trim($name),
                    'email' => $invitation->email,
                    'password' => $password,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ])->save();
            }

            $memberRole = Role::query()->where('slug', 'member')->firstOrFail();
            $user->roles()->syncWithoutDetaching([$memberRole->id]);

            $hasPrimary = MemberPortalAccount::query()
                ->where('member_id', $invitation->member_id)
                ->where('is_primary', true)
                ->exists();

            $account = MemberPortalAccount::query()->firstOrCreate(
                ['member_id' => $invitation->member_id, 'user_id' => $user->id],
                [
                    'access_role' => $invitation->access_role,
                    'is_primary' => ! $hasPrimary,
                    'linked_at' => now(),
                    'linked_by' => $invitation->created_by,
                ],
            );

            $invitation->forceFill(['accepted_at' => now()])->save();

            $this->audit->record('member_portal_invitation_accepted', $account, after: [
                'member_id' => $account->member_id,
                'user_id' => $account->user_id,
                'access_role' => $account->access_role,
            ]);

            return $account->load(['member.status', 'member.membershipPlan', 'user']);
        });
    }

    public function assertAccess(User $user, Member $member): MemberPortalAccount
    {
        $account = MemberPortalAccount::query()
            ->where('user_id', $user->id)
            ->where('member_id', $member->id)
            ->first();

        if ($account === null) {
            abort(403, 'You do not have access to this membership.');
        }

        return $account;
    }

    public function updateProfile(User $user, Member $member, array $attributes): Member
    {
        $this->assertAccess($user, $member);
        $before = $member->only(['phone', 'job_title', 'organization']);

        $allowed = ['phone'];
        if ($member->type === 'individual') {
            $allowed = ['phone', 'job_title', 'organization'];
        }

        $member->fill(array_intersect_key($attributes, array_flip($allowed)))->save();

        $this->audit->record('member_self_service_profile_updated', $member, before: $before, after: $member->only($allowed));

        return $member->refresh();
    }
}
