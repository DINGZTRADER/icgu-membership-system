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
        {--reset-password : Replace the password for an existing account}';

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

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid staff email address is required.');
            return self::FAILURE;
        }
        if (! in_array($roleSlug, $allowed, true)) {
            $this->error('Role must be one of: '.implode(', ', $allowed));
            return self::FAILURE;
        }

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user = User::query()->where('email', $email)->first();
        $name = trim((string) ($this->option('name') ?: $user?->name ?: ''));
        if ($name === '') {
            $name = trim((string) $this->ask('Staff name'));
        }
        if ($name === '' || mb_strlen($name) > 150) {
            $this->error('Staff name is required and must be at most 150 characters.');
            return self::FAILURE;
        }

        $needsPassword = $user === null || (bool) $this->option('reset-password');
        $password = null;
        if ($needsPassword) {
            $password = (string) $this->secret('Password (minimum 12 characters)');
            if (mb_strlen($password) < 12 || Str::contains(mb_strtolower($password), mb_strtolower(Str::before($email, '@')))) {
                $this->error('Password must be at least 12 characters and must not contain the email username.');
                return self::FAILURE;
            }
            if ($password !== (string) $this->secret('Confirm password')) {
                $this->error('Passwords do not match.');
                return self::FAILURE;
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
        ]);

        $this->info("Staff account ready: {$email} ({$roleSlug}).");
        return self::SUCCESS;
    }
}
