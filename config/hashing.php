<?php

declare(strict_types=1);

return [
    'driver' => 'bcrypt',
    'rehash_on_login' => true,
    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],
    'argon' => [
        'memory' => 65536,
        'threads' => 1,
        'time' => 4,
        'verify' => true,
    ],
];
