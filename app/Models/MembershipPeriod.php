<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class MembershipPeriod extends Model
{
    protected $table = 'membership_periods';
    protected $guarded = ['id'];
    protected $casts = [
        'start_date'   => 'immutable_date',
        'end_date'     => 'immutable_date',
        'target_year'  => 'integer',
        'is_backdated' => 'boolean',
        'is_future'    => 'boolean',
    ];

    // ── Relationships ────────────────────────────────────────────────
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(FinancialLedger::class, 'period_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────
    public function scopeCurrentYear(Builder $query): Builder
    {
        return $query->where('target_year', date('Y'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('start_date', '<=', now())
                     ->where('end_date', '>=', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('end_date', '<', now());
    }

    // ── Accessors ────────────────────────────────────────────────────
    public function getIsCurrentlyActiveAttribute(): bool
    {
        $now = now();
        return $this->start_date <= $now && $this->end_date >= $now;
    }

    public function getDaysRemainingAttribute(): int
    {
        return max(0, (int) now()->diffInDays($this->end_date, false));
    }
}
