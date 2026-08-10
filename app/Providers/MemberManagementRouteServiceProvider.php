<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\StaffMemberManagementController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class MemberManagementRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'staff.mfa'])
            ->prefix('staff/member-admin')
            ->group(function (): void {
                Route::middleware('permission:members.manage')->group(function (): void {
                    Route::get('/new', [StaffMemberManagementController::class, 'create'])->name('staff.members.create');
                    Route::post('/', [StaffMemberManagementController::class, 'store'])->name('staff.members.store');
                    Route::get('/import', [StaffMemberManagementController::class, 'importForm'])->name('staff.members.import');
                    Route::post('/import', [StaffMemberManagementController::class, 'import'])->name('staff.members.import.submit');
                    Route::get('/import/template', [StaffMemberManagementController::class, 'template'])->name('staff.members.import.template');
                    Route::patch('/{member}/profile-assets', [StaffMemberManagementController::class, 'updateCareerAssets'])->name('staff.members.profile-assets');
                });

                Route::get('/{member}/photo', [StaffMemberManagementController::class, 'photo'])
                    ->middleware('permission:members.view')
                    ->name('staff.members.photo');

                Route::get('/{member}/cv', [StaffMemberManagementController::class, 'cv'])
                    ->middleware('permission:documents.view')
                    ->name('staff.members.cv');
            });
    }
}
