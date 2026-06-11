<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class FinancialLedger extends Model
{
    protected $table = 'financial_ledger';

    /**
     * Financial ledger records are IMMUTABLE.
     * Once created, they must not be updated or deleted.
     * Corrections are made via offsetting entries (refunds/waivers).
     */
    protected $guarded = ['id'];

    protected $casts = [
        'amount'          => 'decimal:4',
        'amount_settled'  => 'decimal:4',
        'due_date'        => 'immutable_datetime',
        'settled_at'      => 'immutable_datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(MembershipPeriod::class, 'period_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(LookupStatus::class, 'status_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function parentInvoice(): BelongsTo
    {
        return $this->belongsTo(FinancialLedger::class, 'parent_invoice_id');
    }

    /**
     * All payment and refund entries linked to this invoice.
     */
    public function settlements(): HasMany
    {
        return $this->hasMany(FinancialLedger::class, 'parent_invoice_id')
                    ->whereIn('type', ['payment', 'refund', 'waiver']);
    }

    // ── Query Scopes ─────────────────────────────────────────────────

    public function scopeInvoices(Builder $query): Builder
    {
        return $query->where('type', 'invoice');
    }

    public function scopePayments(Builder $query): Builder
    {
        return $query->where('type', 'payment');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('type', 'invoice')
                     ->whereNull('settled_at')
                     ->where('due_date', '<', now())
                     ->whereColumn('amount_settled', '<', 'amount');
    }

    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query->where('type', 'invoice')
                     ->whereNull('settled_at')
                     ->whereBetween('due_date', [now(), now()->addDays($days)])
                     ->whereColumn('amount_settled', '<', 'amount');
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->whereYear('created_at', $year);
    }

    public function scopeByFeeType(Builder $query, string $feeType): Builder
    {
        return $query->where('fee_type', $feeType);
    }

    // ── Computed Accessors ───────────────────────────────────────────

    public function getBalanceDueAttribute(): string
    {
        return number_format(
            (float) $this->amount - (float) $this->amount_settled,
            4,
            '.',
            ''
        );
    }

    public function getIsFullySettledAttribute(): bool
    {
        return (float) $this->amount_settled >= (float) $this->amount;
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->type === 'invoice'
            && !$this->is_fully_settled
            && $this->due_date !== null
            && $this->due_date->isPast();
    }
}
