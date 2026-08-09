<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

final class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            ['domain' => 'members', 'slug' => 'members.view', 'name' => 'View members'],
            ['domain' => 'members', 'slug' => 'members.manage', 'name' => 'Manage members'],
            ['domain' => 'finance', 'slug' => 'finance.view', 'name' => 'View finance'],
            ['domain' => 'finance', 'slug' => 'finance.manage', 'name' => 'Manage finance'],
            ['domain' => 'training', 'slug' => 'training.view', 'name' => 'View training'],
            ['domain' => 'training', 'slug' => 'training.manage', 'name' => 'Manage training'],
            ['domain' => 'reports', 'slug' => 'reports.view', 'name' => 'View reports'],
            ['domain' => 'audit', 'slug' => 'audit.view', 'name' => 'View audit trail'],
            ['domain' => 'admin', 'slug' => 'users.manage', 'name' => 'Manage users and access'],
        ];

        foreach ($permissions as $permission) {
            Permission::query()->updateOrCreate(['slug' => $permission['slug']], $permission);
        }

        $roles = [
            'super-admin' => ['Super Admin', array_column($permissions, 'slug')],
            'ceo' => ['CEO', ['members.view', 'finance.view', 'training.view', 'reports.view', 'audit.view']],
            'membership-officer' => ['Membership Officer', ['members.view', 'members.manage', 'reports.view']],
            'finance-officer' => ['Finance Officer', ['members.view', 'finance.view', 'finance.manage', 'reports.view']],
            'training-officer' => ['Training Officer', ['members.view', 'training.view', 'training.manage', 'reports.view']],
            'auditor' => ['Auditor', ['members.view', 'finance.view', 'reports.view', 'audit.view']],
            'member' => ['Member', []],
        ];

        foreach ($roles as $slug => [$name, $permissionSlugs]) {
            $role = Role::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'is_system' => true]);
            $role->permissions()->sync(Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id'));
        }
    }
}
