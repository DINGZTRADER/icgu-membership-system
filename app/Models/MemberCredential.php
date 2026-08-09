<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MemberCredential extends Model
{
    protected $table = 'member_credentials';
    protected $guarded = ['id', 'verification_code'];

    protected $casts = [
        'valid_from' => 'immutable_date',
        'valid_until' => 'immutable_date',
        'issued_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
        'meta' => 'array',
    ];

    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
    public function issuedBy(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }

    public function getIsValidAttribute(): bool
    {
        return $this->revoked_at === null
            && $this->valid_from->lte(today())
            && $this->valid_until->gte(today());
    }
}
