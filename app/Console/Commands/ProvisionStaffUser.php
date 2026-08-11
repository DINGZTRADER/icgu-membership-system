<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class ProvisionStaffUser extends Command
{
    protected $signature = 'icgu:staff-user
        {email : Staff email address}
        {role : Staff role slug}
        {--name= : Staff display name}
        {--google-only : Disable password sign-in by assigning an inaccessible random local password; requires the configured Google Workspace domain}
        {--reset-password : Replace the password for an existing account}
        {--password-env= : Environment variable containing the initial/reset password for non-interactive provisioning}';

    protected $description = 'Provision or update a real ICGU staff account without storing credentials in source control or shell arguments.';

    public function __construct(private readonly AuditService $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $roleSlug = trim((string) $this->argument('role'));
        $allowed = ['super-admin', 'ceo', 'membership-officer', 'finance-officer', 'training-officer', 'auditor'];
        $googleOnly = (bool) $this->option('google-only');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid staff email address is required.');
            return self::FAILURE;
        }
        if (! in_array($roleSlug, $allowed, true)) {
            $this->error('Role must be one of: '.implode(', ', $allowed));
            return self::FAILURE;
        }
        if ($googleOnly && (bool) $this->option('reset-password')) {
            $this->error('--google-only already replaces the local password and cannot be combined with --reset-password.');
            return self::FAILURE;
        }
        if ($googleOnly && trim((string) $this->option('password-env')) !== '') {
            $this->error('--google-only cannot be combined with --password-env.');
            return self::FAILURE;
        }

        if ($googleOnly) {
            $workspaceDomain = mb_strtolower(trim((string) config('services.google.hosted_domain', 'icgu.org')));
            $emailDomain = mb_strtolower(trim((string) Str::afterLast($email, '@')));
            if ($workspaceDomain === '' || $emailDomain !== $workspaceDomain) {
                $this->error("--google-only requires an email in the configured Google Workspace domain ({$workspaceDomain}).");
                return self::FAILURE;
            }
        }

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user = User::query()->where('email', $email)->first();
        $name = trim((string) ($this->option('name') ?: $user?->name ?: ''));
        if ($name === '') {
            if (! $this->input->isInteractive()) {
                $this->error('Staff name is required in non-interactive environments. Use --name="Full Name".');
                return self::FAILURE;
            }

            $name = trim((string) $this->ask('Staff name'));
        }
        if ($name === '' || mb_strlen($name) > 150) {
            $this->error('Staff name is required and must be at most 150 characters.');
            return self::FAILURE;
        }

        $needsPassword = $user === null || (bool) $this->option('reset-password') || $googleOnly;
        $password = null;
        if ($needsPassword) {
            if ($googleOnly) {
                // The value is never displayed or persisted outside the one-way password hash.
                // This disables the legacy password path for Google-only Secretariat accounts.
                $password = Str::random(64);
            } else {
                $passwordEnv = trim((string) $this->option('password-env'));

                if ($passwordEnv !== '') {
                    if (! preg_match('/\A[A-Z][A-Z0-9_]{2,80}\z/', $passwordEnv)) {
                        $this->error('Password environment variable name must use uppercase letters, numbers, and underscores only.');
                        return self::FAILURE;
                    }

                    $environmentPassword = getenv($passwordEnv);
                    if ($environmentPassword === false || $environmentPassword === '') {
                        $this->error("Environment variable {$passwordEnv} is not set or is empty.");
                        return self::FAILURE;
                    }

                    $password = (string) $environmentPassword;
                } else {
                    if (! $this->input->isInteractive()) {
                        $this->error('A password source is required in non-interactive environments. Set a temporary secret environment variable and pass --password-env=VARIABLE_NAME, or use --google-only for an ICGU Workspace account.');
                        return self::FAILURE;
                    }

                    $password = (string) $this->secret('Password (minimum 12 characters)');
                    if ($password !== (string) $this->secret('Confirm password')) {
                        $this->error('Passwords do not match.');
                        return self::FAILURE;
                    }
                }

                if (mb_strlen($password) < 12 || Str::contains(mb_strtolower($password), mb_strtolower(Str::before($email, '@')))) {
                    $this->error('Password must be at least 12 characters and must not contain the email username.');
                    return self::FAILURE;
                }
            }
        }

        $before = $user?->only(['id', 'name', 'email', 'is_active']);
        $user ??= new User();
        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'is_active' => true,
            ...($password !== null ? ['password' => Hash::make($password)] : []),
        ])->save();
        $user->roles()->sync([$role->id]);

        $this->audit->record('staff_user_provisioned', $user, before: $before, after: [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $roleSlug,
            'password_reset' => $password !== null,
            'google_only' => $googleOnly,
        ]);

        $mode = $googleOnly ? 'Google Workspace only' : 'password-capable';
        $this->info("Staff account ready: {$email} ({$roleSlug}, {$mode}).");
        return self::SUCCESS;
    }
}
