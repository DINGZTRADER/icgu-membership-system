<?php

declare(strict_types=1);

use App\Http\Controllers\MemberMobileMoneyPaymentController;
use App\Http\Controllers\MemberPortalAuthController;
use App\Http\Controllers\MemberPortalController;
use App\Http\Controllers\MemberPortalPageController;
use App\Http\Controllers\StaffMemberController;
use App\Http\Controllers\StaffMemberPortalController;
use App\Http\Controllers\StaffMembershipApplicationController;
use App\Http\Controllers\StaffMembershipBillingController;
use App\Http\Controllers\StaffMembershipDocumentController;
use App\Http\Controllers\StaffMembershipRenewalController;
use App\Http\Controllers\StaffMfaController;
use App\Http\Controllers\StaffOrganisationController;
use App\Http\Controllers\StaffPortalAuthController;
use App\Http\Controllers\StaffPortalPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('member.login'));

// Human-facing member portal. These routes render the Sprint 6 Blade UI.
Route::get('/member/login', [MemberPortalPageController::class, 'loginForm'])->name('member.login');
Route::post('/member/session', [MemberPortalPageController::class, 'login'])->middleware('throttle:10,1')->name('member.login.submit');
Route::get('/member/invitations/{token}', [MemberPortalPageController::class, 'invitationForm'])->middleware('throttle:60,1')->name('member.invitation');
Route::post('/member/invitations/{token}/activate', [MemberPortalPageController::class, 'acceptInvitation'])->middleware('throttle:10,1')->name('member.invitation.accept');
Route::get('/membership/verify/{verificationCode}', [MemberPortalPageController::class, 'verifyCredential'])->middleware('throttle:120,1')->name('membership.verify.page');

Route::middleware('auth')->group(function (): void {
    Route::post('/member/session/logout', [MemberPortalPageController::class, 'logout'])->name('member.logout');
    Route::get('/member/dashboard', [MemberPortalPageController::class, 'dashboard'])->name('member.dashboard');
    Route::get('/member/memberships/{member}', [MemberPortalPageController::class, 'membership'])->name('member.membership');
    Route::patch('/member/memberships/{member}/profile', [MemberPortalPageController::class, 'updateProfile'])->name('member.profile.update');
    Route::get('/member/memberships/{member}/billing', [MemberPortalPageController::class, 'billing'])->name('member.billing');
    Route::post('/member/memberships/{member}/billing/mtn-momo', MemberMobileMoneyPaymentController::class)->middleware('throttle:6,1')->name('member.billing.mtn-momo');
    Route::post('/member/memberships/{member}/renew', [MemberPortalPageController::class, 'startRenewal'])->name('member.renew');
    Route::post('/member/memberships/{member}/credential', [MemberPortalPageController::class, 'issueCredential'])->name('member.credential.issue');
});

// Secretariat authentication and mandatory MFA.
Route::get('/staff/login', [StaffPortalAuthController::class, 'loginForm'])->name('staff.login');
Route::post('/staff/session', [StaffPortalAuthController::class, 'login'])->middleware('throttle:10,1')->name('staff.login.submit');
Route::get('/staff/mfa/challenge', [StaffMfaController::class, 'challengeForm'])->middleware('throttle:30,1')->name('staff.mfa.challenge');
Route::post('/staff/mfa/challenge', [StaffMfaController::class, 'challenge'])->middleware('throttle:10,1')->name('staff.mfa.challenge.submit');

Route::middleware('auth')->prefix('staff')->group(function (): void {
    Route::post('/session/logout', [StaffPortalAuthController::class, 'logout'])->name('staff.logout');
    Route::get('/mfa/setup', [StaffMfaController::class, 'setup'])->name('staff.mfa.setup');
    Route::post('/mfa/setup', [StaffMfaController::class, 'confirm'])->middleware('throttle:10,1')->name('staff.mfa.confirm');
    Route::get('/mfa/recovery-codes', [StaffMfaController::class, 'recoveryCodes'])->name('staff.mfa.recovery');
});

// Sprint 7 Secretariat and executive admin UI, protected by Sprint 8 MFA.
Route::middleware(['auth', 'staff.mfa'])->prefix('staff')->group(function (): void {
    Route::get('/dashboard', [StaffPortalPageController::class, 'dashboard'])->middleware('permission:reports.view')->name('staff.dashboard');

    Route::middleware('permission:applications.view')->group(function (): void {
        Route::get('/applications', [StaffPortalPageController::class, 'applications'])->name('staff.applications.index');
        Route::get('/applications/{reference}', [StaffPortalPageController::class, 'application'])->name('staff.applications.show');
    });
    Route::middleware('permission:applications.review')->group(function (): void {
        Route::post('/applications/{reference}/review', [StaffPortalPageController::class, 'startReview'])->name('staff.applications.review');
        Route::post('/applications/{reference}/approve', [StaffPortalPageController::class, 'approve'])->name('staff.applications.approve');
        Route::post('/applications/{reference}/reject', [StaffPortalPageController::class, 'reject'])->name('staff.applications.reject');
    });
    Route::post('/applications/{reference}/payment', [StaffPortalPageController::class, 'recordApplicationPayment'])->middleware('permission:finance.manage')->name('staff.applications.payment');
    Route::post('/applications/{reference}/admit', [StaffPortalPageController::class, 'admit'])->middleware('permission:applications.admit')->name('staff.applications.admit');

    Route::middleware('permission:members.view')->group(function (): void {
        Route::get('/members', [StaffPortalPageController::class, 'members'])->name('staff.members.index');
        Route::get('/members/{member}', [StaffPortalPageController::class, 'member'])->name('staff.members.show');
    });
    Route::post('/members/{member}/portal/invite', [StaffPortalPageController::class, 'invitePortal'])->middleware('permission:portal.manage')->name('staff.members.invite');
    Route::post('/members/{member}/renew', [StaffPortalPageController::class, 'createRenewal'])->middleware('permission:renewals.manage')->name('staff.members.renew');
    Route::post('/members/{member}/renewals/{renewal}/payment', [StaffPortalPageController::class, 'recordRenewalPayment'])->middleware('permission:renewals.manage')->name('staff.renewals.payment');

    Route::get('/renewals', [StaffPortalPageController::class, 'renewals'])->middleware('permission:renewals.view')->name('staff.renewals');
    Route::get('/finance', [StaffPortalPageController::class, 'finance'])->middleware('permission:finance.view')->name('staff.finance');
    Route::get('/receipts/{receipt}', [StaffPortalPageController::class, 'receipt'])->middleware('permission:finance.view')->name('staff.receipts.show');
    Route::get('/organisations', [StaffPortalPageController::class, 'organisations'])->middleware('permission:organisations.view')->name('staff.organisations');
    Route::get('/reports', [StaffPortalPageController::class, 'reports'])->middleware('permission:reports.view')->name('staff.reports');
    Route::get('/audit', [StaffPortalPageController::class, 'audit'])->middleware('permission:audit.view')->name('staff.audit');
});

// Existing JSON/session endpoints retained for API and future app clients.
Route::prefix('member')->group(function (): void {
    Route::post('/login', [MemberPortalAuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/invitations/{token}/accept', [MemberPortalAuthController::class, 'acceptInvitation'])->middleware('throttle:10,1');

    Route::middleware('auth')->group(function (): void {
        Route::post('/logout', [MemberPortalAuthController::class, 'logout']);
        Route::get('/portal', [MemberPortalController::class, 'dashboard']);
        Route::get('/portal/members/{member}', [MemberPortalController::class, 'show']);
        Route::patch('/portal/members/{member}/profile', [MemberPortalController::class, 'updateProfile']);
        Route::get('/portal/members/{member}/billing', [MemberPortalController::class, 'billing']);
        Route::post('/portal/members/{member}/renewals', [MemberPortalController::class, 'startRenewal']);
        Route::get('/portal/members/{member}/credential', [MemberPortalController::class, 'credential']);
        Route::post('/portal/members/{member}/credential', [MemberPortalController::class, 'issueCredential']);
        Route::get('/portal/members/{member}/credential.svg', [MemberPortalController::class, 'credentialSvg']);
    });
});

Route::prefix('staff/membership')->middleware(['auth', 'staff.mfa'])->group(function (): void {
    Route::middleware('permission:members.view')->group(function (): void {
        Route::get('/members', [StaffMemberController::class, 'index']);
        Route::get('/members/{member}', [StaffMemberController::class, 'show']);
    });

    Route::middleware('permission:portal.view')->group(function (): void {
        Route::get('/members/{member}/portal', [StaffMemberPortalController::class, 'show']);
    });

    Route::middleware('permission:portal.manage')->group(function (): void {
        Route::post('/members/{member}/portal/invitations', [StaffMemberPortalController::class, 'invite']);
    });

    Route::middleware('permission:renewals.view')->group(function (): void {
        Route::get('/members/{member}/renewal', [StaffMembershipRenewalController::class, 'show']);
    });

    Route::middleware('permission:renewals.manage')->group(function (): void {
        Route::post('/members/{member}/renewal/invoice', [StaffMembershipRenewalController::class, 'invoice']);
        Route::post('/members/{member}/renewals/{renewal}/payments', [StaffMembershipRenewalController::class, 'payment']);
    });

    Route::middleware('permission:organisations.view')->group(function (): void {
        Route::get('/organisations', [StaffOrganisationController::class, 'index']);
        Route::get('/organisations/{organisation}', [StaffOrganisationController::class, 'show']);
    });

    Route::middleware('permission:documents.view')->group(function (): void {
        Route::get('/documents/{document}/download', [StaffMembershipDocumentController::class, 'download']);
    });

    Route::middleware('permission:applications.view')->group(function (): void {
        Route::get('/applications', [StaffMembershipApplicationController::class, 'index']);
        Route::get('/applications/{reference}', [StaffMembershipApplicationController::class, 'show']);

        Route::middleware('permission:applications.review')->group(function (): void {
            Route::post('/applications/{reference}/review', [StaffMembershipApplicationController::class, 'startReview']);
            Route::post('/applications/{reference}/approve', [StaffMembershipApplicationController::class, 'approve']);
            Route::post('/applications/{reference}/reject', [StaffMembershipApplicationController::class, 'reject']);
        });
    });

    Route::middleware('permission:finance.view')->group(function (): void {
        Route::get('/applications/{reference}/billing', [StaffMembershipBillingController::class, 'show']);
        Route::get('/receipts/{receipt}', [StaffMembershipBillingController::class, 'receipt']);
    });

    Route::middleware('permission:finance.manage')->group(function (): void {
        Route::post('/applications/{reference}/invoice', [StaffMembershipBillingController::class, 'invoice']);
        Route::post('/applications/{reference}/payments', [StaffMembershipBillingController::class, 'payment']);
    });

    Route::post('/applications/{reference}/admit', [StaffMembershipBillingController::class, 'admit'])
        ->middleware('permission:applications.admit');
});
