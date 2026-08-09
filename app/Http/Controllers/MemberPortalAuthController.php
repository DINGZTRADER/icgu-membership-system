<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\MemberPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class MemberPortalAuthController extends Controller
{
    public function __construct(private readonly MemberPortalService $portal) {}

    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        $account = $this->portal->acceptInvitation($token, $validated['name'], $validated['password']);
        Auth::login($account->user);
        $request->session()->regenerate();

        return response()->json(['data' => [
            'user' => $account->user,
            'membership' => $account->member,
            'access_role' => $account->access_role,
        ]], 201);
    }

    public function login(Request $request): JsonResponse
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
            throw ValidationException::withMessages(['email' => 'The supplied member portal credentials are invalid.']);
        }

        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();

        return response()->json(['data' => [
            'user' => $request->user(),
            'memberships_count' => $request->user()->portalAccounts()->count(),
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['data' => ['logged_out' => true]]);
    }
}
