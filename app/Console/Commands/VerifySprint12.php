<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

final class VerifySprint12 extends Command
{
    protected $signature = 'icgu:verify-sprint12';
    protected $description = 'Verify pilot staff authentication readiness for Google Workspace and TOTP paths.';

    public function handle(): int
    {
        $originalGoogle = (array) config('services.google', []);

        DB::beginTransaction();

        try {
            $ceo = Role::query()->where('slug', 'ceo')->firstOrFail();

            Config::set('services.google.client_id', 'sprint12-client');
            Config::set('services.google.client_secret', 'sprint12-secret');
            Config::set('services.google.hosted_domain', 'icgu.org');

            $googleUser = $this->staffUser('sprint12.google@icgu.org', $ceo, null);
            $this->assert($googleUser->hasReadyStaffAuthenticator(), 'Configured ICGU Workspace staff account should be authentication-ready.');

            $externalUser = $this->staffUser('sprint12.external@example.com', $ceo, null);
            $this->assert(! $externalUser->hasReadyStaffAuthenticator(), 'External-domain account must not become ready through ICGU Google Workspace.');

            Config::set('services.google.client_secret', '');
            $this->assert(! $googleUser->fresh()->hasReadyStaffAuthenticator(), 'Google staff readiness must fail when OAuth credentials are incomplete.');

            $totpUser = $this->staffUser('sprint12.totp@example.com', $ceo, now());
            $this->assert($totpUser->hasReadyStaffAuthenticator(), 'TOTP-enrolled staff must remain authentication-ready without Google OAuth.');

            $totpUser->forceFill(['is_active' => false])->save();
            $this->assert(! $totpUser->fresh()->hasReadyStaffAuthenticator(), 'Inactive staff accounts must never be authentication-ready.');

            $this->info('Sprint 12 Google Workspace/TOTP pilot authentication readiness verified successfully.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            DB::rollBack();
            Config::set('services.google', $originalGoogle);
        }
    }

    private function staffUser(string $email, Role $role, mixed $mfaConfirmedAt): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Sprint 12 Verification',
            'email' => $email,
            'password' => Hash::make(Str::random(48)),
            'is_active' => true,
            'mfa_confirmed_at' => $mfaConfirmedAt,
        ])->save();
        $user->roles()->attach($role->id);

        return $user->fresh(['roles.permissions']);
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
