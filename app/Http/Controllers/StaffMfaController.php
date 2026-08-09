<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use App\Services\TotpService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class StaffMfaController extends Controller
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly AuditService $audit,
    ) {}

    public function setup(Request $request): View|RedirectResponse
    {
        $user = $this->staffUser($request);
        if ($user->mfa_confirmed_at !== null) {
            return redirect()->route('staff.dashboard');
        }

        if (! is_string($user->mfa_secret) || $user->mfa_secret === '') {
            $user->forceFill(['mfa_secret' => $this->totp->generateSecret()])->save();
        }

        return view('staff.mfa-setup', [
            'secret' => $user->mfa_secret,
            'provisioningUri' => $this->totp->provisioningUri($user, $user->mfa_secret),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $this->staffUser($request);
        $validated = $request->validate(['code' => ['required', 'string', 'max:12']]);

        if (! is_string($user->mfa_secret) || ! $this->totp->verify($user->mfa_secret, $validated['code'])) {
            throw ValidationException::withMessages(['code' => 'The authenticator code is invalid.']);
        }

        $recovery = $this->totp->generateRecoveryCodes();
        $user->forceFill([
            'mfa_confirmed_at' => now(),
            'mfa_recovery_codes' => $recovery['hashes'],
        ])->save();
        $request->session()->put('staff_mfa_verified_at', now()->timestamp);
        $request->session()->flash('staff_mfa_recovery_codes', $recovery['plain']);

        $this->audit->record('staff_mfa_enabled', $user, after: ['mfa_confirmed_at' => $user->mfa_confirmed_at?->toIso8601String()]);

        return redirect()->route('staff.mfa.recovery');
    }

    public function recoveryCodes(Request $request): View|RedirectResponse
    {
        $this->staffUser($request);
        $codes = $request->session()->get('staff_mfa_recovery_codes');
        if (! is_array($codes) || $codes === []) {
            return redirect()->route('staff.dashboard');
        }

        return view('staff.mfa-recovery', ['codes' => $codes]);
    }

    public function challengeForm(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('staff_mfa_pending_user_id')) {
            return redirect()->route('staff.login');
        }

        return view('staff.mfa-challenge');
    }

    public function challenge(Request $request): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'max:32']]);
        $userId = $request->session()->get('staff_mfa_pending_user_id');
        $user = is_numeric($userId) ? User::query()->find((int) $userId) : null;

        if ($user === null || ! $user->is_active || ! $user->hasStaffRole() || $user->mfa_confirmed_at === null || ! is_string($user->mfa_secret)) {
            $request->session()->forget(['staff_mfa_pending_user_id', 'staff_mfa_pending_remember']);
            throw ValidationException::withMessages(['code' => 'The MFA challenge is no longer valid. Sign in again.']);
        }

        $valid = $this->totp->verify($user->mfa_secret, $validated['code']);
        $usedRecoveryCode = false;
        if (! $valid) {
            $usedRecoveryCode = $this->totp->consumeRecoveryCode($user, $validated['code']);
            $valid = $usedRecoveryCode;
        }

        if (! $valid) {
            throw ValidationException::withMessages(['code' => 'The authenticator or recovery code is invalid.']);
        }

        $remember = (bool) $request->session()->pull('staff_mfa_pending_remember', false);
        $request->session()->forget('staff_mfa_pending_user_id');
        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->put('staff_mfa_verified_at', now()->timestamp);
        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->record('staff_mfa_challenge_passed', $user, after: ['recovery_code_used' => $usedRecoveryCode]);

        return redirect()->route('staff.dashboard');
    }

    private function staffUser(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active && $user->hasStaffRole(), 403);

        return $user;
    }
}
