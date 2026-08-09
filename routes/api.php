<?php

declare(strict_types=1);

use App\Http\Controllers\PublicMembershipApplicationController;
use App\Http\Controllers\PublicMembershipBillingController;
use App\Http\Controllers\PublicMembershipVerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('membership')->group(function (): void {
    Route::get('/plans', [PublicMembershipApplicationController::class, 'plans'])
        ->middleware('throttle:60,1');

    Route::get('/verify/{verificationCode}', [PublicMembershipVerificationController::class, 'show'])
        ->middleware('throttle:120,1');

    Route::post('/applications', [PublicMembershipApplicationController::class, 'store'])
        ->middleware('throttle:10,1');

    Route::get('/applications/{reference}', [PublicMembershipApplicationController::class, 'show'])
        ->middleware('throttle:60,1');

    Route::get('/applications/{reference}/billing', [PublicMembershipBillingController::class, 'show'])
        ->middleware('throttle:60,1');

    Route::post('/applications/{reference}/documents', [PublicMembershipApplicationController::class, 'uploadDocument'])
        ->middleware('throttle:30,1');

    Route::post('/applications/{reference}/submit', [PublicMembershipApplicationController::class, 'submit'])
        ->middleware('throttle:10,1');
});
