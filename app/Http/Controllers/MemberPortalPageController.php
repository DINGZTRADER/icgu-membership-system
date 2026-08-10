<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\MemberCredential;
use App\Services\MemberCredentialService;
use App\Services\MemberPortalService;
use App\Services\MembershipRenewalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class MemberPortalPageController extends Controller
{
    public function __construct(
        private readonly MemberPortalService $portal,
        private readonly MembershipRenewalService $renewals,
        private readonly MemberCredentialService $credentials,
    ) {}

    public function loginForm(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user?->hasStaffRole()) {
            return redirect()->route($user->mfa_confirmed_at ? 'staff.dashboard' : 'staff.mfa.setup');
        }

        if ($user?->portalAccounts()->exists()) {
            return redirect()->route('member.dashboard');
        }

        return view('member.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([
            'email' => mb_strtolower($validated['email']),
            'password' => $validated['password'],
            'is_active' => true,
        ], true)) {
            throw ValidationException::withMessages(['email' => 'The supplied credentials are invalid.']);
        }

        $request->session()->regenerate();
        $user = $request->user();

        if ($user->hasStaffRole()) {
            $request->session()->forget('staff_mfa_verified_at');

            if ($user->requiresStaffMfa() && $user->mfa_confirmed_at === null) {
                return redirect()->route('staff.mfa.setup');
            }

            if ($user->requiresStaffMfa()) {
                $userId = $user->id;
                Auth::logout();
                $request->session()->put('staff_mfa_pending_user_id', $userId);
                $request->session()->put('staff_mfa_pending_remember', false);

                return redirect()->route('staff.mfa.challenge');
            }

            $user->forceFill(['last_login_at' => now()])->save();

            return redirect()->route('staff.dashboard');
        }

        if (! $user->portalAccounts()->exists()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw ValidationException::withMessages(['email' => 'This account is not linked to an ICGU portal record.']);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('member.dashboard');
    }

    public function invitationForm(string $token): View
    {
        return view('member.invitation', ['token' => $token]);
    }

    public function acceptInvitation(Request $request, string $token): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $account = $this->portal->acceptInvitation($token, $validated['name'], $validated['password']);
        Auth::login($account->user);
        $request->session()->regenerate();

        return redirect()->route('member.dashboard')->with('status', 'Your ICGU member portal is ready.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('member.login')->with('status', 'You have signed out securely.');
    }

    public function dashboard(Request $request): View
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

        return view('member.dashboard', ['accounts' => $accounts]);
    }

    public function membership(Request $request, Member $member): View
    {
        $account = $this->portal->assertAccess($request->user(), $member);
        $member->load([
            'status', 'membershipPlan', 'organisation', 'primaryEmail',
            'currentPeriod', 'latestPeriod', 'latestRenewal.invoice.settlements', 'activeCredential',
        ]);

        return view('member.membership', compact('member', 'account'));
    }

    public function updateProfile(Request $request, Member $member): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:40'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'organization' => ['nullable', 'string', 'max:200'],
        ]);

        $this->portal->updateProfile($request->user(), $member, $validated);

        return back()->with('status', 'Profile details updated.');
    }

    public function billing(Request $request, Member $member): View
    {
        $account = $this->portal->assertAccess($request->user(), $member, ['owner', 'representative', 'billing']);
        $member->load([
            'status', 'membershipPlan',
            'invoices.settlements',
            'payments.receipt',
            'renewals.invoice.settlements',
            'renewals.resultingPeriod',
        ]);

        return view('member.billing', compact('member', 'account'));
    }

    public function startRenewal(Request $request, Member $member): RedirectResponse
    {
        $this->portal->assertAccess($request->user(), $member, ['owner', 'representative', 'billing']);
        $renewal = $this->renewals->ensureRenewal($member, $request->user());

        return redirect()->route('member.billing', $member)
            ->with('status', 'Renewal invoice '.$renewal->invoice?->invoice_number.' is ready.');
    }

    public function issueCredential(Request $request, Member $member): RedirectResponse
    {
        $this->portal->assertAccess($request->user(), $member, ['owner', 'representative']);
        $this->credentials->issue($member, $request->user());

        return back()->with('status', 'Your digital credential is ready.');
    }

    public function verifyCredential(string $verificationCode): View
    {
        $credential = MemberCredential::query()
            ->where('verification_code', $verificationCode)
            ->with(['member.membershipPlan', 'member.status'])
            ->first();

        return view('member.verify', [
            'credential' => $credential,
            'isValid' => $credential?->isValid() ?? false,
        ]);
    }
}
