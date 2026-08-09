<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MemberPortalInvitation extends Model
{
    protected $table = 'member_portal_invitations';
    protected $guarded = ['id', 'token_hash'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'expires_at' => 'immutable_datetime',
        'accepted_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    public function getIsUsableAttribute(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }
}
