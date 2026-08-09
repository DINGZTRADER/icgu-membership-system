<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

Artisan::command('icgu:health', function (): int {
    $this->info('ICGU application booted successfully.');

    return self::SUCCESS;
})->purpose('Verify that the ICGU Laravel application can boot.');
