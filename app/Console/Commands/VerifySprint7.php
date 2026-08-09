<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\StaffPortalPageController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

final class VerifySprint7 extends Command
{
    protected $signature = 'icgu:verify-sprint7';
    protected $description = 'Verify Sprint 7 Secretariat and executive admin UI, routes, RBAC and reports.';

    public function handle(): int
    {
        DB::beginTransaction();

        try {
            foreach ([
                'staff.login', 'staff.dashboard', 'staff.applications.index', 'staff.applications.show',
                'staff.members.index', 'staff.members.show', 'staff.renewals', 'staff.finance',
                'staff.receipts.show', 'staff.organisations', 'staff.reports', 'staff.audit',
            ] as $name) {
                if (! app('router')->has($name)) {
                    throw new \RuntimeException("Missing Sprint 7 named route: {$name}");
                }
            }

            foreach ([
                'staff.login', 'staff.dashboard', 'staff.applications.index', 'staff.applications.show',
                'staff.members.index', 'staff.members.show', 'staff.renewals', 'staff.finance',
                'staff.receipt', 'staff.organisations', 'staff.reports', 'staff.audit', 'layouts.staff-admin',
            ] as $view) {
                if (! View::exists($view)) {
                    throw new \RuntimeException("Missing Sprint 7 Blade view: {$view}");
                }
            }

            $css = public_path('css/staff-admin.css');
            $cssContents = is_file($css) ? (string) file_get_contents($css) : '';
            if ($cssContents === '' || ! str_contains($cssContents, '@media(max-width:760px)') || ! str_contains($cssContents, '@media print')) {
                throw new \RuntimeException('Sprint 7 responsive/print staff stylesheet is missing or incomplete.');
            }

            $role = Role::query()->where('slug', 'super-admin')->firstOrFail();
            $user = User::query()->create([
                'name' => 'Sprint Seven Secretariat User',
                'email' => 'sprint7.staff@prototype.invalid',
                'password' => 'prototype-password',
                'is_active' => true,
            ]);
            $user->roles()->attach($role->id);
            Auth::login($user);
            View::share('errors', new ViewErrorBag());

            if (! $user->hasPermission('reports.view') || ! $user->hasPermission('finance.manage') || ! $user->hasPermission('audit.view')) {
                throw new \RuntimeException('Super Admin does not retain the Sprint 7 operational permissions.');
            }

            $request = Request::create('/staff/dashboard', 'GET');
            $request->setUserResolver(fn () => $user);
            $controller = app(StaffPortalPageController::class);

            $dashboard = $controller->dashboard($request)->render();
            if (! str_contains($dashboard, 'Membership operations at a glance') || ! str_contains($dashboard, 'Outstanding balance')) {
                throw new \RuntimeException('Executive dashboard did not render the expected operational KPIs.');
            }

            $applications = $controller->applications($request)->render();
            $members = $controller->members($request)->render();
            $finance = $controller->finance($request)->render();
            $reports = $controller->reports()->render();
            $audit = $controller->audit($request)->render();

            foreach ([
                'Application work queue' => $applications,
                'ICGU membership register' => $members,
                'Invoice register' => $finance,
                'Membership performance & financial position' => $reports,
                'Immutable operational audit trail' => $audit,
            ] as $needle => $html) {
                if (! str_contains($html, $needle)) {
                    throw new \RuntimeException("Sprint 7 screen failed to render expected content: {$needle}");
                }
            }

            $this->info('Sprint 7 Secretariat dashboard, staff workspaces, reports and audit UI verified successfully.');
            return self::SUCCESS;
        } finally {
            Auth::logout();
            DB::rollBack();
        }
    }
}
