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
            ['domain' => 'applications', 'slug' => 'applications.view', 'name' => 'View membership applications'],
            ['domain' => 'applications', 'slug' => 'applications.review', 'name' => 'Review and decide membership applications'],
            ['domain' => 'applications', 'slug' => 'applications.manage', 'name' => 'Manage membership applications'],
            ['domain' => 'applications', 'slug' => 'applications.admit', 'name' => 'Admit fully paid approved applicants'],
            ['domain' => 'documents', 'slug' => 'documents.view', 'name' => 'View membership documents'],
            ['domain' => 'documents', 'slug' => 'documents.manage', 'name' => 'Manage membership documents'],
            ['domain' => 'organisations', 'slug' => 'organisations.view', 'name' => 'View organisations'],
            ['domain' => 'organisations', 'slug' => 'organisations.manage', 'name' => 'Manage organisations'],
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
            'ceo' => ['CEO', ['members.view', 'applications.view', 'applications.review', 'applications.admit', 'documents.view', 'organisations.view', 'finance.view', 'training.view', 'reports.view', 'audit.view']],
            'membership-officer' => ['Membership Officer', ['members.view', 'members.manage', 'applications.view', 'applications.review', 'applications.manage', 'applications.admit', 'documents.view', 'documents.manage', 'organisations.view', 'organisations.manage', 'reports.view']],
            'finance-officer' => ['Finance Officer', ['members.view', 'applications.view', 'organisations.view', 'finance.view', 'finance.manage', 'reports.view']],
            'training-officer' => ['Training Officer', ['members.view', 'organisations.view', 'training.view', 'training.manage', 'reports.view']],
            'auditor' => ['Auditor', ['members.view', 'applications.view', 'documents.view', 'organisations.view', 'finance.view', 'reports.view', 'audit.view']],
            'member' => ['Member', []],
        ];

        foreach ($roles as $slug => [$name, $permissionSlugs]) {
            $role = Role::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'is_system' => true]);
            $role->permissions()->sync(Permission::query()->whereIn('slug', $permissionSlugs)->pluck('id'));
        }
    }
}
