<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Member;
use App\Services\MemberPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class StaffMemberPortalController extends Controller
{
    public function __construct(private readonly MemberPortalService $portal) {}

    public function show(Member $member): JsonResponse
    {
        $member->load(['portalAccounts.user', 'portalInvitations', 'credentials']);

        return response()->json(['data' => [
            'member_id' => $member->id,
            'registration_number' => $member->registration_number,
            'accounts' => $member->portalAccounts,
            'invitations' => $member->portalInvitations,
            'credentials' => $member->credentials,
        ]]);
    }

    public function invite(Request $request, Member $member): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'access_role' => ['required', Rule::in(['owner', 'representative', 'billing'])],
            'ttl_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $result = $this->portal->issueInvitation(
            $member,
            $validated['email'],
            $validated['access_role'],
            $request->user(),
            (int) ($validated['ttl_hours'] ?? 72),
        );

        return response()->json(['data' => [
            'invitation' => $result['invitation'],
            'token' => $result['token'],
            'acceptance_path' => '/member/invitations/'.$result['token'].'/accept',
            'token_notice' => 'The plaintext invitation token is returned only at issuance. Deliver it securely to the intended recipient.',
        ]], 201);
    }
}
