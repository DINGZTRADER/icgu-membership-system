<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MembershipPlan extends Model
{
    protected $fillable = [
        'code', 'name', 'audience', 'first_year_fee', 'renewal_fee', 'currency',
        'requires_legal_entity', 'requirements', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'first_year_fee' => 'decimal:2',
            'renewal_fee' => 'decimal:2',
            'requires_legal_entity' => 'boolean',
            'requirements' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(MembershipApplication::class);
    }
}
