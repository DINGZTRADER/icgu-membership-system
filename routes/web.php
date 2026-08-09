<?php

declare(strict_types=1);

use App\Http\Controllers\StaffMemberController;
use App\Http\Controllers\StaffMembershipApplicationController;
use App\Http\Controllers\StaffMembershipDocumentController;
use App\Http\Controllers\StaffOrganisationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('staff/membership')->middleware('auth')->group(function (): void {
    Route::middleware('permission:members.view')->group(function (): void {
        Route::get('/members', [StaffMemberController::class, 'index']);
        Route::get('/members/{member}', [StaffMemberController::class, 'show']);
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
});
