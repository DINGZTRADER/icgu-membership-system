<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\MemberCredential;
use Illuminate\Http\JsonResponse;

final class PublicMembershipVerificationController extends Controller
{
    public function show(string $verificationCode): JsonResponse
    {
        $credential = MemberCredential::query()
            ->where('verification_code', $verificationCode)
            ->with(['member.status', 'member.membershipPlan', 'member.organisation'])
            ->firstOrFail();

        $member = $credential->member;
        $valid = $credential->is_valid && $member->status?->code === 'ACTIVE';

        return response()->json(['data' => [
            'valid' => $valid,
            'credential_type' => $credential->credential_type,
            'verification_code' => $credential->verification_code,
            'member_name' => $member->display_name,
            'registration_number' => $member->registration_number,
            'membership_category' => $member->membershipPlan?->name,
            'membership_status' => $member->status?->code,
            'valid_from' => $credential->valid_from?->toDateString(),
            'valid_until' => $credential->valid_until?->toDateString(),
            'revoked' => $credential->revoked_at !== null,
        ]]);
    }
}
