<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class LookupStatus extends Model
{
    protected $table = 'lookup_statuses';
    protected $guarded = ['id'];
    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────────
    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'status_id');
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(FinancialLedger::class, 'status_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type)->where('is_active', true)->orderBy('sort_order');
    }

    public function scopeMembership(Builder $query): Builder
    {
        return $query->ofType('membership');
    }

    public function scopePayment(Builder $query): Builder
    {
        return $query->ofType('payment');
    }

    // ── Static Helpers ───────────────────────────────────────────────
    public static function findByCode(string $code): ?self
    {
        return static::where('code', $code)->first();
    }

    public static function idByCode(string $code): int
    {
        return (int) static::where('code', $code)->value('id');
    }
}
