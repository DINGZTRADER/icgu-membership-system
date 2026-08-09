<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MembershipRenewal extends Model
{
    protected $table = 'membership_renewals';
    protected $guarded = ['id'];

    protected $casts = [
        'target_year' => 'integer',
        'planned_start_date' => 'immutable_date',
        'planned_end_date' => 'immutable_date',
        'renewal_fee' => 'decimal:4',
        'generated_at' => 'immutable_datetime',
        'settled_at' => 'immutable_datetime',
        'activated_at' => 'immutable_datetime',
    ];

    public function member(): BelongsTo { return $this->belongsTo(Member::class, 'member_id'); }
    public function sourcePeriod(): BelongsTo { return $this->belongsTo(MembershipPeriod::class, 'source_period_id'); }
    public function invoice(): BelongsTo { return $this->belongsTo(FinancialLedger::class, 'invoice_id'); }
    public function resultingPeriod(): BelongsTo { return $this->belongsTo(MembershipPeriod::class, 'resulting_period_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['invoiced', 'partial', 'settled']);
    }

    public function getBalanceDueAttribute(): string
    {
        return $this->invoice?->balance_due ?? number_format((float) $this->renewal_fee, 4, '.', '');
    }

    public function getIsFullySettledAttribute(): bool
    {
        return $this->invoice !== null && $this->invoice->is_fully_settled;
    }
}
