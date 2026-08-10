<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class GoogleStaffAuthController extends Controller
{
    private const AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';

    public function redirect(Request $request): RedirectResponse
    {
        $clientId = trim((string) config('services.google.client_id'));
        $redirectUri = trim((string) config('services.google.redirect'));

        if ($clientId === '' || $redirectUri === '') {
            return redirect()->route('staff.login')->withErrors([
                'google' => 'Google sign-in is not configured yet. Please contact the system administrator.',
            ]);
        }

        $state = Str::random(64);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put('google_oauth_state', $state);
        $request->session()->put('google_pkce_verifier', $verifier);

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'hd' => (string) config('services.google.hosted_domain', 'icgu.org'),
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away(self::AUTHORIZE_ENDPOINT.'?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('staff.login')->withErrors([
                'google' => 'Google sign-in was cancelled or could not be completed.',
            ]);
        }

        $expectedState = (string) $request->session()->pull('google_oauth_state', '');
        $returnedState = (string) $request->query('state', '');
        $verifier = (string) $request->session()->pull('google_pkce_verifier', '');
        $code = (string) $request->query('code', '');

        if ($expectedState === '' || $returnedState === '' || ! hash_equals($expectedState, $returnedState) || $verifier === '' || $code === '') {
            return redirect()->route('staff.login')->withErrors([
                'google' => 'Google sign-in could not be verified. Please try again.',
            ]);
        }

        $clientId = trim((string) config('services.google.client_id'));
        $clientSecret = trim((string) config('services.google.client_secret'));
        $redirectUri = trim((string) config('services.google.redirect'));

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
            return redirect()->route('staff.login')->withErrors([
                'google' => 'Google sign-in is not configured yet. Please contact the system administrator.',
            ]);
        }

        $tokenResponse = Http::asForm()->acceptJson()->timeout(15)->post(self::TOKEN_ENDPOINT, [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'code_verifier' => $verifier,
        ]);

        if (! $tokenResponse->successful()) {
            return redirect()->route('staff.login')->withErrors([
                'google' => 'Google sign-in could not be completed. Please try again.',
            ]);
        }

        $accessToken = (string) $tokenResponse->json('access_token', '');
        if ($accessToken === '') {
            return redirect()->route('staff.login')->withErrors([
                'google' => 'Google did not return a valid sign-in token.',
            ]);
        }

        $profileResponse = Http::withToken($accessToken)->acceptJson()->timeout(15)->get(self::USERINFO_ENDPOINT);
        if (! $profileResponse->successful()) {
            return redirect()->route('staff.login')->withErrors([
                'google' => 'Google account details could not be verified.',
            ]);
        }

        $email = mb_strtolower(trim((string) $profileResponse->json('email', '')));
        $emailVerified = filter_var($profileResponse->json('email_verified', false), FILTER_VALIDATE_BOOL);
        $hostedDomain = mb_strtolower(trim((string) $profileResponse->json('hd', '')));
        $requiredDomain = mb_strtolower(trim((string) config('services.google.hosted_domain', 'icgu.org')));

        if ($email === '' || ! $emailVerified || $hostedDomain !== $requiredDomain) {
            return redirect()->route('staff.login')->withErrors([
                'google' => 'Please sign in with your authorised ICGU Google Workspace account.',
            ]);
        }

        $user = User::query()
            ->where('is_active', true)
            ->whereRaw('lower(email) = ?', [$email])
            ->first();

        if (! $user instanceof User || ! $user->hasStaffRole()) {
            return redirect()->route('staff.login')->withErrors([
                'google' => 'This Google account is not registered for ICGU staff access.',
            ]);
        }

        Auth::login($user, true);
        $request->session()->regenerate();
        $request->session()->forget([
            'staff_mfa_verified_at',
            'staff_mfa_pending_user_id',
            'staff_mfa_pending_remember',
        ]);
        $request->session()->put('staff_google_authenticated_at', now()->toIso8601String());
        $request->session()->put('staff_google_subject', (string) $profileResponse->json('sub', ''));

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('staff.dashboard');
    }
}
