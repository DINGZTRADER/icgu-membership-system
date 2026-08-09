<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

final class SystemActorService
{
    public function integrations(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'integrations@system.icgu.invalid'],
            [
                'name' => 'ICGU Integration Service',
                'password' => Str::random(64),
                'is_active' => false,
            ],
        );
    }
}
