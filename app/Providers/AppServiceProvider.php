<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::select('select 1');
        });

        Schedule::command('icgu:reconcile-mobile-money --limit=100')
            ->everyTwoMinutes()
            ->withoutOverlapping(5)
            ->onOneServer()
            ->when(fn (): bool => (bool) config('services.mtn_momo.enabled', false));
    }
}
