<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class MembershipApplication extends Model
{
    protected $guarded = ['id', 'reference', 'access_token_hash'];
    protected $hidden = ['access_token_hash'];

    protected function casts(): array
    {
        return [
            'integrity_declaration_at' => 'immutable_datetime',
            'terms_accepted_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'review_started_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo { return $this->belongsTo(MembershipPlan::class, 'membership_plan_id'); }
    public function organisation(): BelongsTo { return $this->belongsTo(Organisation::class); }
    public function applicantUser(): BelongsTo { return $this->belongsTo(User::class, 'applicant_user_id'); }
    public function decisionMaker(): BelongsTo { return $this->belongsTo(User::class, 'decision_by'); }
    public function resultingMember(): BelongsTo { return $this->belongsTo(Member::class, 'resulting_member_id'); }
    public function representatives(): HasMany { return $this->hasMany(ApplicationRepresentative::class); }
    public function documents(): HasMany { return $this->hasMany(ApplicationDocument::class); }

    public function invoice(): HasOne
    {
        return $this->hasOne(FinancialLedger::class, 'membership_application_id')->where('type', 'invoice');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinancialLedger::class, 'membership_application_id')->where('type', 'payment')->orderByDesc('received_at');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class, 'membership_application_id')->orderByDesc('issued_at');
    }

    public function scopeSubmitted(Builder $query): Builder
    {
        return $query->whereIn('status', ['submitted', 'under_review', 'approved_pending_payment']);
    }

    public function tokenMatches(string $token): bool
    {
        return $token !== '' && hash_equals($this->access_token_hash, hash('sha256', $token));
    }
}
