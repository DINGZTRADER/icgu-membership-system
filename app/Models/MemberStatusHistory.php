<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberStatusHistory extends Model
{
    protected $table = 'member_status_history';
    protected $guarded = ['id'];
    protected $casts = [
        'effective_at' => 'immutable_datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(LookupStatus::class, 'from_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(LookupStatus::class, 'to_status_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_id');
    }
}
