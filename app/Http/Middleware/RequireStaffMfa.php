<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class RequireStaffMfa
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user !== null && $user->hasStaffRole(), 403);

        if (! $user->requiresStaffMfa()) {
            return $next($request);
        }

        if ($user->mfa_confirmed_at === null) {
            return redirect()->route('staff.mfa.setup');
        }

        if (! $request->session()->has('staff_mfa_verified_at')) {
            $request->session()->put('staff_mfa_pending_user_id', $user->id);
            $request->session()->put('staff_mfa_pending_remember', true);
            Auth::logout();

            return redirect()->route('staff.mfa.challenge');
        }

        return $next($request);
    }
}
