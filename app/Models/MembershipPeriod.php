<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MembershipPeriod extends Model
{
    protected $table = 'membership_periods';
    protected $guarded = ['id'];
    protected $casts = [
        'start_date' => 'immutable_date',
        'end_date' => 'immutable_date',
        'target_year' => 'integer',
        'is_backdated' => 'boolean',
        'is_future' => 'boolean',
    ];

    public function member(): BelongsTo { return $this->belongsTo(Member::class, 'member_id'); }
    public function ledgerEntries(): HasMany { return $this->hasMany(FinancialLedger::class, 'period_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeCurrentYear(Builder $query): Builder { return $query->where('target_year', date('Y')); }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereDate('start_date', '<=', today())->whereDate('end_date', '>=', today());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereDate('end_date', '<', today());
    }

    public function getIsCurrentlyActiveAttribute(): bool
    {
        $today = today();
        return $this->start_date->lte($today) && $this->end_date->gte($today);
    }

    public function getDaysRemainingAttribute(): int
    {
        return max(0, (int) today()->diffInDays($this->end_date, false));
    }
}
