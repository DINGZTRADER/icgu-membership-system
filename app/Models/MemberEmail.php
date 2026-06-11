<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class MemberEmail extends Model
{
    protected $table = 'member_emails';
    protected $guarded = ['id'];
    protected $casts = [
        'is_primary'          => 'boolean',
        'is_active'           => 'boolean',
        'verified_at'         => 'immutable_datetime',
        'verification_sent_at'=> 'immutable_datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true)->where('is_active', true);
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull('verified_at');
    }

    public function scopeUnverified(Builder $query): Builder
    {
        return $query->whereNull('verified_at')->where('is_active', true);
    }

    // ── Accessors ────────────────────────────────────────────────────
    public function getIsVerifiedAttribute(): bool
    {
        return $this->verified_at !== null;
    }
}
