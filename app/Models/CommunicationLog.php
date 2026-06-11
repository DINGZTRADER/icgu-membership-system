<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class CommunicationLog extends Model
{
    protected $table = 'communication_logs';
    protected $guarded = ['id'];
    protected $casts = [
        'sent_at'   => 'immutable_datetime',
        'opened_at' => 'immutable_datetime',
        'meta'      => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────────
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'sent_by');
    }

    // ── Scopes ───────────────────────────────────────────────────────
    public function scopeBySequence(Builder $query, string $sequence): Builder
    {
        return $query->where('sequence', $sequence);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', ['failed', 'bounced']);
    }

    public function scopeOpened(Builder $query): Builder
    {
        return $query->where('status', 'opened');
    }

    // ── Accessors ────────────────────────────────────────────────────
    public function getWasOpenedAttribute(): bool
    {
        return $this->opened_at !== null;
    }
}
