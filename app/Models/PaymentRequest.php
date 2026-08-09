<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PaymentRequest extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'provider_payload' => 'array',
            'requested_at' => 'immutable_datetime',
            'callback_received_at' => 'immutable_datetime',
            'last_polled_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function invoice(): BelongsTo { return $this->belongsTo(FinancialLedger::class, 'invoice_id'); }
    public function application(): BelongsTo { return $this->belongsTo(MembershipApplication::class, 'membership_application_id'); }
    public function renewal(): BelongsTo { return $this->belongsTo(MembershipRenewal::class, 'membership_renewal_id'); }
    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function webhookEvents(): HasMany { return $this->hasMany(PaymentWebhookEvent::class); }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', ['created', 'pending']);
    }

    public function getIsTerminalAttribute(): bool
    {
        return in_array($this->status, ['successful', 'failed', 'expired', 'cancelled'], true);
    }
}
