<?php

declare(strict_types=1);

use App\Http\Controllers\MemberPortalAuthController;
use App\Http\Controllers\MemberPortalController;
use App\Http\Controllers\StaffMemberController;
use App\Http\Controllers\StaffMemberPortalController;
use App\Http\Controllers\StaffMembershipApplicationController;
use App\Http\Controllers\StaffMembershipBillingController;
use App\Http\Controllers\StaffMembershipDocumentController;
use App\Http\Controllers\StaffMembershipRenewalController;
use App\Http\Controllers\StaffOrganisationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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

Route::prefix('staff/membership')->middleware('auth')->group(function (): void {
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
