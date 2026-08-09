<?php

declare(strict_types=1);

use App\Http\Controllers\StaffMembershipApplicationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('staff/membership')
    ->middleware(['auth', 'permission:applications.view'])
    ->group(function (): void {
        Route::get('/applications', [StaffMembershipApplicationController::class, 'index']);
        Route::get('/applications/{reference}', [StaffMembershipApplicationController::class, 'show']);

        Route::middleware('permission:applications.review')->group(function (): void {
            Route::post('/applications/{reference}/review', [StaffMembershipApplicationController::class, 'startReview']);
            Route::post('/applications/{reference}/approve', [StaffMembershipApplicationController::class, 'approve']);
            Route::post('/applications/{reference}/reject', [StaffMembershipApplicationController::class, 'reject']);
        });
    });
