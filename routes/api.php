<?php

declare(strict_types=1);

use App\Http\Controllers\PublicMembershipApplicationController;
use Illuminate\Support\Facades\Route;

Route::prefix('membership')->group(function (): void {
    Route::get('/plans', [PublicMembershipApplicationController::class, 'plans']);
    Route::post('/applications', [PublicMembershipApplicationController::class, 'store']);
    Route::get('/applications/{reference}', [PublicMembershipApplicationController::class, 'show']);
    Route::post('/applications/{reference}/documents', [PublicMembershipApplicationController::class, 'uploadDocument']);
    Route::post('/applications/{reference}/submit', [PublicMembershipApplicationController::class, 'submit']);
});
