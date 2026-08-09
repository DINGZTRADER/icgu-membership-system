<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FinancialLedger extends Model
{
    protected $table = 'financial_ledger';

    /** Financial records are append-only; corrections use offsetting entries. */
    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:4',
        'amount_settled' => 'decimal:4',
        'due_date' => 'immutable_datetime',
        'settled_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'meta' => 'array',
    ];

    public function member(): BelongsTo { return $this->belongsTo(Member::class, 'member_id'); }
    public function application(): BelongsTo { return $this->belongsTo(MembershipApplication::class, 'membership_application_id'); }
    public function renewal(): BelongsTo { return $this->belongsTo(MembershipRenewal::class, 'membership_renewal_id'); }
    public function period(): BelongsTo { return $this->belongsTo(MembershipPeriod::class, 'period_id'); }
    public function status(): BelongsTo { return $this->belongsTo(LookupStatus::class, 'status_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function parentInvoice(): BelongsTo { return $this->belongsTo(FinancialLedger::class, 'parent_invoice_id'); }
    public function receipt(): HasOne { return $this->hasOne(Receipt::class, 'payment_ledger_id'); }

    public function settlements(): HasMany
    {
        return $this->hasMany(FinancialLedger::class, 'parent_invoice_id')
            ->whereIn('type', ['payment', 'refund', 'waiver']);
    }

    public function scopeInvoices(Builder $query): Builder { return $query->where('type', 'invoice'); }
    public function scopePayments(Builder $query): Builder { return $query->where('type', 'payment'); }

    public function scopeOverdue(Builder $query): Builder
    {
        return $this->scopeOutstanding($query)
            ->where('financial_ledger.due_date', '<', now());
    }

    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $this->scopeOutstanding($query)
            ->whereBetween('financial_ledger.due_date', [now(), now()->addDays($days)]);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query
            ->where('financial_ledger.type', 'invoice')
            ->whereRaw(<<<'SQL'
financial_ledger.amount > COALESCE(
    (
        SELECT SUM(CASE WHEN settlement.type = 'refund' THEN -settlement.amount ELSE settlement.amount END)
        FROM financial_ledger AS settlement
        WHERE settlement.parent_invoice_id = financial_ledger.id
          AND settlement.type IN ('payment', 'waiver', 'refund')
    ),
    financial_ledger.amount_settled,
    0
)
SQL);
    }

    public function scopeForYear(Builder $query, int $year): Builder { return $query->whereYear('created_at', $year); }
    public function scopeByFeeType(Builder $query, string $feeType): Builder { return $query->where('fee_type', $feeType); }

    public function getBalanceDueAttribute(): string
    {
        if ($this->type !== 'invoice') {
            return '0.0000';
        }

        $entries = $this->relationLoaded('settlements') ? $this->settlements : $this->settlements()->get();
        $settled = (float) $this->amount_settled;

        if ($entries->isNotEmpty()) {
            $settled = 0.0;
            foreach ($entries as $entry) {
                $settled += $entry->type === 'refund' ? -(float) $entry->amount : (float) $entry->amount;
            }
        }

        return number_format(max(0, (float) $this->amount - $settled), 4, '.', '');
    }

    public function getIsFullySettledAttribute(): bool { return (float) $this->balance_due <= 0.0001; }

    public function getIsOverdueAttribute(): bool
    {
        return $this->type === 'invoice'
            && ! $this->is_fully_settled
            && $this->due_date !== null
            && $this->due_date->isPast();
    }
}
