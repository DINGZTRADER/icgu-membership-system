<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

final class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_active', 'last_login_at'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'mfa_recovery_codes' => 'encrypted:array',
            'mfa_confirmed_at' => 'immutable_datetime',
        ];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)->withTimestamps();
    }

    public function portalAccounts(): HasMany
    {
        return $this->hasMany(MemberPortalAccount::class, 'user_id');
    }

    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'member_portal_accounts', 'user_id', 'member_id')
            ->withPivot(['access_role', 'is_primary', 'linked_at'])
            ->withTimestamps();
    }

    public function hasRole(string $role): bool
    {
        return $this->authorizationRoles()->contains('slug', $role);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->authorizationRoles()->contains(
            fn (Role $role): bool => $role->permissions->contains('slug', $permission),
        );
    }

    public function hasStaffRole(): bool
    {
        return $this->authorizationRoles()->contains(
            fn (Role $role): bool => $role->slug !== 'member',
        );
    }

    public function requiresStaffMfa(): bool
    {
        return $this->hasStaffRole() && (bool) config('production.require_staff_mfa', false);
    }

    /**
     * A staff account is pilot-authentication-ready when it is active and can
     * satisfy the production authentication policy through either enrolled
     * TOTP MFA or the configured, domain-restricted Google Workspace flow.
     */
    public function hasReadyStaffAuthenticator(): bool
    {
        if (! $this->is_active || ! $this->hasStaffRole()) {
            return false;
        }

        if ($this->mfa_confirmed_at !== null) {
            return true;
        }

        $clientId = trim((string) config('services.google.client_id'));
        $clientSecret = trim((string) config('services.google.client_secret'));
        $hostedDomain = mb_strtolower(trim((string) config('services.google.hosted_domain')));

        if ($clientId === '' || $clientSecret === '' || $hostedDomain === '') {
            return false;
        }

        return Str::endsWith(mb_strtolower(trim($this->email)), '@'.$hostedDomain);
    }

    /**
     * Load roles and permissions once on this authenticated User instance.
     * Middleware and Blade reuse the same relation graph for the rest of the
     * request instead of issuing a new database query for every permission.
     *
     * @return Collection<int,Role>
     */
    private function authorizationRoles(): Collection
    {
        $this->loadMissing('roles.permissions');

        return $this->roles;
    }
}
