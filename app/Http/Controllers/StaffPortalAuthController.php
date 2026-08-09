<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class StaffPortalAuthController extends Controller
{
    public function loginForm(Request $request): View|RedirectResponse
    {
        if ($request->user()?->hasStaffRole()) {
            return redirect()->route($request->user()->mfa_confirmed_at ? 'staff.dashboard' : 'staff.mfa.setup');
        }

        return view('staff.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt([
            'email' => mb_strtolower($credentials['email']),
            'password' => $credentials['password'],
            'is_active' => true,
        ], false)) {
            throw ValidationException::withMessages(['email' => 'The supplied staff credentials are invalid.']);
        }

        $request->session()->regenerate();
        $user = $request->user();

        if (! $user->hasStaffRole()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw ValidationException::withMessages(['email' => 'This account does not have Secretariat access.']);
        }

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

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('staff.login');
    }
}
