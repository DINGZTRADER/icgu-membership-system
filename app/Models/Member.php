<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Member extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'members';
    protected $guarded = ['id', 'registration_number'];

    protected $casts = [
        'registration_date' => 'immutable_date',
        'is_archived' => 'boolean',
    ];

    public function status(): BelongsTo { return $this->belongsTo(LookupStatus::class, 'status_id'); }
    public function membershipPlan(): BelongsTo { return $this->belongsTo(MembershipPlan::class); }
    public function sourceApplication(): BelongsTo { return $this->belongsTo(MembershipApplication::class, 'source_application_id'); }
    public function organisation(): BelongsTo { return $this->belongsTo(Organisation::class); }

    public function emails(): HasMany { return $this->hasMany(MemberEmail::class, 'member_id')->orderByDesc('is_primary'); }
    public function primaryEmail(): HasOne { return $this->hasOne(MemberEmail::class, 'member_id')->where('is_primary', true)->where('is_active', true); }
    public function periods(): HasMany { return $this->hasMany(MembershipPeriod::class, 'member_id')->orderByDesc('target_year'); }

    public function currentPeriod(): HasOne
    {
        return $this->hasOne(MembershipPeriod::class, 'member_id')
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->latestOfMany('target_year');
    }

    public function ledgerEntries(): HasMany { return $this->hasMany(FinancialLedger::class, 'member_id')->orderByDesc('created_at'); }
    public function invoices(): HasMany { return $this->hasMany(FinancialLedger::class, 'member_id')->where('type', 'invoice')->orderByDesc('created_at'); }
    public function payments(): HasMany { return $this->hasMany(FinancialLedger::class, 'member_id')->where('type', 'payment')->orderByDesc('created_at'); }
    public function communicationLogs(): HasMany { return $this->hasMany(CommunicationLog::class, 'member_id')->orderByDesc('sent_at'); }
    public function statusHistory(): HasMany { return $this->hasMany(MemberStatusHistory::class, 'member_id')->orderByDesc('effective_at'); }

    public function scopeActive(Builder $query): Builder { return $query->whereHas('status', fn (Builder $q) => $q->where('code', 'ACTIVE')); }
    public function scopeExpired(Builder $query): Builder { return $query->whereHas('status', fn (Builder $q) => $q->where('code', 'EXPIRED')); }
    public function scopePending(Builder $query): Builder { return $query->whereHas('status', fn (Builder $q) => $q->where('code', 'PENDING')); }
    public function scopeIndividuals(Builder $query): Builder { return $query->where('type', 'individual'); }
    public function scopeCorporates(Builder $query): Builder { return $query->where('type', 'corporate'); }
    public function scopeNotArchived(Builder $query): Builder { return $query->where('is_archived', false); }
    public function scopeRegisteredInYear(Builder $query, int $year): Builder { return $query->whereYear('registration_date', $year); }

    public function getIsIndividualAttribute(): bool { return $this->type === 'individual'; }
    public function getIsCorporateAttribute(): bool { return $this->type === 'corporate'; }

    public function getDisplayNameAttribute(): string
    {
        if ($this->type === 'corporate') {
            return $this->organisation?->legal_name ?? $this->company_name ?? 'Unknown Organisation';
        }

        return trim(($this->title ? $this->title.' ' : '').($this->first_name ?? '').' '.($this->last_name ?? ''));
    }

    public function getOutstandingBalanceAttribute(): string
    {
        $invoiced = $this->ledgerEntries->where('type', 'invoice')->sum('amount');
        $settled = $this->ledgerEntries->where('type', 'invoice')->sum('amount_settled');
        return number_format($invoiced - $settled, 4, '.', '');
    }
}
