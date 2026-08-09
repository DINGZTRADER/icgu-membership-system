<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Member;
use App\Services\MemberCredentialService;
use App\Services\MemberPortalService;
use App\Services\MembershipRenewalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MemberPortalController extends Controller
{
    public function __construct(
        private readonly MemberPortalService $portal,
        private readonly MembershipRenewalService $renewals,
        private readonly MemberCredentialService $credentials,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $accounts = $request->user()->portalAccounts()
            ->with([
                'member.status',
                'member.membershipPlan',
                'member.currentPeriod',
                'member.latestPeriod',
                'member.latestRenewal.invoice.settlements',
                'member.activeCredential',
            ])
            ->orderByDesc('is_primary')
            ->get();

        return response()->json(['data' => $accounts->map(fn ($account) => [
            'access_role' => $account->access_role,
            'is_primary' => $account->is_primary,
            'member' => $account->member,
            'outstanding_balance' => $account->member->outstanding_balance,
        ])]);
    }

    public function show(Request $request, Member $member): JsonResponse
    {
        $account = $this->portal->assertAccess($request->user(), $member);
        $member->load(['status', 'membershipPlan', 'organisation', 'primaryEmail', 'currentPeriod', 'latestPeriod', 'latestRenewal.invoice.settlements', 'activeCredential']);

        return response()->json(['data' => [
            'member' => $member,
            'access_role' => $account->access_role,
            'outstanding_balance' => $member->outstanding_balance,
        ]]);
    }

    public function updateProfile(Request $request, Member $member): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'organization' => ['nullable', 'string', 'max:200'],
        ]);

        return response()->json(['data' => $this->portal->updateProfile($request->user(), $member, $validated)]);
    }

    public function billing(Request $request, Member $member): JsonResponse
    {
        $this->portal->assertAccess($request->user(), $member);
        $member->load([
            'invoices.settlements',
            'payments.receipt',
            'renewals.invoice.settlements',
            'renewals.resultingPeriod',
        ]);

        return response()->json(['data' => [
            'outstanding_balance' => $member->outstanding_balance,
            'invoices' => $member->invoices,
            'payments' => $member->payments,
            'renewals' => $member->renewals,
        ]]);
    }

    public function startRenewal(Request $request, Member $member): JsonResponse
    {
        $this->portal->assertAccess($request->user(), $member);
        $renewal = $this->renewals->ensureRenewal($member, $request->user());

        return response()->json(['data' => $renewal->load(['invoice.settlements', 'sourcePeriod', 'resultingPeriod'])], 201);
    }

    public function credential(Request $request, Member $member): JsonResponse
    {
        $this->portal->assertAccess($request->user(), $member);
        return response()->json(['data' => $this->credentials->active($member)]);
    }

    public function issueCredential(Request $request, Member $member): JsonResponse
    {
        $this->portal->assertAccess($request->user(), $member);
        return response()->json(['data' => $this->credentials->issue($member, $request->user())], 201);
    }

    public function credentialSvg(Request $request, Member $member): Response
    {
        $this->portal->assertAccess($request->user(), $member);
        $credential = $this->credentials->active($member);
        abort_if($credential === null, 404, 'No current digital membership credential has been issued.');

        return response($this->credentials->renderSvg($member, $credential), 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="'.str_replace('/', '-', $member->registration_number).'-'.$credential->credential_type.'.svg"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
