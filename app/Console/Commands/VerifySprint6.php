<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\MemberPortalAccount;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

final class VerifySprint6 extends Command
{
    protected $signature = 'icgu:verify-sprint6';
    protected $description = 'Verify Sprint 6 member portal Blade UI, routes and responsive assets.';

    public function handle(): int
    {
        DB::beginTransaction();

        try {
            foreach (['member.login','member.dashboard','member.membership','member.billing','member.renew','member.credential.issue','membership.verify.page'] as $name) {
                if (! app('router')->has($name)) {
                    throw new \RuntimeException("Missing Sprint 6 named route: {$name}");
                }
            }

            foreach (['member.login','member.invitation','member.dashboard','member.membership','member.billing','member.verify','layouts.member-portal'] as $view) {
                if (! View::exists($view)) {
                    throw new \RuntimeException("Missing Sprint 6 Blade view: {$view}");
                }
            }

            $css = public_path('css/member-portal.css');
            if (! is_file($css) || ! str_contains((string) file_get_contents($css), '@media(max-width:640px)')) {
                throw new \RuntimeException('Responsive member portal stylesheet is missing or incomplete.');
            }

            // Web requests receive this via ShareErrorsFromSession middleware. CLI view rendering does not.
            View::share('errors', new ViewErrorBag());

            $member = Member::query()->with(['status','membershipPlan','currentPeriod','latestPeriod','latestRenewal.invoice.settlements','activeCredential'])->firstOrFail();
            $user = User::query()->create([
                'name' => 'Sprint Six Portal User',
                'email' => 'sprint6.portal@prototype.invalid',
                'password' => 'prototype-password',
                'is_active' => true,
            ]);
            MemberPortalAccount::query()->create([
                'member_id' => $member->id,
                'user_id' => $user->id,
                'access_role' => $member->type === 'individual' ? 'owner' : 'representative',
                'is_primary' => true,
                'linked_at' => now(),
            ]);
            Auth::login($user);

            $accounts = $user->portalAccounts()->with([
                'member.status','member.membershipPlan','member.currentPeriod','member.latestPeriod',
                'member.latestRenewal.invoice.settlements','member.activeCredential',
            ])->get();

            $dashboard = view('member.dashboard', compact('accounts'))->render();
            if (! str_contains($dashboard, 'Your ICGU membership at a glance') || ! str_contains($dashboard, $member->registration_number)) {
                throw new \RuntimeException('Member dashboard did not render expected membership content.');
            }

            $account = $accounts->first();
            $member->load(['organisation','primaryEmail','invoices.settlements','payments.receipt','renewals.invoice.settlements','renewals.resultingPeriod']);
            $membershipHtml = view('member.membership', compact('member','account'))->render();
            $billingHtml = view('member.billing', compact('member','account'))->render();
            if (! str_contains($membershipHtml, 'Membership standing') || ! str_contains($billingHtml, 'Billing & annual renewal')) {
                throw new \RuntimeException('Membership or billing page failed to render.');
            }

            $login = view('member.login')->render();
            if (! str_contains($login, 'Sign in to Member Portal') || ! str_contains($login, 'name="_token"')) {
                throw new \RuntimeException('Login page is missing the portal form or CSRF field.');
            }

            $this->info('Sprint 6 branded member portal UI verified successfully.');
            return self::SUCCESS;
        } finally {
            Auth::logout();
            DB::rollBack();
        }
    }
}
